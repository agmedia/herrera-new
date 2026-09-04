<?php

namespace App\Http\Middleware;

use App\Models\Settings\Local\Language;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetFrontendLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        [$availableCodes, $availableLanguages, $defaultLocale] = $this->resolveLanguages();

        $sessionLocale = $this->normalizeLocale((string) $request->session()->get('front_locale', ''));
        $locale = in_array($sessionLocale, $availableCodes, true) ? $sessionLocale : $defaultLocale;

        App::setLocale($locale);
        $request->attributes->set('front_locale', $locale);

        View::share('frontLocale', $locale);
        View::share('frontLanguages', $availableLanguages);

        return $next($request);
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array{code: string, label: string}>, 2: string}
     */
    private function resolveLanguages(): array
    {
        $fallback = $this->normalizeLocale((string) config('app.locale', 'en'));

        try {
            $rows = Language::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->get(['code', 'native_name', 'name']);

            $availableLanguages = [];
            foreach ($rows as $row) {
                $code = $this->normalizeLocale((string) $row->code);
                if ($code === '') {
                    continue;
                }

                $label = trim((string) ($row->native_name ?: $row->name ?: strtoupper($code)));
                $availableLanguages[] = [
                    'code' => $code,
                    'label' => $label,
                ];
            }

            if ($availableLanguages === []) {
                return [[$fallback], [['code' => $fallback, 'label' => strtoupper($fallback)]], $fallback];
            }

            $availableCodes = array_values(array_unique(array_map(
                static fn (array $item): string => (string) $item['code'],
                $availableLanguages
            )));

            return [$availableCodes, $availableLanguages, (string) ($availableCodes[0] ?? $fallback)];
        } catch (\Throwable) {
            return [[$fallback], [['code' => $fallback, 'label' => strtoupper($fallback)]], $fallback];
        }
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = strtolower(trim($locale));
        if ($normalized === '') {
            return '';
        }

        if (str_contains($normalized, '_')) {
            $normalized = (string) explode('_', $normalized)[0];
        }

        if (str_contains($normalized, '-')) {
            $normalized = (string) explode('-', $normalized)[0];
        }

        return $normalized;
    }
}
