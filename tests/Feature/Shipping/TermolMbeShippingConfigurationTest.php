<?php

namespace Tests\Feature\Shipping;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Settings\SystemSettingsService;
use App\Services\Shipping\CroatianIslandDestinationClassifier;
use App\Services\Shipping\ShippingCalculator;
use Database\Seeders\SettingsLocalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TermolMbeShippingConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mbe_tariffs_are_active_with_destination_scopes_and_gapless_thousandth_kg_boundaries(): void
    {
        $mainland = ShippingMethod::query()->with('rates')->where('code', 'mbe_mainland_hr')->firstOrFail();
        $islands = ShippingMethod::query()->with('rates')->where('code', 'mbe_islands_hr')->firstOrFail();
        $pickup = ShippingMethod::query()->where('code', 'pickup')->firstOrFail();
        $missingWeightQuote = ShippingMethod::query()->where('code', 'mbe_missing_weight_quote_hr')->firstOrFail();

        $this->assertTrue($mainland->is_active);
        $this->assertTrue($islands->is_active);
        $this->assertSame('MBE Boxes – dostava na kopno', $mainland->name);
        $this->assertSame('MBE Boxes – dostava na otoke', $islands->name);
        $this->assertSame('mbe', $mainland->carrier);
        $this->assertSame('weight_tiers', $mainland->pricing_type);
        $this->assertSame('block', $mainland->missing_measurements_policy);
        $this->assertSame('hr_mainland', data_get($mainland->settings, 'destination_scope'));
        $this->assertSame('hr_islands', data_get($islands->settings, 'destination_scope'));
        $this->assertSame(12, $mainland->rates->count());
        $this->assertSame(12, $islands->rates->count());
        $this->assertTrue($pickup->is_active);
        $this->assertSame('pickup', $pickup->service_type);
        $this->assertSame('free', $pickup->pricing_type);
        $this->assertSame('Lapovačka 11A, 32100 Vinkovci', data_get($pickup->settings, 'pickup_address'));
        $this->assertSame('Radnim danom 08:00–16:00', data_get($pickup->settings, 'pickup_opening_hours'));
        $this->assertTrue($missingWeightQuote->is_active);
        $this->assertSame('quote', $missingWeightQuote->pricing_type);
        $this->assertTrue((bool) data_get($missingWeightQuote->settings, 'fallback_for_missing_weight'));
        $this->assertSame(
            ['hr_mainland', 'hr_islands'],
            data_get($missingWeightQuote->settings, 'destination_scopes'),
        );

        $this->assertGaplessRates($mainland);
        $this->assertGaplessRates($islands);
        $this->assertSame(
            config('termol_shipping.mbe.methods.mbe_mainland_hr.rates'),
            $this->serializedRates($mainland),
        );
        $this->assertSame(
            config('termol_shipping.mbe.methods.mbe_islands_hr.rates'),
            $this->serializedRates($islands),
        );

        $this->assertSame(6.0, $this->priceForWeight($mainland, 4.999));
        $this->assertSame(7.0, $this->priceForWeight($mainland, 5.000));
        $this->assertSame(7.0, $this->priceForWeight($mainland, 9.999));
        $this->assertSame(9.5, $this->priceForWeight($mainland, 10.000));
        $this->assertSame(100.0, $this->priceForWeight($mainland, 799.999));
        $this->assertSame(200.0, $this->priceForWeight($mainland, 800.000));

        $this->assertSame(10.0, $this->priceForWeight($islands, 4.999));
        $this->assertSame(12.0, $this->priceForWeight($islands, 5.000));
        $this->assertSame(12.0, $this->priceForWeight($islands, 19.999));
        $this->assertSame(15.0, $this->priceForWeight($islands, 20.000));
        $this->assertSame(120.0, $this->priceForWeight($islands, 499.999));
        $this->assertSame(150.0, $this->priceForWeight($islands, 500.000));
    }

    public function test_reapplying_configuration_labels_only_the_listed_catalog_categories(): void
    {
        $quoteCategory = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => '020203',
            'payload' => [
                'show_filters' => false,
                'shipping_labels' => ['fragile'],
            ],
        ]);
        $regularCategory = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => 'not-in-termol-list',
            'payload' => ['shipping_labels' => ['fragile']],
        ]);
        $sameCodeInAnotherScope = Category::query()->create([
            'scope' => Category::SCOPE_BLOG,
            'code' => '020203',
            'payload' => [],
        ]);

        $migration = require database_path('migrations/2026_08_30_082000_configure_termol_mbe_shipping.php');
        $rateIds = ShippingMethod::query()
            ->with('rates')
            ->whereIn('code', ['mbe_mainland_hr', 'mbe_islands_hr'])
            ->get()
            ->flatMap->rates
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $migration->up();
        $migration->up();

        $quoteCategory->refresh();
        $regularCategory->refresh();
        $sameCodeInAnotherScope->refresh();

        $this->assertFalse($quoteCategory->payload['show_filters']);
        $this->assertSame(['fragile', 'quote_shipping'], $quoteCategory->shippingLabels());
        $this->assertSame(['fragile'], $regularCategory->shippingLabels());
        $this->assertSame([], $sameCodeInAnotherScope->shippingLabels());
        $this->assertCount(42, config('termol_shipping.quote_shipping_category_codes'));
        $this->assertSame(
            24,
            ShippingMethod::query()
                ->whereIn('code', ['mbe_mainland_hr', 'mbe_islands_hr'])
                ->withCount('rates')
                ->get()
                ->sum('rates_count'),
        );
        $this->assertSame(
            $rateIds,
            ShippingMethod::query()
                ->with('rates')
                ->whereIn('code', ['mbe_mainland_hr', 'mbe_islands_hr'])
                ->get()
                ->flatMap->rates
                ->pluck('id')
                ->sort()
                ->values()
                ->all(),
        );

        $migration->down();
        $quoteCategory->refresh();

        $this->assertSame(['fragile', 'quote_shipping'], $quoteCategory->shippingLabels());
        $this->assertSame(
            0,
            ShippingMethod::query()
                ->whereIn('code', ['mbe_mainland_hr', 'mbe_islands_hr'])
                ->count(),
        );
    }

    public function test_quote_categories_keep_pickup_available_and_require_quote_for_delivery(): void
    {
        $category = new Category([
            'payload' => ['shipping_labels' => ['quote_shipping']],
        ]);
        $product = new Product([
            'code' => 'quote-shipping-product',
            'sku' => 'QUOTE-SHIPPING-PRODUCT',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 1,
            'weight_kg' => 20,
            'shipping_labels' => [],
        ]);
        $product->forceFill(['id' => 200]);
        $product->setRelation('packages', collect());
        $product->setRelation('categories', collect([$category]));
        $lines = collect([['product' => $product, 'quantity' => 1]]);
        $calculator = app(ShippingCalculator::class);

        $mainland = ShippingMethod::query()->with('rates')->where('code', 'mbe_mainland_hr')->firstOrFail();
        $quoteMethod = ShippingMethod::query()->with('rates')->where('code', 'shipping_quote')->firstOrFail();
        $pickup = ShippingMethod::query()->with('rates')->where('code', 'pickup')->firstOrFail();

        $this->assertNull($calculator->quote($mainland, $lines, 100));

        $quote = $calculator->quote($quoteMethod, $lines, 100);
        $this->assertNotNull($quote);
        $this->assertTrue($quote['requires_quote']);

        $pickupQuote = $calculator->quote($pickup, $lines, 100);
        $this->assertNotNull($pickupQuote);
        $this->assertSame('free', $pickupQuote['pricing_type']);
        $this->assertSame(0.0, $pickupQuote['price']);
    }

    public function test_missing_weight_quote_is_used_only_when_cart_weight_is_unknown(): void
    {
        $method = ShippingMethod::query()
            ->with('rates')
            ->where('code', 'mbe_missing_weight_quote_hr')
            ->firstOrFail();
        $product = new Product([
            'code' => 'missing-weight-product',
            'sku' => 'MISSING-WEIGHT',
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 1,
            'weight_kg' => null,
            'shipping_labels' => [],
        ]);
        $product->forceFill(['id' => 300]);
        $product->setRelation('packages', collect());
        $product->setRelation('categories', collect());
        $lines = collect([['product' => $product, 'quantity' => 1]]);

        $quote = app(ShippingCalculator::class)->quote($method, $lines, 100);

        $this->assertNotNull($quote);
        $this->assertTrue($quote['requires_quote']);

        $product->weight_kg = 2;
        $this->assertNull(app(ShippingCalculator::class)->quote($method, $lines, 100));

        $product->weight_kg = null;
        $product->shipping_labels = ['quote_shipping'];
        $this->assertNull(app(ShippingCalculator::class)->quote($method, $lines, 100));
    }

    public function test_activation_migration_localizes_methods_without_overwriting_boxnow_or_admin_policy(): void
    {
        ShippingMethod::query()->create([
            'code' => 'standard',
            'name' => 'Standard Shipping',
            'price' => 4.99,
            'is_active' => true,
            'sort_order' => 1,
        ]);
        ShippingMethod::query()->create([
            'code' => 'express',
            'name' => 'Express Shipping',
            'price' => 8.99,
            'is_active' => true,
            'sort_order' => 2,
        ]);
        $boxNow = ShippingMethod::query()->create([
            'code' => 'boxnow',
            'name' => 'BOX NOW Locker',
            'carrier' => 'boxnow',
            'service_type' => 'parcel_locker',
            'pricing_type' => 'flat',
            'price' => 3.77,
            'is_active' => true,
            'sort_order' => 3,
            'settings' => [
                'boxnow_partner_id' => 'partner-existing',
                'merchant_flag' => 'preserve-me',
            ],
        ]);
        $legacyBoxNow = ShippingMethod::query()->create([
            'code' => 'box_now',
            'name' => 'Duplicate legacy locker',
            'price' => 2.99,
            'is_active' => true,
            'sort_order' => 4,
            'settings' => [
                'boxnow_partner_id' => 'legacy-partner',
            ],
        ]);
        app(SystemSettingsService::class)->put(
            CroatianIslandDestinationClassifier::SETTING_KEY,
            CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS,
        );
        $rateIds = ShippingMethod::query()
            ->whereIn('code', ['mbe_mainland_hr', 'mbe_islands_hr'])
            ->with('rates')
            ->get()
            ->flatMap->rates
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $migration = require database_path('migrations/2026_08_30_083000_enable_hr_destination_shipping.php');
        $migration->up();
        $migration->up();

        $this->assertDatabaseHas('shipping_methods', [
            'code' => 'standard',
            'name' => 'Standardna dostava',
            'is_active' => false,
            'price' => 4.99,
        ]);
        $this->assertDatabaseHas('shipping_methods', [
            'code' => 'express',
            'name' => 'Ekspresna dostava',
            'is_active' => false,
            'price' => 8.99,
        ]);
        $this->assertSame('BOX NOW paketomat', $boxNow->refresh()->name);
        $this->assertTrue($boxNow->is_active);
        $this->assertSame('3.77', $boxNow->price);
        $this->assertSame('partner-existing', data_get($boxNow->settings, 'boxnow_partner_id'));
        $this->assertSame('preserve-me', data_get($boxNow->settings, 'merchant_flag'));
        $this->assertFalse($legacyBoxNow->refresh()->is_active);
        $this->assertSame(1, ShippingMethod::query()
            ->whereIn('code', ['boxnow', 'box_now'])
            ->where('is_active', true)
            ->count());
        $this->assertSame(
            CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS,
            app(SystemSettingsService::class)->get(CroatianIslandDestinationClassifier::SETTING_KEY),
        );
        $this->assertSame(
            $rateIds,
            ShippingMethod::query()
                ->whereIn('code', ['mbe_mainland_hr', 'mbe_islands_hr'])
                ->with('rates')
                ->get()
                ->flatMap->rates
                ->pluck('id')
                ->sort()
                ->values()
                ->all(),
        );
    }

    public function test_local_settings_seeder_creates_a_fully_configured_boxnow_method(): void
    {
        ShippingMethod::query()->whereIn('code', ['boxnow', 'box_now'])->delete();

        $this->seed(SettingsLocalSeeder::class);

        $boxNow = ShippingMethod::query()->where('code', 'boxnow')->firstOrFail();

        $this->assertSame('BOX NOW paketomat', $boxNow->name);
        $this->assertSame('boxnow', $boxNow->carrier);
        $this->assertSame('parcel_locker', $boxNow->service_type);
        $this->assertSame('flat', $boxNow->pricing_type);
        $this->assertSame('20.000', $boxNow->max_weight_kg);
        $this->assertSame('60.00', $boxNow->max_length_cm);
        $this->assertSame('45.00', $boxNow->max_width_cm);
        $this->assertSame('36.00', $boxNow->max_height_cm);
        $this->assertFalse($boxNow->allows_fragile);
        $this->assertFalse($boxNow->allows_oversized);
        $this->assertFalse($boxNow->allows_heavy);
        $this->assertSame('block', $boxNow->missing_measurements_policy);
        $this->assertFalse($boxNow->is_active);
        $this->assertSame('', data_get($boxNow->settings, 'boxnow_partner_id'));
    }

    public function test_local_settings_seeder_reuses_legacy_boxnow_alias_and_preserves_merchant_settings(): void
    {
        ShippingMethod::query()->whereIn('code', ['boxnow', 'box_now'])->delete();
        $legacy = ShippingMethod::query()->create([
            'code' => 'box_now',
            'name' => 'Legacy locker',
            'price' => 4.25,
            'is_active' => false,
            'sort_order' => 99,
            'settings' => [
                'boxnow_partner_id' => 'partner-existing',
                'merchant_flag' => 'preserve-me',
            ],
        ]);

        $this->seed(SettingsLocalSeeder::class);

        $this->assertDatabaseMissing('shipping_methods', ['code' => 'boxnow']);
        $this->assertSame('BOX NOW paketomat', $legacy->refresh()->name);
        $this->assertSame('boxnow', $legacy->carrier);
        $this->assertSame('parcel_locker', $legacy->service_type);
        $this->assertFalse($legacy->is_active);
        $this->assertSame('block', $legacy->missing_measurements_policy);
        $this->assertSame('partner-existing', data_get($legacy->settings, 'boxnow_partner_id'));
        $this->assertSame('preserve-me', data_get($legacy->settings, 'merchant_flag'));
    }

    public function test_local_settings_seeder_deactivates_a_duplicate_boxnow_alias(): void
    {
        ShippingMethod::query()->whereIn('code', ['boxnow', 'box_now'])->delete();
        $canonical = ShippingMethod::query()->create([
            'code' => 'boxnow',
            'name' => 'Canonical locker',
            'price' => 2.99,
            'is_active' => true,
            'sort_order' => 6,
            'settings' => ['boxnow_partner_id' => 'canonical-partner'],
        ]);
        $legacy = ShippingMethod::query()->create([
            'code' => 'box_now',
            'name' => 'Legacy duplicate locker',
            'price' => 2.99,
            'is_active' => true,
            'sort_order' => 7,
            'settings' => ['boxnow_partner_id' => 'legacy-partner'],
        ]);

        $this->seed(SettingsLocalSeeder::class);

        $this->assertTrue($canonical->refresh()->is_active);
        $this->assertFalse($legacy->refresh()->is_active);
        $this->assertSame('canonical-partner', data_get($canonical->settings, 'boxnow_partner_id'));
    }

    public function test_rollback_removes_only_methods_created_by_the_configuration(): void
    {
        $mainland = ShippingMethod::query()->where('code', 'mbe_mainland_hr')->firstOrFail();
        $settings = $mainland->settings;
        unset($settings['created_by_termol_requirements_migration']);
        $mainland->update(['settings' => $settings]);

        $category = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => '020203',
            'payload' => ['shipping_labels' => ['quote_shipping']],
        ]);
        $migration = require database_path('migrations/2026_08_30_082000_configure_termol_mbe_shipping.php');

        $migration->down();

        $this->assertDatabaseHas('shipping_methods', ['code' => 'mbe_mainland_hr']);
        $this->assertDatabaseMissing('shipping_methods', ['code' => 'mbe_islands_hr']);
        $this->assertDatabaseHas('shipping_methods', ['code' => 'pickup']);
        $this->assertSame(['quote_shipping'], $category->refresh()->shippingLabels());
    }

    private function assertGaplessRates(ShippingMethod $method): void
    {
        $rates = $method->rates->values();

        $this->assertSame(0.0, (float) $rates->first()->min_weight_kg);
        $this->assertNull($rates->last()->max_weight_kg);

        foreach ($rates->slice(0, -1) as $index => $rate) {
            $next = $rates->get($index + 1);

            $this->assertNotNull($rate->max_weight_kg);
            $this->assertSame(
                round((float) $rate->max_weight_kg + 0.001, 3),
                round((float) $next->min_weight_kg, 3),
            );
        }
    }

    private function priceForWeight(ShippingMethod $method, float $weight): float
    {
        $product = new Product([
            'code' => 'mbe-boundary-'.$weight,
            'sku' => 'MBE-'.$weight,
            'is_active' => true,
            'base_price' => 100,
            'stock_qty' => 1,
            'weight_kg' => $weight,
            'shipping_labels' => [],
        ]);
        $product->forceFill(['id' => 100]);
        $product->setRelation('packages', collect());
        $product->setRelation('categories', collect());

        $quote = app(ShippingCalculator::class)->quote(
            $method,
            collect([['product' => $product, 'quantity' => 1]]),
            100,
        );

        $this->assertNotNull($quote);

        return $quote['price'];
    }

    /** @return list<array{min_weight_kg:string,max_weight_kg:string|null,price:string}> */
    private function serializedRates(ShippingMethod $method): array
    {
        return $method->rates
            ->map(static fn ($rate): array => [
                'min_weight_kg' => $rate->min_weight_kg,
                'max_weight_kg' => $rate->max_weight_kg,
                'price' => $rate->price,
            ])
            ->all();
    }
}
