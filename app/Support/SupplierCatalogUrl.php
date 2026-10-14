<?php

namespace App\Support;

final class SupplierCatalogUrl
{
    public static function isSafe(?string $url): bool
    {
        $url = trim((string) $url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! str_contains($host, '.') || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)
            || preg_match('/\.(?:localhost|local|internal|test|invalid)$/', $host)) {
            return false;
        }

        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/', $host);
    }
}
