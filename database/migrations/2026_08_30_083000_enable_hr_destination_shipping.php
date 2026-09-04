<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('system_settings')) {
            $key = (string) config('termol_shipping.islands.setting_key', 'shipping_hr_island_policy');

            if (! DB::table('system_settings')->where('key', $key)->exists()) {
                DB::table('system_settings')->insert([
                    'key' => $key,
                    'value' => json_encode(
                        config('termol_shipping.islands.default_policy', 'unconnected_only'),
                        JSON_THROW_ON_ERROR,
                    ),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            Cache::forget('settings.system.map');
        }

        if (! Schema::hasTable('shipping_methods')) {
            return;
        }

        $this->configureBoxNowSafety($now);
        $this->configureMissingWeightQuote($now);

        $labels = [
            'standard' => [
                'name' => 'Standardna dostava',
                'description' => 'Standardna dostava na adresu u Hrvatskoj.',
                'is_active' => false,
            ],
            'standard_eu' => [
                'name' => 'Standardna dostava – EU',
                'description' => 'Standardna dostava u države Europske unije izvan Hrvatske.',
            ],
            'standard_world' => [
                'name' => 'Standardna dostava – svijet',
                'description' => 'Standardna međunarodna dostava izvan Europske unije.',
            ],
            'pickup' => [
                'name' => 'Osobno preuzimanje – Vinkovci',
                'description' => 'Preuzimanje na adresi Lapovačka 11A, 32100 Vinkovci, radnim danom 08:00–16:00 ili prema prethodnom dogovoru s korisničkom službom.',
                'is_active' => true,
            ],
            'express' => [
                'name' => 'Ekspresna dostava',
                'description' => 'Prioritetna dostava na adresu.',
                'is_active' => false,
            ],
            'boxnow' => [
                'name' => 'BOX NOW paketomat',
                'description' => 'Dostava u odabrani BOX NOW paketomat.',
            ],
            'box_now' => [
                'name' => 'BOX NOW paketomat',
                'description' => 'Dostava u odabrani BOX NOW paketomat.',
            ],
            'mbe_mainland_hr' => [
                'name' => 'MBE Boxes – dostava na kopno',
                'description' => 'Dostava na adresu u Hrvatskoj prema MBE Boxes cjeniku za kopno.',
                'is_active' => true,
            ],
            'mbe_islands_hr' => [
                'name' => 'MBE Boxes – dostava na otoke',
                'description' => 'Dostava na hrvatske otoke prema MBE Boxes cjeniku i politici odabranoj u administraciji.',
                'is_active' => true,
            ],
        ];

        foreach ($labels as $code => $values) {
            DB::table('shipping_methods')
                ->where('code', $code)
                ->update(array_merge($values, ['updated_at' => $now]));
        }
    }

    public function down(): void
    {
        // Existing merchant labels and activation choices are intentionally not
        // overwritten during rollback of this forward-only configuration step.
    }

    private function configureMissingWeightQuote(mixed $now): void
    {
        $code = 'mbe_missing_weight_quote_hr';
        $method = DB::table('shipping_methods')->where('code', $code)->first();
        $settings = $this->decodeJson($method?->settings ?? null);
        $settings['destination_scopes'] = ['hr_mainland', 'hr_islands'];
        $settings['destination_country_codes'] = ['HR'];
        $settings['allow_incomplete_destination'] = true;
        $settings['fallback_for_missing_weight'] = true;
        $settings['configured_from'] = 'termol_webshop_requirements_2026_08';
        $croatiaZoneId = DB::table('geo_zones')->where('code', 'hr')->value('id')
            ?? DB::table('geo_zones')->where('name', 'like', '%Croatia%')->value('id')
            ?? DB::table('geo_zones')->where('name', 'like', '%Hrvats%')->value('id');

        $values = [
            'name' => 'Dostava na adresu – cijena na upit',
            'carrier' => 'mbe',
            'service_type' => 'quote',
            'pricing_type' => 'quote',
            'geo_zone_id' => $croatiaZoneId ?? $method?->geo_zone_id,
            'description' => 'Privremena sigurna opcija kada artikli nemaju težinu potrebnu za automatski MBE obračun.',
            'price' => 0,
            'free_over' => null,
            'min_subtotal' => null,
            'max_subtotal' => null,
            'min_weight_kg' => null,
            'max_weight_kg' => null,
            'max_length_cm' => null,
            'max_width_cm' => null,
            'max_height_cm' => null,
            'allows_fragile' => true,
            'allows_oversized' => true,
            'allows_heavy' => true,
            'fragile_surcharge' => 0,
            'oversized_surcharge' => 0,
            'heavy_surcharge' => 0,
            'missing_measurements_policy' => 'allow',
            'sort_order' => 82,
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];

        if ($method) {
            DB::table('shipping_methods')->where('id', $method->id)->update($values);

            return;
        }

        DB::table('shipping_methods')->insert(array_merge($values, [
            'code' => $code,
            'is_active' => true,
            'created_at' => $now,
        ]));
    }

    private function configureBoxNowSafety(mixed $now): void
    {
        $methods = DB::table('shipping_methods')
            ->whereIn('code', ['boxnow', 'box_now'])
            ->orderBy('id')
            ->get();

        if ($methods->isEmpty()) {
            return;
        }

        $primary = $methods->firstWhere('code', 'boxnow') ?? $methods->first();
        $primarySettings = $this->decodeJson($primary->settings ?? null);
        foreach ($methods as $method) {
            if ((int) $method->id === (int) $primary->id) {
                continue;
            }

            foreach ($this->decodeJson($method->settings ?? null) as $key => $value) {
                if ($this->isBlankSetting($primarySettings[$key] ?? null) && ! $this->isBlankSetting($value)) {
                    $primarySettings[$key] = $value;
                }
            }
        }

        foreach ($methods as $method) {
            $isPrimary = (int) $method->id === (int) $primary->id;
            $settings = $isPrimary ? $primarySettings : $this->decodeJson($method->settings ?? null);
            $partnerId = trim((string) ($settings['boxnow_partner_id'] ?? ''));

            DB::table('shipping_methods')->where('id', $method->id)->update([
                'carrier' => 'boxnow',
                'service_type' => 'parcel_locker',
                'pricing_type' => 'flat',
                'max_weight_kg' => 20,
                'max_length_cm' => 60,
                'max_width_cm' => 45,
                'max_height_cm' => 36,
                'allows_fragile' => false,
                'allows_oversized' => false,
                'allows_heavy' => false,
                'missing_measurements_policy' => 'block',
                'is_active' => $isPrimary && $partnerId !== '' && (bool) $method->is_active,
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isBlankSetting(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
};
