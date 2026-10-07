<?php

namespace App\Support\Integrations;

use RuntimeException;

final class MsanModule
{
    public static function available(): bool
    {
        return config('integrations.msan.available', false) === true;
    }

    public static function assertAvailable(): void
    {
        if (! self::available()) {
            throw new RuntimeException('M SAN dobavljački modul nije dostupan u ovoj trgovini.');
        }
    }
}
