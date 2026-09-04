<?php

namespace App\Support;

final class CountryCatalog
{
    /**
     * EU member states by ISO 3166-1 alpha-2.
     *
     * @return array<int, string>
     */
    public static function euCodes(): array
    {
        return [
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI',
            'FR', 'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT',
            'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
        ];
    }

    /**
     * @return array<int, array{code:string,name_hr:string,name_en:string}>
     */
    public static function all(): array
    {
        static $cached = null;

        if (is_array($cached)) {
            return $cached;
        }

        $intlAvailable = class_exists(\Locale::class);

        /** @var array<string, string> $regions */
        $regions = require base_path('vendor/nesbot/carbon/src/Carbon/List/regions.php');
        $countries = [];

        foreach ($regions as $code => $fallbackEnName) {
            $iso = strtoupper((string) $code);
            if (!preg_match('/^[A-Z]{2}$/', $iso)) {
                continue;
            }

            $en = $intlAvailable
                ? trim((string) \Locale::getDisplayRegion('und-'.$iso, 'en'))
                : '';
            $hr = $intlAvailable
                ? trim((string) \Locale::getDisplayRegion('und-'.$iso, 'hr'))
                : '';

            if ($en === '') {
                $en = (string) $fallbackEnName;
            }
            if ($hr === '') {
                $hr = $en;
            }

            $countries[] = [
                'code' => $iso,
                'name_hr' => $hr,
                'name_en' => $en,
            ];
        }

        usort($countries, static fn (array $a, array $b): int => strcmp($a['name_en'], $b['name_en']));
        $cached = $countries;

        return $cached;
    }

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_values(array_map(
            static fn (array $country): string => (string) $country['code'],
            self::all()
        ));
    }
}
