<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class AssetVersion
{
    private const CACHE_KEY = 'assets:version';

    private ?string $current = null;

    public function current(): string
    {
        if ($this->current !== null) {
            return $this->current;
        }

        try {
            $cached = trim((string) Cache::get(self::CACHE_KEY, ''));

            if ($cached !== '') {
                return $this->current = $cached;
            }
        } catch (Throwable) {
            // The cache store may be unavailable while the application is being installed.
        }

        $manifestPath = public_path('build/manifest.json');
        $manifestTimestamp = is_file($manifestPath)
            ? (string) filemtime($manifestPath)
            : '1';

        return $this->current = 'build-'.$manifestTimestamp;
    }

    public function bump(): string
    {
        $version = now()->format('YmdHisv').'-'.Str::lower(Str::random(8));

        Cache::forever(self::CACHE_KEY, $version);

        return $this->current = $version;
    }
}
