<?php

namespace App\Services\Integrations\Msan;

use Generator;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use XMLReader;

class MsanXmlStreamReader
{
    private const MAX_FIELD_BYTES = 8 * 1024 * 1024;

    private const MAX_ROW_BYTES = 16 * 1024 * 1024;

    private const MAX_FIELDS_PER_ROW = 5000;

    /**
     * Streams business rows from plain NewDataSet, SOAP or diffgram XML.
     * Inline XSD declarations are naturally ignored because they describe
     * an xs:element named Table rather than containing a Table element.
     *
     * @return iterable<int, array<string, string>>
     */
    public function rows(string $path): iterable
    {
        return $this->readRows($this->absolutePath($path));
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    private function readRows(string $path): Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('M SAN XML datoteka nije čitljiva.');
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;

        try {
            if (! @$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('M SAN XML datoteku nije moguće otvoriti.');
            }

            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Table') {
                    continue;
                }

                $tableDepth = $reader->depth;
                $row = [];
                $rowBytes = 0;
                if ($reader->isEmptyElement) {
                    yield $row;

                    continue;
                }

                while ($reader->read()) {
                    if ($reader->nodeType === XMLReader::END_ELEMENT
                        && $reader->depth === $tableDepth
                        && $reader->localName === 'Table'
                    ) {
                        break;
                    }

                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->depth !== $tableDepth + 1) {
                        continue;
                    }

                    $field = $reader->localName;
                    $value = $reader->isEmptyElement
                        ? ''
                        : $this->readFieldValue($reader, $reader->depth);
                    $fieldBytes = strlen($value);
                    $rowBytes += $fieldBytes;
                    if ($fieldBytes > self::MAX_FIELD_BYTES
                        || $rowBytes > self::MAX_ROW_BYTES
                        || count($row) >= self::MAX_FIELDS_PER_ROW
                    ) {
                        throw new RuntimeException('M SAN XML redak prelazi dopuštenu veličinu.');
                    }

                    $row[$field] = $value;
                }

                yield $row;
            }

            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    throw new RuntimeException('M SAN odgovor nije valjan XML dokument.');
                }
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    private function readFieldValue(XMLReader $reader, int $fieldDepth): string
    {
        $value = '';
        $bytes = 0;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $fieldDepth) {
                break;
            }

            if (! in_array($reader->nodeType, [
                XMLReader::TEXT,
                XMLReader::CDATA,
                XMLReader::WHITESPACE,
                XMLReader::SIGNIFICANT_WHITESPACE,
            ], true)) {
                continue;
            }

            $chunk = $reader->value;
            $bytes += strlen($chunk);
            if ($bytes > self::MAX_FIELD_BYTES) {
                throw new RuntimeException('M SAN XML polje prelazi dopuštenu veličinu.');
            }

            $value .= $chunk;
        }

        return $value;
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Putanja M SAN XML datoteke nije valjana.');
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
        ) {
            return $path;
        }

        return Storage::disk('local')->path(ltrim($path, '/\\'));
    }
}
