<?php

namespace Database\Seeders;

use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\GeoZone;
use App\Models\Settings\Local\GeoZoneCountry;
use App\Models\Settings\Local\Language;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\Region;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\TaxRate;
use App\Support\CountryCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class SettingsLocalSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRegions();

        $hr = GeoZone::updateOrCreate(
            ['code' => 'HR'],
            ['name' => 'Croatia', 'description' => 'Domestic zone', 'is_active' => true, 'sort_order' => 1]
        );

        $eu = GeoZone::updateOrCreate(
            ['code' => 'EU'],
            ['name' => 'European Union', 'description' => 'EU shipping/payment zone', 'is_active' => true, 'sort_order' => 2]
        );

        $world = GeoZone::updateOrCreate(
            ['code' => 'WORLD'],
            ['name' => 'World', 'description' => 'Rest of world', 'is_active' => true, 'sort_order' => 3]
        );

        $euCodes = array_values(array_filter(
            CountryCatalog::euCodes(),
            static fn (string $code): bool => $code !== 'HR'
        ));
        $allCodes = CountryCatalog::codes();
        $worldCodes = array_values(array_diff($allCodes, array_merge(['HR'], CountryCatalog::euCodes())));

        GeoZoneCountry::query()
            ->whereIn('geo_zone_id', [$hr->id, $eu->id, $world->id])
            ->delete();

        $now = Carbon::now();
        $payload = [];

        $payload[] = [
            'geo_zone_id' => $hr->id,
            'country_code' => 'HR',
            'region_code' => null,
            'postal_code_from' => null,
            'postal_code_to' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach ($euCodes as $code) {
            $payload[] = [
                'geo_zone_id' => $eu->id,
                'country_code' => $code,
                'region_code' => null,
                'postal_code_from' => null,
                'postal_code_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach ($worldCodes as $code) {
            $payload[] = [
                'geo_zone_id' => $world->id,
                'country_code' => $code,
                'region_code' => null,
                'postal_code_from' => null,
                'postal_code_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        GeoZoneCountry::query()->insert($payload);

        Currency::updateOrCreate(
            ['code' => 'EUR'],
            [
                'name' => 'Euro',
                'symbol' => 'EUR',
                'symbol_position' => 'left',
                'decimal_places' => 2,
                'exchange_rate' => 1,
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        Currency::updateOrCreate(
            ['code' => 'USD'],
            [
                'name' => 'US Dollar',
                'symbol' => '$',
                'symbol_position' => 'left',
                'decimal_places' => 2,
                'exchange_rate' => 1.08,
                'is_default' => false,
                'is_active' => false,
                'sort_order' => 2,
            ]
        );

        TaxRate::updateOrCreate(
            ['code' => 'PDV25'],
            [
                'name' => 'PDV 25%',
                'geo_zone_id' => $hr->id,
                'rate_type' => 'percent',
                'rate' => 25,
                'priority' => 1,
                'is_default' => true,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );

        OrderStatus::updateOrCreate(
            ['code' => 'new'],
            ['name' => 'New', 'color' => 'blue', 'is_default' => true, 'is_paid' => false, 'is_cancelled' => false, 'is_active' => true, 'sort_order' => 1]
        );
        OrderStatus::updateOrCreate(
            ['code' => 'paid'],
            ['name' => 'Paid', 'color' => 'emerald', 'is_default' => false, 'is_paid' => true, 'is_cancelled' => false, 'is_active' => true, 'sort_order' => 2]
        );
        OrderStatus::updateOrCreate(
            ['code' => 'sent'],
            ['name' => 'Sent', 'color' => 'violet', 'is_default' => false, 'is_paid' => true, 'is_cancelled' => false, 'is_active' => true, 'sort_order' => 3]
        );
        OrderStatus::updateOrCreate(
            ['code' => 'cancelled'],
            ['name' => 'Cancelled', 'color' => 'rose', 'is_default' => false, 'is_paid' => false, 'is_cancelled' => true, 'is_active' => true, 'sort_order' => 4]
        );

        Language::updateOrCreate(
            ['code' => 'hr'],
            ['locale' => 'hr_HR', 'name' => 'Croatian', 'native_name' => 'Hrvatski', 'direction' => 'ltr', 'is_default' => true, 'is_active' => true, 'sort_order' => 1]
        );
        Language::updateOrCreate(
            ['code' => 'en'],
            ['locale' => 'en_US', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr', 'is_default' => false, 'is_active' => false, 'sort_order' => 2]
        );
        Language::updateOrCreate(
            ['code' => 'de'],
            ['locale' => 'de_DE', 'name' => 'German', 'native_name' => 'Deutsch', 'direction' => 'ltr', 'is_default' => false, 'is_active' => false, 'sort_order' => 3]
        );

        PaymentMethod::updateOrCreate(
            ['code' => 'cod'],
            [
                'name' => 'Plaćanje pouzećem',
                'provider' => 'cod',
                'geo_zone_id' => $hr->id,
                'description' => 'Plaćanje gotovinom dostavljaču prilikom preuzimanja pošiljke.',
                'fee_type' => 'fixed',
                'fee_value' => 0,
                'is_active' => true,
                'sort_order' => 1,
            ]
        );
        PaymentMethod::updateOrCreate(
            ['code' => 'bank'],
            [
                'name' => 'Uplata na račun',
                'provider' => 'bank',
                'geo_zone_id' => null,
                'description' => 'Plaćanje prema podacima za uplatu koji se prikazuju nakon narudžbe.',
                'fee_type' => 'fixed',
                'fee_value' => 0,
                'is_active' => true,
                'sort_order' => 2,
            ]
        );
        PaymentMethod::updateOrCreate(
            ['code' => 'pickup'],
            [
                'name' => 'Plaćanje pri osobnom preuzimanju',
                'provider' => 'pickup',
                'geo_zone_id' => $hr->id,
                'description' => 'Plaćanje prilikom osobnog preuzimanja robe na lokaciji Termola.',
                'fee_type' => 'fixed',
                'fee_value' => 0,
                'is_active' => true,
                'sort_order' => 3,
            ]
        );
        $quoteRequest = PaymentMethod::query()->firstOrNew(['code' => 'quote_request']);
        $quoteRequestWasExisting = $quoteRequest->exists;
        $quoteRequestWasActive = (bool) $quoteRequest->is_active;
        $quoteRequest->fill([
            'name' => 'Plaćanje nakon potvrde ponude',
            'provider' => 'manual_quote',
            'geo_zone_id' => null,
            'description' => 'Narudžba se šalje bez naplate; Termol naknadno potvrđuje konačnu cijenu dostave i način plaćanja.',
            'fee_type' => 'fixed',
            'fee_value' => 0,
            'is_active' => $quoteRequestWasExisting ? $quoteRequestWasActive : true,
            'sort_order' => 4,
        ])->save();
        PaymentMethod::updateOrCreate(
            ['code' => 'wspay'],
            [
                'name' => 'WSPay',
                'provider' => 'wspay',
                'geo_zone_id' => null,
                'description' => 'Neaktivna kartična integracija; Termol za kartično plaćanje koristi CorvusPay.',
                'fee_type' => 'fixed',
                'fee_value' => 0,
                'is_active' => false,
                'sort_order' => 5,
                'settings' => [
                    'wspay_mode' => 'test',
                    'wspay_form_url' => 'https://formtest.wspay.biz/authorization.aspx',
                    'wspay_return_method' => 'GET',
                ],
            ]
        );
        $legacyCorvusMethods = PaymentMethod::query()
            ->whereIn('code', ['corvuspay', 'corvus_pay'])
            ->orderBy('id')
            ->get();
        $corvus = PaymentMethod::query()->where('code', 'corvus')->first()
            ?? $legacyCorvusMethods->first()
            ?? new PaymentMethod;
        $corvusWasExisting = $corvus->exists;
        $corvusWasActive = (bool) $corvus->is_active;
        $corvus->code = 'corvus';
        $legacyCorvusSettings = $legacyCorvusMethods
            ->map(static fn (PaymentMethod $method): array => is_array($method->settings) ? $method->settings : [])
            ->first(fn (array $settings): bool => $this->hasCorvusCredentials($settings))
            ?? $legacyCorvusMethods
                ->map(static fn (PaymentMethod $method): array => is_array($method->settings) ? $method->settings : [])
                ->first()
            ?? [];
        $currentCorvusSettings = is_array($corvus->settings) ? $corvus->settings : [];
        $corvusSettings = array_replace([
            'corvus_mode' => 'test',
            'corvus_form_url' => 'https://wallet.test.corvuspay.com/checkout/',
            'corvus_language' => 'hr',
            'corvus_currency' => 'EUR',
            'corvus_require_complete' => 'false',
        ], $legacyCorvusSettings, $currentCorvusSettings);
        if (! $this->hasCorvusCredentials($currentCorvusSettings) && $this->hasCorvusCredentials($legacyCorvusSettings)) {
            $corvusSettings = array_replace($corvusSettings, $legacyCorvusSettings);
        }
        foreach ($legacyCorvusSettings as $key => $value) {
            if ($this->isBlankSetting($corvusSettings[$key] ?? null) && ! $this->isBlankSetting($value)) {
                $corvusSettings[$key] = $value;
            }
        }
        $corvusMode = strtolower(trim((string) ($corvusSettings['corvus_mode'] ?? 'test')));
        if (! in_array($corvusMode, ['test', 'live'], true)) {
            $corvusMode = 'test';
        }
        $corvusSettings['corvus_mode'] = $corvusMode;
        $corvusSettings['corvus_form_url'] = $corvusMode === 'live'
            ? 'https://wallet.corvuspay.com/checkout/'
            : 'https://wallet.test.corvuspay.com/checkout/';
        $corvusSettings['corvus_language'] = 'hr';
        $corvusSettings['corvus_currency'] = 'EUR';
        $corvusReady = trim((string) ($corvusSettings['corvus_store_id'] ?? '')) !== ''
            && trim((string) ($corvusSettings['corvus_secret_key'] ?? '')) !== '';

        $corvus->fill([
            'name' => 'Kartično plaćanje (CorvusPay)',
            'provider' => 'corvuspay',
            'geo_zone_id' => null,
            'description' => 'Sigurno kartično plaćanje putem CorvusPay obrasca.',
            'fee_type' => 'fixed',
            'fee_value' => 0,
            'is_active' => $corvusWasExisting ? $corvusWasActive : $corvusReady,
            'sort_order' => 6,
            'settings' => $corvusSettings,
        ])->save();
        PaymentMethod::query()
            ->whereIn('code', ['corvuspay', 'corvus_pay'])
            ->where('id', '!=', $corvus->id)
            ->update(['is_active' => false]);
        PaymentMethod::updateOrCreate(
            ['code' => 'keks'],
            [
                'name' => 'KEKS Pay',
                'provider' => 'kekspay',
                'geo_zone_id' => null,
                'description' => 'Najbrže i bez naknada putem KEKS Pay aplikacije!',
                'fee_type' => 'fixed',
                'fee_value' => 0,
                'is_active' => false,
                'sort_order' => 7,
                'settings' => [
                    'keks_mode' => 'test',
                    'keks_qr_type' => 1,
                    'keks_sell_base_url' => 'https://kekspayuat.erstebank.hr/galebpay',
                    'keks_advice_auth_mode' => 'none',
                ],
            ]
        );

        ShippingMethod::updateOrCreate(
            ['code' => 'standard'],
            [
                'name' => 'Standardna dostava',
                'geo_zone_id' => $hr->id,
                'description' => 'Standardna dostava na adresu u Hrvatskoj.',
                'price' => 4.99,
                'free_over' => 60,
                'is_active' => false,
                'sort_order' => 1,
            ]
        );
        ShippingMethod::updateOrCreate(
            ['code' => 'standard_eu'],
            [
                'name' => 'Standardna dostava – EU',
                'geo_zone_id' => $eu->id,
                'description' => 'Standardna dostava u države Europske unije izvan Hrvatske.',
                'price' => 9.99,
                'free_over' => 120,
                'is_active' => true,
                'sort_order' => 2,
            ]
        );
        ShippingMethod::updateOrCreate(
            ['code' => 'standard_world'],
            [
                'name' => 'Standardna dostava – svijet',
                'geo_zone_id' => $world->id,
                'description' => 'Standardna međunarodna dostava izvan Europske unije.',
                'price' => 19.99,
                'free_over' => 200,
                'is_active' => true,
                'sort_order' => 3,
            ]
        );
        ShippingMethod::updateOrCreate(
            ['code' => 'pickup'],
            [
                'name' => 'Osobno preuzimanje – Vinkovci',
                'geo_zone_id' => $hr->id,
                'description' => 'Preuzimanje na adresi Lapovačka 11A, 32100 Vinkovci, radnim danom 08:00–16:00 ili prema prethodnom dogovoru s korisničkom službom.',
                'price' => 0,
                'free_over' => null,
                'is_active' => true,
                'sort_order' => 4,
            ]
        );
        ShippingMethod::updateOrCreate(
            ['code' => 'express'],
            [
                'name' => 'Ekspresna dostava',
                'geo_zone_id' => $hr->id,
                'description' => 'Prioritetna dostava na adresu.',
                'price' => 8.99,
                'free_over' => null,
                'is_active' => false,
                'sort_order' => 5,
            ]
        );
        $boxNowCandidates = ShippingMethod::query()
            ->whereIn('code', ['boxnow', 'box_now'])
            ->orderBy('id')
            ->get();
        $boxNow = $boxNowCandidates->firstWhere('code', 'boxnow')
            ?? $boxNowCandidates->firstWhere('code', 'box_now')
            ?? new ShippingMethod(['code' => 'boxnow']);
        $boxNowWasExisting = $boxNow->exists;
        $boxNowWasActive = (bool) $boxNow->is_active;
        $boxNowSettings = is_array($boxNow->settings) ? $boxNow->settings : [];
        foreach ($boxNowCandidates as $candidate) {
            if ($boxNowWasExisting && (int) $candidate->id === (int) $boxNow->id) {
                continue;
            }

            $candidateSettings = is_array($candidate->settings) ? $candidate->settings : [];
            foreach ($candidateSettings as $key => $value) {
                if ($this->isBlankSetting($boxNowSettings[$key] ?? null) && ! $this->isBlankSetting($value)) {
                    $boxNowSettings[$key] = $value;
                }
            }
        }
        $boxNowSettings['boxnow_partner_id'] ??= '';
        $boxNowConfigured = trim((string) $boxNowSettings['boxnow_partner_id']) !== '';
        $boxNow->fill([
            'name' => 'BOX NOW paketomat',
            'carrier' => 'boxnow',
            'service_type' => 'parcel_locker',
            'pricing_type' => 'flat',
            'geo_zone_id' => $hr->id,
            'description' => 'Dostava u odabrani BOX NOW paketomat.',
            'price' => 2.99,
            'free_over' => null,
            'min_weight_kg' => null,
            'max_weight_kg' => 20,
            'max_length_cm' => 60,
            'max_width_cm' => 45,
            'max_height_cm' => 36,
            'allows_fragile' => false,
            'allows_oversized' => false,
            'allows_heavy' => false,
            'missing_measurements_policy' => 'block',
            'is_active' => $boxNowConfigured && ($boxNowWasExisting ? $boxNowWasActive : true),
            'sort_order' => 6,
            'settings' => $boxNowSettings,
        ])->save();
        ShippingMethod::query()
            ->whereIn('code', ['boxnow', 'box_now'])
            ->where('id', '!=', $boxNow->id)
            ->update(['is_active' => false]);
    }

    private function seedRegions(): void
    {
        $path = public_path('front-theme/data/hr-places.json');
        if (! File::exists($path)) {
            return;
        }

        $raw = json_decode((string) File::get($path), true);
        if (! is_array($raw)) {
            return;
        }

        $counties = array_values(array_filter((array) ($raw['counties_hr'] ?? []), 'is_string'));
        if ($counties === []) {
            return;
        }

        Region::query()->where('country_code', 'HR')->delete();

        $payload = [];
        $now = Carbon::now();
        foreach (array_values($counties) as $index => $county) {
            $name = trim($county);
            if ($name === '') {
                continue;
            }

            $payload[] = [
                'country_code' => 'HR',
                'code' => strtoupper(str_replace(' ', '_', $name)),
                'name' => $name,
                'is_active' => true,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($payload !== []) {
            Region::query()->insert($payload);
        }
    }

    /** @param array<string, mixed> $settings */
    private function hasCorvusCredentials(array $settings): bool
    {
        return trim((string) ($settings['corvus_store_id'] ?? '')) !== ''
            && trim((string) ($settings['corvus_secret_key'] ?? '')) !== '';
    }

    private function isBlankSetting(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
