<?php

namespace App\Http\Controllers\Front\Concerns;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

trait ResolvesGridColumns
{
    private const GRID_COLS_COOKIE = 'front_grid_cols';

    protected function resolveGridCols(Request $request, int $default = 4): int
    {
        $queryValue = $request->query('cols');
        if ($queryValue !== null && $queryValue !== '') {
            return $this->normalizeGridCols((int) $queryValue, $default);
        }

        $cookieValue = $request->cookie(self::GRID_COLS_COOKIE);
        if ($cookieValue !== null && $cookieValue !== '') {
            return $this->normalizeGridCols((int) $cookieValue, $default);
        }

        return $this->normalizeGridCols($default, $default);
    }

    protected function queueGridColsCookie(int $cols): void
    {
        $normalized = $this->normalizeGridCols($cols, 4);
        Cookie::queue(cookie(self::GRID_COLS_COOKIE, (string) $normalized, 60 * 24 * 365));
    }

    protected function defaultDesktopGridCols(Request $request): int
    {
        return app(SystemSettingsService::class)->getInt('store_product_desktop_default_cols', 4, 4, 5);
    }

    private function normalizeGridCols(int $cols, int $default): int
    {
        if (! in_array($cols, [1, 2, 3, 4, 5], true)) {
            return $default;
        }

        return $cols;
    }
}
