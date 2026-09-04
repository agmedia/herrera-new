<?php

namespace App\Services\Front;

use App\Models\Settings\Local\Region;
use App\Support\CountryCatalog;

final class AddressDirectoryService
{
    /**
     * @return array{countries:array<int,array{code:string,name_hr:string,name_en:string}>,counties_hr:array<int,string>,places:array<int,array{postal_code:string,city:string,county:string,country_code:string}>}
     */
    public function data(): array
    {
        static $cached = null;

        if (is_array($cached)) {
            return $cached;
        }

        $path = public_path('front-theme/data/hr-places.json');

        $countries = $this->mergedCountries([]);

        if (! is_file($path)) {
            $cached = [
                'countries' => $countries,
                'counties_hr' => [],
                'places' => [],
            ];

            return $cached;
        }

        $raw = json_decode((string) file_get_contents($path), true);

        if (! is_array($raw)) {
            $cached = [
                'countries' => $countries,
                'counties_hr' => [],
                'places' => [],
            ];

            return $cached;
        }

        $cached = [
            'countries' => $this->mergedCountries(array_values(array_filter((array) ($raw['countries'] ?? []), 'is_array'))),
            'counties_hr' => array_values(array_filter((array) ($raw['counties_hr'] ?? []), 'is_string')),
            'places' => array_values(array_filter((array) ($raw['places'] ?? []), 'is_array')),
        ];

        return $cached;
    }

    /**
     * @return array<int,array{code:string,label:string}>
     */
    public function countries(string $locale = 'hr'): array
    {
        $isHr = str_starts_with(strtolower($locale), 'hr');

        return array_map(static function (array $country) use ($isHr): array {
            return [
                'code' => (string) ($country['code'] ?? ''),
                'label' => (string) ($isHr ? ($country['name_hr'] ?? $country['name_en'] ?? $country['code'] ?? '') : ($country['name_en'] ?? $country['name_hr'] ?? $country['code'] ?? '')),
            ];
        }, $this->data()['countries']);
    }

    /**
     * @return array<int,string>
     */
    public function counties(): array
    {
        return $this->data()['counties_hr'];
    }

    /**
     * @return array<string, array<int, array{code:string,name:string}>>
     */
    public function regionsByCountry(string $locale = 'hr'): array
    {
        static $cache = [];

        $cacheKey = strtolower($locale);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $rows = Region::query()
            ->where('is_active', true)
            ->orderBy('country_code')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['country_code', 'code', 'name']);

        $regions = [];
        foreach ($rows as $row) {
            $countryCode = strtoupper((string) $row->country_code);
            $regions[$countryCode] ??= [];
            $regions[$countryCode][] = [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
            ];
        }

        // Always prefer local HR counties dataset so postal autofill names match exactly.
        $regions['HR'] = array_map(static function (string $county): array {
            return [
                'code' => $county,
                'name' => $county,
            ];
        }, $this->counties());

        $cache[$cacheKey] = $regions;

        return $cache[$cacheKey];
    }

    /**
     * @return array<int,string>
     */
    public function regionNames(string $countryCode, string $locale = 'hr'): array
    {
        $countryCode = strtoupper(trim($countryCode));
        $regions = $this->regionsByCountry($locale);
        $items = $regions[$countryCode] ?? [];

        return array_values(array_map(static fn (array $item): string => (string) ($item['name'] ?? ''), $items));
    }

    public function placesAssetUrl(): string
    {
        return asset('front-theme/data/hr-places.json');
    }

    /**
     * @param  array<int, array<string, mixed>>  $fromFile
     * @return array<int, array{code:string,name_hr:string,name_en:string}>
     */
    private function mergedCountries(array $fromFile): array
    {
        $base = [];
        foreach (CountryCatalog::all() as $row) {
            $base[(string) $row['code']] = $row;
        }

        foreach ($fromFile as $country) {
            $code = strtoupper(trim((string) ($country['code'] ?? '')));
            if ($code === '' || !isset($base[$code])) {
                continue;
            }

            $nameHr = trim((string) ($country['name_hr'] ?? ''));
            $nameEn = trim((string) ($country['name_en'] ?? ''));

            if ($nameHr !== '') {
                $base[$code]['name_hr'] = $nameHr;
            }
            if ($nameEn !== '') {
                $base[$code]['name_en'] = $nameEn;
            }
        }

        $countries = array_values($base);
        usort($countries, static fn (array $a, array $b): int => strcmp($a['name_en'], $b['name_en']));

        return $countries;
    }
}
