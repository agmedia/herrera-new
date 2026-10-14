<?php

namespace App\Support;

final class SupplierCatalogUploadLimits
{
    public static function pdfKilobytes(): int
    {
        $limits = [(int) config('supplier_catalogs.max_pdf_kb', 256000) * 1024];
        $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
        $post = self::iniBytes((string) ini_get('post_max_size'));
        if ($upload > 0) {
            $limits[] = $upload;
        }
        if ($post > 0) {
            $limits[] = max(1024, $post - 1024 * 1024);
        }

        return max(1, (int) floor(min($limits) / 1024));
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (float) $value;

        return (int) ($number * match (strtolower(substr($value, -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
        });
    }
}
