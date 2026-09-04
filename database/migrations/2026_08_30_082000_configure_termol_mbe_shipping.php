<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MBE_METHODS = [
        'mbe_mainland_hr' => [
            'name' => 'MBE Boxes - dostava kopno',
            'description' => 'Dostava na području Republike Hrvatske (kopno) prema MBE Boxes cjeniku.',
            'destination_scope' => 'hr_mainland',
            'sort_order' => 80,
            'rates' => [
                ['min_weight_kg' => '0.000', 'max_weight_kg' => '4.999', 'price' => '6.00'],
                ['min_weight_kg' => '5.000', 'max_weight_kg' => '9.999', 'price' => '7.00'],
                ['min_weight_kg' => '10.000', 'max_weight_kg' => '19.999', 'price' => '9.50'],
                ['min_weight_kg' => '20.000', 'max_weight_kg' => '29.999', 'price' => '17.50'],
                ['min_weight_kg' => '30.000', 'max_weight_kg' => '39.999', 'price' => '20.00'],
                ['min_weight_kg' => '40.000', 'max_weight_kg' => '49.999', 'price' => '22.50'],
                ['min_weight_kg' => '50.000', 'max_weight_kg' => '74.999', 'price' => '30.00'],
                ['min_weight_kg' => '75.000', 'max_weight_kg' => '99.999', 'price' => '35.00'],
                ['min_weight_kg' => '100.000', 'max_weight_kg' => '199.999', 'price' => '60.00'],
                ['min_weight_kg' => '200.000', 'max_weight_kg' => '299.999', 'price' => '75.00'],
                ['min_weight_kg' => '300.000', 'max_weight_kg' => '799.999', 'price' => '100.00'],
                ['min_weight_kg' => '800.000', 'max_weight_kg' => null, 'price' => '200.00'],
            ],
        ],
        'mbe_islands_hr' => [
            'name' => 'MBE Boxes - dostava otoci',
            'description' => 'Dostava na hrvatske otoke (s mostom i bez mosta) prema MBE Boxes cjeniku.',
            'destination_scope' => 'hr_islands',
            'sort_order' => 81,
            'rates' => [
                ['min_weight_kg' => '0.000', 'max_weight_kg' => '4.999', 'price' => '10.00'],
                ['min_weight_kg' => '5.000', 'max_weight_kg' => '19.999', 'price' => '12.00'],
                ['min_weight_kg' => '20.000', 'max_weight_kg' => '29.999', 'price' => '15.00'],
                ['min_weight_kg' => '30.000', 'max_weight_kg' => '39.999', 'price' => '20.00'],
                ['min_weight_kg' => '40.000', 'max_weight_kg' => '49.999', 'price' => '30.00'],
                ['min_weight_kg' => '50.000', 'max_weight_kg' => '74.999', 'price' => '35.00'],
                ['min_weight_kg' => '75.000', 'max_weight_kg' => '99.999', 'price' => '40.00'],
                ['min_weight_kg' => '100.000', 'max_weight_kg' => '199.999', 'price' => '55.00'],
                ['min_weight_kg' => '200.000', 'max_weight_kg' => '299.999', 'price' => '80.00'],
                ['min_weight_kg' => '300.000', 'max_weight_kg' => '399.999', 'price' => '95.00'],
                ['min_weight_kg' => '400.000', 'max_weight_kg' => '499.999', 'price' => '120.00'],
                ['min_weight_kg' => '500.000', 'max_weight_kg' => null, 'price' => '150.00'],
            ],
        ],
    ];

    private const QUOTE_CATEGORY_CODES = [
        '020203',
        '020301',
        '020302',
        '020303',
        '020304',
        '020501',
        '020502',
        '020601',
        '020602',
        '020603',
        '020604',
        '030101',
        '030102',
        '030103',
        '030104',
        '040101',
        '040102',
        '040103',
        '040104',
        '040105',
        '040106',
        '070101',
        '070102',
        '070103',
        '070104',
        '070105',
        '070106',
        '070107',
        '070108',
        '070109',
        '070110',
        '070201',
        '070202',
        '070203',
        '070204',
        '070205',
        '070301',
        '070302',
        '070303',
        '070304',
        '070305',
        '070601',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('shipping_methods') || ! Schema::hasTable('shipping_method_rates')) {
            return;
        }

        $now = now();
        $croatiaZoneId = null;
        if (Schema::hasTable('geo_zones')) {
            $croatiaZoneId = DB::table('geo_zones')->where('code', 'hr')->value('id')
                ?? DB::table('geo_zones')->where('name', 'like', '%Croatia%')->value('id')
                ?? DB::table('geo_zones')->where('name', 'like', '%Hrvats%')->value('id');
        }

        foreach (self::MBE_METHODS as $code => $configuration) {
            $method = DB::table('shipping_methods')->where('code', $code)->first();
            $settings = $this->decodeJson($method?->settings ?? null);
            $settings['destination_scope'] = (string) ($configuration['destination_scope'] ?? '');
            $settings['rate_boundary_policy'] = 'shared_boundary_belongs_to_upper_band_0.001_kg_precision';
            $settings['configured_from'] = 'termol_webshop_requirements_2026_08';
            if (! $method) {
                $settings['created_by_termol_requirements_migration'] = true;
            }

            $values = [
                'name' => (string) $configuration['name'],
                'carrier' => 'mbe',
                'service_type' => 'home_delivery',
                'pricing_type' => 'weight_tiers',
                'geo_zone_id' => $croatiaZoneId ?? $method?->geo_zone_id,
                'description' => (string) ($configuration['description'] ?? ''),
                'price' => 0,
                'free_over' => null,
                'min_subtotal' => null,
                'max_subtotal' => null,
                'min_weight_kg' => 0,
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
                'missing_measurements_policy' => 'block',
                'sort_order' => (int) ($configuration['sort_order'] ?? 80),
                'settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ];

            if ($method) {
                DB::table('shipping_methods')->where('id', $method->id)->update($values);
                $methodId = (int) $method->id;
            } else {
                $methodId = (int) DB::table('shipping_methods')->insertGetId(array_merge($values, [
                    'code' => $code,
                    // Tariffs are ready for review, but destination routing must be
                    // confirmed before either MBE method is enabled in production.
                    'is_active' => false,
                    'created_at' => $now,
                ]));
            }

            $existingRates = DB::table('shipping_method_rates')
                ->where('shipping_method_id', $methodId)
                ->orderBy('id')
                ->get()
                ->keyBy('sort_order');
            $keptRateIds = [];

            foreach (array_values((array) ($configuration['rates'] ?? [])) as $sortOrder => $rate) {
                $existingRate = $existingRates->get($sortOrder);
                $values = [
                    'shipping_method_id' => $methodId,
                    'min_weight_kg' => $rate['min_weight_kg'],
                    'max_weight_kg' => $rate['max_weight_kg'],
                    'price' => $rate['price'],
                    'sort_order' => $sortOrder,
                    'updated_at' => $now,
                ];

                if ($existingRate) {
                    DB::table('shipping_method_rates')->where('id', $existingRate->id)->update($values);
                    $keptRateIds[] = (int) $existingRate->id;
                } else {
                    $keptRateIds[] = (int) DB::table('shipping_method_rates')->insertGetId(array_merge(
                        $values,
                        ['created_at' => $now],
                    ));
                }
            }

            DB::table('shipping_method_rates')
                ->where('shipping_method_id', $methodId)
                ->when($keptRateIds !== [], fn ($query) => $query->whereNotIn('id', $keptRateIds))
                ->delete();
        }

        $this->configurePickup($croatiaZoneId, $now);
        $this->applyQuoteShippingLabels();
    }

    public function down(): void
    {
        if (Schema::hasTable('shipping_methods')) {
            $createdMethodIds = DB::table('shipping_methods')
                ->whereIn('code', array_keys(self::MBE_METHODS))
                ->get(['id', 'settings'])
                ->filter(fn (object $method): bool => (bool) (
                    $this->decodeJson($method->settings)['created_by_termol_requirements_migration'] ?? false
                ))
                ->pluck('id')
                ->all();

            if ($createdMethodIds !== []) {
                DB::table('shipping_methods')->whereIn('id', $createdMethodIds)->delete();
            }
        }

        // Category labels are intentionally retained. They may have existed
        // before this migration, and removing them could enable parcel delivery
        // for products that must continue through the manual quote workflow.
    }

    private function configurePickup(mixed $croatiaZoneId, mixed $now): void
    {
        $pickups = DB::table('shipping_methods')
            ->whereIn('code', ['pickup', 'store_pickup', 'local_pickup'])
            ->get()
            ->keyBy('code');
        $pickup = $pickups->get('pickup')
            ?? $pickups->get('store_pickup')
            ?? $pickups->get('local_pickup');
        $settings = $this->decodeJson($pickup?->settings ?? null);
        $settings['pickup_address'] = 'Lapovačka 11A, 32100 Vinkovci';
        $settings['pickup_street'] = 'Lapovačka 11A';
        $settings['pickup_postal_code'] = '32100';
        $settings['pickup_city'] = 'Vinkovci';
        $settings['pickup_country_code'] = 'HR';
        $settings['pickup_opening_hours'] = 'Radnim danom 08:00–16:00';
        $settings['pickup_by_arrangement'] = true;
        $settings['configured_from'] = 'termol_webshop_requirements_2026_08';

        $values = [
            'name' => 'Osobno preuzimanje – Vinkovci',
            'carrier' => 'pickup',
            'service_type' => 'pickup',
            'pricing_type' => 'free',
            'geo_zone_id' => $croatiaZoneId ?? $pickup?->geo_zone_id,
            'description' => 'Preuzimanje na adresi Lapovačka 11A, 32100 Vinkovci, radnim danom 08:00–16:00 ili prema prethodnom dogovoru s korisničkom službom.',
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
            'sort_order' => (int) ($pickup?->sort_order ?? 82),
            'settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];

        if ($pickup) {
            DB::table('shipping_methods')->where('id', $pickup->id)->update($values);

            return;
        }

        DB::table('shipping_methods')->insert(array_merge($values, [
            'code' => 'pickup',
            // Keep a newly introduced option inactive until the administrator
            // reviews the checkout methods. Existing activation is preserved.
            'is_active' => false,
            'created_at' => $now,
        ]));
    }

    private function applyQuoteShippingLabels(): void
    {
        if (! Schema::hasTable('categories')) {
            return;
        }

        DB::table('categories')
            ->where('scope', 'catalog')
            ->whereIn('code', self::QUOTE_CATEGORY_CODES)
            ->orderBy('id')
            ->eachById(function (object $category): void {
                $payload = $this->decodeJson($category->payload ?? null);
                $currentLabels = (array) ($payload['shipping_labels'] ?? []);
                $labels = array_values(array_unique(array_merge(
                    $currentLabels,
                    ['quote_shipping']
                )));
                if ($labels === $currentLabels) {
                    return;
                }

                $payload['shipping_labels'] = $labels;

                DB::table('categories')->where('id', $category->id)->update([
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            });
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
};
