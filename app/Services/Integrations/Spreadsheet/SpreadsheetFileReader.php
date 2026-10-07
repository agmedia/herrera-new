<?php

namespace App\Services\Integrations\Spreadsheet;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use RuntimeException;
use Throwable;
use ZipArchive;

class SpreadsheetFileReader
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_ROWS = 10000;

    public function read(string $path, array $columns, int $firstRow): array
    {
        if (! str_starts_with($path, '/') || ! is_file($path) || ! is_readable($path)
            || filesize($path) > self::MAX_BYTES || filesize($path) === 0) {
            throw new RuntimeException('Datoteka nije čitljiva ili prelazi dopuštenih 10 MB.');
        }
        $spreadsheet = null;
        try {
            $type = IOFactory::identify($path, [IOFactory::READER_XLSX, IOFactory::READER_XLS, IOFactory::READER_CSV]);
            if ($type === IOFactory::READER_XLSX) {
                $this->inspectArchive($path);
            }
            $reader = IOFactory::createReader($type);
            // Keep source format masks for identifiers such as 0000123; do not
            // calculate formulas, load charts, or execute workbook content.
            $reader->setReadEmptyCells(false);
            if ($type === IOFactory::READER_CSV) {
                $reader->setValueBinder(new StringValueBinder);
                $reader->setInputEncoding('UTF-8');
            }
            $filter = new class($columns, $firstRow) implements IReadFilter
            {
                public array $overflowSheets = [];

                public function __construct(private array $columns, private int $firstRow) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    $selected = in_array(Coordinate::columnIndexFromString($columnAddress), $this->columns, true);
                    if ($selected && $row >= $this->firstRow + SpreadsheetFileReader::MAX_ROWS) {
                        $this->overflowSheets[$worksheetName] = true;
                    }

                    return $selected && $row >= $this->firstRow && $row < $this->firstRow + SpreadsheetFileReader::MAX_ROWS;
                }
            };
            $reader->setReadFilter($filter);
            $spreadsheet = $reader->load($path);
            if ($spreadsheet->hasMacros()) {
                throw new RuntimeException('Datoteka sadrži makronaredbe koje ovaj uvoz ne podržava.');
            }
            $sheet = $spreadsheet->getActiveSheet();
            if (isset($filter->overflowSheets[$sheet->getTitle()]) || isset($filter->overflowSheets[''])) {
                throw new RuntimeException('Datoteka prelazi dopuštenih 10.000 redaka.');
            }
            $highest = $sheet->getHighestDataRow();
            if ($highest - $firstRow + 1 > self::MAX_ROWS) {
                throw new RuntimeException('Datoteka prelazi dopuštenih 10.000 redaka.');
            }
            $rows = [];
            for ($row = $firstRow; $row <= $highest; $row++) {
                $values = [];
                $errors = [];
                $masks = [];
                foreach ($columns as $column) {
                    $cell = $sheet->getCell([$column, $row]);
                    $value = $cell->getValue();
                    if ($cell->getDataType() === DataType::TYPE_FORMULA || (is_string($value) && str_starts_with($value, '='))) {
                        if (! is_string($value) || preg_match('/\[[^\]]+\]|\b(?:WEBSERVICE|DDE|RTD|HYPERLINK|FILTERXML|INDIRECT)\s*\(/i', $value)) {
                            $errors[$column] = 'Formula s vanjskim vezama nije podržana.';
                            $value = null;
                        } else {
                            $value = $cell->getOldCalculatedValue();
                            if ($value === null) {
                                $errors[$column] = 'Formula nema spremljeni rezultat. Spremite izračunatu datoteku u Excelu.';
                            }
                        }
                    }
                    if ($cell->getDataType() === DataType::TYPE_ERROR) {
                        $errors[$column] = 'Ćelija sadrži pogrešku.';
                    }
                    $values[$column] = $value;
                    $masks[$column] = $cell->getStyle()->getNumberFormat()->getFormatCode();
                }
                if (count(array_filter($values, fn ($value) => $value !== null && $value !== '')) === 0 && $errors === []) {
                    continue;
                }
                $rows[] = ['row_number' => $row, 'values' => $values, 'errors' => $errors, 'masks' => $masks];
            }
            if ($rows === []) {
                throw new RuntimeException('Od odabranog početnog retka nema podataka za uvoz.');
            }

            return ['rows' => $rows, 'sheet_name' => $sheet->getTitle(), 'source_type' => strtolower($type)];
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RuntimeException('Datoteku nije moguće pročitati kao podržani XLSX, XLS ili CSV.');
        } finally {
            $spreadsheet?->disconnectWorksheets();
        }
    }

    private function inspectArchive(string $path): void
    {
        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            throw new RuntimeException('XLSX datoteka nije valjana.');
        }
        try {
            $bytes = 0;
            if ($archive->numFiles > 5000) {
                throw new RuntimeException('XLSX datoteka prelazi dopuštenu veličinu sadržaja.');
            }
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $bytes += (int) ($entry['size'] ?? 0);
                if ($bytes > 50 * 1024 * 1024) {
                    throw new RuntimeException('XLSX datoteka prelazi dopuštenu veličinu sadržaja.');
                }
                if (preg_match('~(?:^|/)(?:vbaProject\.bin|externalLinks/|connections\.xml)~i', (string) ($entry['name'] ?? ''))) {
                    throw new RuntimeException('Datoteka sadrži vanjske veze ili makronaredbe koje ovaj uvoz ne podržava.');
                }
                if (str_ends_with(strtolower((string) ($entry['name'] ?? '')), '.rels')
                    && preg_match('/\bTargetMode\s*=\s*[\'"]External[\'"]/i', (string) $archive->getFromIndex($index))) {
                    throw new RuntimeException('Datoteka sadrži vanjske veze ili makronaredbe koje ovaj uvoz ne podržava.');
                }
            }
        } finally {
            $archive->close();
        }
    }
}
