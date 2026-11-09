<?php

namespace App\Services\Analytics;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;
use XMLWriter;
use ZipArchive;

/** Write real XLSX rows to disk without retaining a workbook in PHP memory. */
class SalesOpportunityExport
{
    public function create(Builder $products): string
    {
        $sheetPath = tempnam(sys_get_temp_dir(), 'herrera-opportunity-sheet-');
        $filePath = tempnam(sys_get_temp_dir(), 'herrera-opportunities-');
        if ($sheetPath === false || $filePath === false) {
            if ($sheetPath !== false) {
                @unlink($sheetPath);
            }
            if ($filePath !== false) {
                @unlink($filePath);
            }
            throw new RuntimeException('Nije moguće pripremiti Excel datoteku.');
        }
        chmod($sheetPath, 0600);
        chmod($filePath, 0600);
        try {
            $xml = new XMLWriter;
            if (! $xml->openUri($sheetPath)) {
                throw new RuntimeException('Nije moguće pripremiti Excel podatke.');
            }
            $xml->startDocument('1.0', 'UTF-8');
            $xml->startElement('worksheet');
            $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $xml->startElement('sheetViews');
            $xml->startElement('sheetView');
            $xml->writeAttribute('workbookViewId', '0');
            $xml->startElement('pane');
            foreach (['ySplit' => '1', 'topLeftCell' => 'A2', 'activePane' => 'bottomLeft', 'state' => 'frozen'] as $key => $value) {
                $xml->writeAttribute($key, $value);
            }
            $xml->endElement();
            $xml->endElement();
            $xml->endElement();
            $xml->startElement('cols');
            foreach ([8, 22, 24, 24, 60, 45, 22, 26] as $index => $width) {
                $xml->startElement('col');
                foreach (['min' => $index + 1, 'max' => $index + 1, 'width' => $width, 'customWidth' => 1] as $key => $value) {
                    $xml->writeAttribute($key, (string) $value);
                }
                $xml->endElement();
            }
            $xml->endElement();
            $xml->startElement('sheetData');
            $this->row($xml, 1, ['Rang', 'Šifra', 'SKU', 'EAN', 'Naziv artikla', 'Kategorije', 'Poznati kupci · 90 dana', 'Neto količina · 90 dana'], true);
            $row = 1;
            foreach ($products->lazy(100) as $product) {
                $translation = $product->translations->firstWhere('locale', 'hr') ?: $product->translations->first();
                $categories = $product->categories->map(fn ($category) => ($category->translations->firstWhere('locale', 'hr') ?: $category->translations->first())?->name ?: $category->code)->implode(', ');
                $row++;
                if ($row > 1048576) {
                    throw new RuntimeException('Broj artikala prelazi kapacitet jednog Excel lista. Suzite izbor kategorijom.');
                }
                $this->row($xml, $row, [$row - 1, (string) $product->code, (string) $product->sku, (string) $product->barcode,
                    (string) ($translation?->name ?: $product->code), $categories, (int) $product->opportunity_buyers, (float) $product->opportunity_quantity]);
            }
            $xml->endElement();
            $xml->startElement('autoFilter');
            $xml->writeAttribute('ref', 'A1:H'.$row);
            $xml->endElement();
            $xml->endElement();
            $xml->endDocument();
            $xml->flush();
            unset($xml);
            $zip = new ZipArchive;
            if ($zip->open($filePath, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Nije moguće pripremiti Excel datoteku.');
            }
            try {
                foreach ($this->parts() as $name => $content) {
                    if (! $zip->addFromString($name, $content)) {
                        throw new RuntimeException('Nije moguće pripremiti Excel datoteku.');
                    }
                }
                if (! $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml') || ! $zip->close()) {
                    throw new RuntimeException('Nije moguće dovršiti Excel datoteku.');
                }
            } catch (Throwable $exception) {
                $zip->close();
                throw $exception;
            }

            return $filePath;
        } catch (Throwable $exception) {
            @unlink($filePath);
            throw $exception;
        } finally {
            @unlink($sheetPath);
        }
    }

    private function row(XMLWriter $xml, int $row, array $values, bool $header = false): void
    {
        $xml->startElement('row');
        $xml->writeAttribute('r', (string) $row);
        foreach ($values as $index => $value) {
            $xml->startElement('c');
            $xml->writeAttribute('r', chr(65 + $index).$row);
            $xml->writeAttribute('s', $header ? '1' : ($index === 7 ? '2' : '0'));
            if (is_int($value) || is_float($value)) {
                $xml->writeElement('v', (string) $value);
            } else {
                $xml->writeAttribute('t', 'inlineStr');
                $xml->startElement('is');
                $xml->startElement('t');
                $xml->writeAttribute('xml:space', 'preserve');
                $xml->text(preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '');
                $xml->endElement();
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
    }

    private function parts(): array
    {
        $prefix = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

        return [
            '[Content_Types].xml' => $prefix.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => $prefix.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $prefix.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Prodajne prilike" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => $prefix.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => $prefix.'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0E7490"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        ];
    }
}
