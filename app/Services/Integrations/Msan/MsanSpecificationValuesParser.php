<?php

namespace App\Services\Integrations\Msan;

use RuntimeException;
use XMLReader;

class MsanSpecificationValuesParser
{
    private const MAX_BYTES = 512 * 1024;

    private const MAX_VALUES = 128;

    private const MAX_VALUE_BYTES = 16 * 1024;

    /** @return list<string> */
    public function parse(mixed $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }
        if (strlen($raw) > self::MAX_BYTES) {
            throw new RuntimeException('M SAN vrijednosti specifikacije prelaze dopuštenu veličinu.');
        }

        if (! str_starts_with(ltrim($raw), '<')) {
            return $this->normalizeValues([$raw]);
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $raw) === 1) {
            throw new RuntimeException('M SAN vrijednosti specifikacije sadrže nedopuštene XML deklaracije.');
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;
        $values = [];

        try {
            if (! @$reader->XML($raw, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('M SAN vrijednosti specifikacije nisu valjan XML.');
            }
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'Value') {
                    continue;
                }
                if (count($values) >= self::MAX_VALUES) {
                    throw new RuntimeException('M SAN specifikacija sadrži previše vrijednosti.');
                }

                $value = trim($reader->readString());
                if (strlen($value) > self::MAX_VALUE_BYTES) {
                    throw new RuntimeException('Pojedina M SAN vrijednost specifikacije je predugačka.');
                }
                $values[] = $value;
            }

            foreach (libxml_get_errors() as $error) {
                if ($error->level >= LIBXML_ERR_ERROR) {
                    throw new RuntimeException('M SAN vrijednosti specifikacije nisu valjan XML.');
                }
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $this->normalizeValues($values);
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private function normalizeValues(array $values): array
    {
        return collect($values)
            ->map(static fn (string $value): string => trim(preg_replace('/\s+/u', ' ', $value) ?? $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->take(self::MAX_VALUES)
            ->values()
            ->all();
    }
}
