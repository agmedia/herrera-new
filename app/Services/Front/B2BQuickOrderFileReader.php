<?php

namespace App\Services\Front;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;
use XMLReader;
use ZipArchive;

class B2BQuickOrderFileReader
{
    private const MAX_XML_NODES = 20000;

    private const MAX_XML_ATTRIBUTES = 20000;

    private const MAX_XML_TEXT_BYTES = 2 * 1024 * 1024;

    private const MAX_SHARED_STRINGS = 1000;

    private const MAX_STYLE_RESOURCES = 1024;

    public function read(UploadedFile $file): array
    {
        try {
            $rows = strtolower($file->getClientOriginalExtension()) === 'xlsx'
                ? $this->xlsx($file->getRealPath()) : $this->csv($file->getRealPath());
            if ($rows !== [] && $this->isHeader($rows[0]['parts'])) {
                array_shift($rows);
            }
            if ($rows === [] || count($rows) > 100) {
                $this->invalid('Datoteka mora sadržavati od 1 do 100 artikala.');
            }

            return $rows;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->invalid('Datoteku nije moguće pročitati. Odaberite XLSX ili CSV s dva stupca: šifra/EAN i količina.');
        }
    }

    private function csv(string $path): array
    {
        $stream = fopen($path, 'r');
        try {
            $first = fgets($stream);
            if (! is_string($first) || ! mb_check_encoding($first, 'UTF-8')) {
                $this->invalid('CSV spremite u UTF-8 formatu.');
            }
            $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
            $delimiter = ';';
            $mostColumns = 1;
            foreach ([';', ',', "\t"] as $candidate) {
                $columns = count(str_getcsv($first, $candidate, '"', ''));
                if ($columns > $mostColumns) {
                    $delimiter = $candidate;
                    $mostColumns = $columns;
                }
            }
            rewind($stream);
            $rows = [];
            $rowNumber = 0;
            while (($parts = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                $rowNumber++;
                if ($rowNumber === 1) {
                    $parts[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($parts[0] ?? ''));
                }
                if (count(array_filter($parts, fn ($value) => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                foreach ($parts as $value) {
                    if (! mb_check_encoding((string) $value, 'UTF-8')) {
                        $this->invalid('CSV spremite u UTF-8 formatu.');
                    }
                }
                $rows[] = ['line' => $rowNumber, 'parts' => $parts];
                if (count($rows) > 101) {
                    $this->invalid('Unesite najviše 100 artikala odjednom.');
                }
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function xlsx(string $path): array
    {
        $this->inspectArchive($path);
        $reader = new Xlsx;
        $reader->setReadEmptyCells(false);
        $sheets = $reader->listWorksheetNames($path);
        if ($sheets === []) {
            $this->invalid('XLSX datoteka nema radni list.');
        }
        $reader->setLoadSheetsOnly($sheets[0]);
        $filter = new class implements IReadFilter
        {
            public bool $overflow = false;

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                if ($row > 102 || ! in_array($columnAddress, ['A', 'B'], true)) {
                    $this->overflow = true;

                    return false;
                }

                return true;
            }
        };
        $reader->setReadFilter($filter);
        $spreadsheet = $reader->load($path);
        try {
            if ($filter->overflow || $spreadsheet->hasMacros()) {
                $this->invalid('Koristite samo prva dva stupca i najviše 100 artikala, uz neobavezni redak zaglavlja.');
            }
            $sheet = $spreadsheet->getSheet(0);
            $rows = [];
            for ($row = 1; $row <= $sheet->getHighestDataRow(); $row++) {
                $parts = [];
                foreach (['A', 'B'] as $column) {
                    $cell = $sheet->getCell($column.$row);
                    $value = $cell->getValue();
                    if (in_array($cell->getDataType(), [DataType::TYPE_FORMULA, DataType::TYPE_ERROR], true)
                        || is_bool($value) || is_array($value) || is_object($value)) {
                        $this->invalid('Redak '.$row.': unesite vrijednosti umjesto formula ili pogrešaka ćelije.');
                    }
                    if ($column === 'A' && (is_int($value) || is_float($value)) && floor($value) === (float) $value) {
                        if (abs($value) >= 1000000000000000) {
                            $this->invalid('Redak '.$row.': duge šifre spremite kao tekst kako Excel ne bi promijenio njihove znamenke.');
                        }
                        $value = sprintf('%.0f', $value);
                        $mask = $cell->getStyle()->getNumberFormat()->getFormatCode();
                        if (preg_match('/^0{2,191}$/D', $mask)) {
                            $value = str_pad($value, strlen($mask), '0', STR_PAD_LEFT);
                        }
                    }
                    $parts[] = trim((string) $value);
                }
                if ($parts !== ['', '']) {
                    $rows[] = ['line' => $row, 'parts' => $parts];
                }
            }

            return $rows;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function inspectArchive(string $path): void
    {
        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            $this->invalid('XLSX datoteka nije valjana.');
        }
        try {
            $bytes = 0;
            $xmlBudget = ['nodes' => 0, 'attributes' => 0, 'text_bytes' => 0, 'shared_strings' => 0, 'styles' => 0];
            if ($archive->numFiles > 1000) {
                $this->invalid('XLSX datoteka ima previše sadržaja. Koristite jednostavan popis artikala.');
            }
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $bytes += (int) ($entry['size'] ?? 0);
                if ($bytes > 10 * 1024 * 1024) {
                    $this->invalid('Raspakirani XLSX sadržaj prelazi 10 MB.');
                }
                $name = (string) ($entry['name'] ?? '');
                if (preg_match('~(?:^|/)(?:vbaProject\.bin|externalLinks/|connections\.xml)~i', $name)
                    || (str_ends_with(strtolower($name), '.rels')
                        && preg_match('/\bTargetMode\s*=\s*[\'"]External[\'"]/i', (string) $archive->getFromIndex($index)))) {
                    $this->invalid('Uklonite vanjske veze i makronaredbe iz datoteke.');
                }
                if (preg_match('/\.(?:xml|rels)$/i', $name)) {
                    $contents = $archive->getFromIndex($index);
                    if (! is_string($contents)) {
                        $this->invalid('XLSX datoteka nije valjana.');
                    }
                    $this->inspectXml($contents, $xmlBudget);
                }
            }
        } finally {
            $archive->close();
        }
    }

    /** Read filters do not stop PhpSpreadsheet loading unused strings or styles. */
    private function inspectXml(string $contents, array &$budget): void
    {
        $xml = new XMLReader;
        $previousErrors = libxml_use_internal_errors(true);
        try {
            if (! $xml->XML($contents, null, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->invalid('XLSX datoteka nije valjana.');
            }
            $xml->setParserProperty(XMLReader::LOADDTD, false);
            $xml->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            $xml->setParserProperty(XMLReader::VALIDATE, false);
            while ($xml->read()) {
                if (in_array($xml->nodeType, [XMLReader::DOC_TYPE, XMLReader::ENTITY, XMLReader::ENTITY_REF], true)) {
                    $this->invalid('XLSX datoteka ne smije sadržavati DTD ili XML entitete.');
                }
                $budget['nodes']++;
                if ($xml->depth > 64 || $budget['nodes'] > self::MAX_XML_NODES) {
                    $this->complexWorkbook();
                }
                if ($xml->nodeType === XMLReader::ELEMENT) {
                    if ($xml->localName === 'si' && ++$budget['shared_strings'] > self::MAX_SHARED_STRINGS) {
                        $this->complexWorkbook();
                    }
                    if (in_array($xml->localName, ['xf', 'font', 'fill', 'border', 'dxf', 'cellStyle', 'numFmt'], true)
                        && ++$budget['styles'] > self::MAX_STYLE_RESOURCES) {
                        $this->complexWorkbook();
                    }
                    while ($xml->moveToNextAttribute()) {
                        if ($xml->localName === 'TargetMode' && strcasecmp($xml->value, 'External') === 0) {
                            $this->invalid('Uklonite vanjske veze i makronaredbe iz datoteke.');
                        }
                        $budget['attributes']++;
                        $budget['text_bytes'] += strlen($xml->value);
                        if ($budget['attributes'] > self::MAX_XML_ATTRIBUTES || $budget['text_bytes'] > self::MAX_XML_TEXT_BYTES) {
                            $this->complexWorkbook();
                        }
                    }
                    $xml->moveToElement();
                } else {
                    $budget['text_bytes'] += strlen($xml->value);
                    if ($budget['text_bytes'] > self::MAX_XML_TEXT_BYTES) {
                        $this->complexWorkbook();
                    }
                }
            }
            if (libxml_get_errors() !== []) {
                $this->invalid('XLSX datoteka nije valjana.');
            }
        } finally {
            $xml->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function complexWorkbook(): never
    {
        $this->invalid('XLSX datoteka sadrži previše teksta, stilova ili neiskorištenih podataka. Kopirajte najviše 100 artikala u novu jednostavnu datoteku s dva stupca.');
    }

    private function isHeader(array $parts): bool
    {
        $identifier = mb_strtolower(preg_replace('/\s+/', '', trim((string) ($parts[0] ?? ''))));
        $quantity = mb_strtolower(trim((string) ($parts[1] ?? '')));

        return count($parts) === 2
            && in_array($identifier, ['sifra', 'šifra', 'sku', 'ean', 'barkod', 'barcode', 'identifier', 'šifra/ean', 'sifra/ean', 'sku/ean'], true)
            && in_array($quantity, ['kolicina', 'količina', 'quantity', 'qty'], true);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['order_file' => __($message)]);
    }
}
