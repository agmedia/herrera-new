<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_methods')) {
            return;
        }

        $this->updateMethod('cod', [
            'name' => 'Plaćanje pouzećem',
            'description' => 'Plaćanje gotovinom dostavljaču prilikom preuzimanja pošiljke.',
            'provider' => 'cod',
            'is_active' => true,
        ]);
        $this->updateMethod('bank', [
            'name' => 'Uplata na račun',
            'description' => 'Plaćanje prema podacima za uplatu koji se prikazuju nakon narudžbe.',
            'provider' => 'bank',
            'is_active' => true,
        ]);
        $this->updateMethod('pickup', [
            'name' => 'Plaćanje pri osobnom preuzimanju',
            'description' => 'Plaćanje prilikom osobnog preuzimanja robe na lokaciji Termola.',
            'provider' => 'pickup',
            'is_active' => true,
        ]);
        $this->updateMethod('keks', [
            'name' => 'KEKS Pay',
            'description' => 'Brzo plaćanje putem KEKS Pay aplikacije.',
            'provider' => 'kekspay',
        ]);
        $this->updateMethod('wspay', [
            'name' => 'WSPay',
            'description' => 'Neaktivna kartična integracija; Termol za kartično plaćanje koristi CorvusPay.',
            'provider' => 'wspay',
            'is_active' => false,
        ]);
        $this->configureQuoteRequestMethod();

        $corvus = DB::table('payment_methods')->where('code', 'corvus')->first();
        if (! $corvus) {
            $legacyCorvus = DB::table('payment_methods')
                ->whereIn('code', ['corvuspay', 'corvus_pay'])
                ->orderBy('id')
                ->first();

            if ($legacyCorvus) {
                DB::table('payment_methods')->where('id', $legacyCorvus->id)->update([
                    'code' => 'corvus',
                    'updated_at' => now(),
                ]);
                $corvus = DB::table('payment_methods')->where('id', $legacyCorvus->id)->first();
            }
        }

        if ($corvus) {
            $legacySettings = $this->preferredLegacyCorvusSettings();
            $canonicalSettings = $this->decodeJson($corvus->settings ?? null);
            $settings = array_replace([
                'corvus_mode' => 'test',
                'corvus_form_url' => 'https://wallet.test.corvuspay.com/checkout/',
                'corvus_language' => 'hr',
                'corvus_currency' => 'EUR',
                'corvus_require_complete' => 'false',
            ], $legacySettings, $canonicalSettings);

            if (! $this->hasCorvusCredentials($canonicalSettings) && $this->hasCorvusCredentials($legacySettings)) {
                $settings = array_replace($settings, $legacySettings);
            }

            foreach ($legacySettings as $key => $value) {
                if ($this->isBlankSetting($settings[$key] ?? null) && ! $this->isBlankSetting($value)) {
                    $settings[$key] = $value;
                }
            }

            $mode = strtolower(trim((string) ($settings['corvus_mode'] ?? 'test')));
            if (! in_array($mode, ['test', 'live'], true)) {
                $mode = 'test';
            }

            $settings['corvus_mode'] = $mode;
            $settings['corvus_form_url'] = $mode === 'live'
                ? 'https://wallet.corvuspay.com/checkout/'
                : 'https://wallet.test.corvuspay.com/checkout/';
            $settings['corvus_language'] = 'hr';
            $settings['corvus_currency'] = 'EUR';

            DB::table('payment_methods')->where('id', $corvus->id)->update([
                'name' => 'Kartično plaćanje (CorvusPay)',
                'description' => 'Sigurno kartično plaćanje putem CorvusPay obrasca.',
                'provider' => 'corvuspay',
                'is_active' => (bool) $corvus->is_active && $this->hasCorvusCredentials($settings),
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }

        // Old aliases may coexist with the canonical record. They remain available
        // for order history lookups but can never create a second checkout option.
        DB::table('payment_methods')
            ->whereIn('code', ['corvuspay', 'corvus_pay'])
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    private function configureQuoteRequestMethod(): void
    {
        $method = DB::table('payment_methods')->where('code', 'quote_request')->first();
        $values = [
            'name' => 'Plaćanje nakon potvrde ponude',
            'provider' => 'manual_quote',
            'geo_zone_id' => null,
            'description' => 'Narudžba se šalje bez naplate; Termol naknadno potvrđuje konačnu cijenu dostave i način plaćanja.',
            'fee_type' => 'fixed',
            'fee_value' => 0,
            'min_subtotal' => null,
            'max_subtotal' => null,
            'sort_order' => 4,
            'updated_at' => now(),
        ];

        if ($method) {
            DB::table('payment_methods')->where('id', $method->id)->update($values);

            return;
        }

        DB::table('payment_methods')->insert(array_merge($values, [
            'code' => 'quote_request',
            'is_active' => true,
            'settings' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]));
    }

    public function down(): void
    {
        // One-way storefront localization and payment-safety correction.
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function updateMethod(string $code, array $values): void
    {
        if (! DB::table('payment_methods')->where('code', $code)->exists()) {
            return;
        }

        DB::table('payment_methods')->where('code', $code)->update(array_merge($values, [
            'updated_at' => now(),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param  array<string, mixed>  $settings
     */
    private function hasCorvusCredentials(array $settings): bool
    {
        return trim((string) ($settings['corvus_store_id'] ?? '')) !== ''
            && trim((string) ($settings['corvus_secret_key'] ?? '')) !== '';
    }

    /** @return array<string, mixed> */
    private function preferredLegacyCorvusSettings(): array
    {
        $fallback = [];

        foreach (DB::table('payment_methods')
            ->whereIn('code', ['corvuspay', 'corvus_pay'])
            ->orderBy('id')
            ->get(['settings']) as $legacy) {
            $settings = $this->decodeJson($legacy->settings ?? null);
            $fallback = $fallback === [] ? $settings : $fallback;

            if ($this->hasCorvusCredentials($settings)) {
                return $settings;
            }
        }

        return $fallback;
    }

    private function isBlankSetting(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
};
