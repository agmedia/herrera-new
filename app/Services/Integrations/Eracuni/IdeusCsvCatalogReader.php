<?php

namespace App\Services\Integrations\Eracuni;

use RuntimeException;

/** The legacy Generic IDEUS file is a fixed 46-column CSV, not the other 33-column IDEUS export. */
class IdeusCsvCatalogReader
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_ROWS = 10000;

    public function read(string $path): array
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) || ! is_file($path) || ! is_readable($path)
            || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            throw new RuntimeException('Odaberite čitljivu CSV datoteku.');
        }
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw new RuntimeException('CSV mora biti neprazan i manji od 10 MB.');
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('CSV datoteku nije moguće pročitati.');
        }
        try {
            $delimiter = ',';
            $score = 0;
            foreach ([',', ';', "\t"] as $candidate) {
                rewind($handle);
                for ($i = 0; $i < 3 && ($record = fgetcsv($handle, 0, $candidate, '"', '')) !== false; $i++) {
                    if (count($record) > $score) {
                        $score = count($record);
                        $delimiter = $candidate;
                    }
                }
            }
            if ($score < 46) {
                throw new RuntimeException('Potreban je stari Generic IDEUS CSV s najmanje 46 stupaca.');
            }
            rewind($handle);
            $rows = [];
            $line = 0;
            while (($values = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if ($line > self::MAX_ROWS + 2) {
                    throw new RuntimeException('CSV sadrži više od 10.000 redaka artikala.');
                }
                if ($values === [null] || count(array_filter($values, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                // The original format starts with up to two title/header rows.
                if (count($values) < 46 && $line <= 2) {
                    continue;
                }
                if (count($values) < 46 || count($values) > 256) {
                    throw new RuntimeException('CSV redak nema očekivani raspored stupaca.');
                }
                $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $values[0]);
                foreach ($values as $value) {
                    if (! mb_check_encoding((string) $value, 'UTF-8') || str_contains((string) $value, "\0")) {
                        throw new RuntimeException('CSV mora sadržavati valjan UTF-8 tekst.');
                    }
                }
                $rows[] = ['row_number' => $line, 'values' => $values];
            }
            if ($rows === []) {
                throw new RuntimeException('CSV nema redaka artikala.');
            }

            return ['rows' => $rows, 'file_name' => basename($path), 'sha256' => hash_file('sha256', $path), 'delimiter' => $delimiter];
        } finally {
            fclose($handle);
        }
    }
}
