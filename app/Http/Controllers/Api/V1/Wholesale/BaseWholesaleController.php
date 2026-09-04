<?php

namespace App\Http\Controllers\Api\V1\Wholesale;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class BaseWholesaleController extends Controller
{
    protected function resolveLocale(Request $request): array
    {
        $locale = strtolower(trim((string) $request->query('locale', config('app.locale', 'en'))));
        $fallbackLocale = strtolower((string) config('app.fallback_locale', config('app.locale', 'en')));

        if ($locale === '') {
            $locale = strtolower((string) config('app.locale', 'en'));
        }

        return [$locale, $fallbackLocale];
    }

    protected function resolvePerPage(Request $request, int $default = 50, int $max = 250): int
    {
        $perPage = (int) $request->integer('per_page', $default);
        if ($perPage < 1) {
            $perPage = $default;
        }

        return min($max, $perPage);
    }

    protected function resolveUpdatedSince(?string $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'updated_since' => 'Invalid updated_since datetime.',
            ]);
        }
    }

    protected function toBoolean(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $parsed ?? $default;
    }
}
