<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\System\SystemSetting;
use App\Services\Import\HerreraClientDefaults;
use App\Services\Integrations\Stock\FixedSupplierAvailabilityService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraClientDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_another_store_gets_neither_stock_defaults_nor_legacy_repairs_or_a_marker(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Another shop');
        $brand = $this->manufacturer('Nopallux');
        $product = $this->product($brand, ['minimum_order_quantity' => 4, 'payload' => ['opencart' => ['minimum' => 4]]]);

        $this->assertFalse(app(HerreraClientDefaults::class)->apply()['applied']);
        $this->assertSame(3, $product->fresh()->supplier_stock_qty);
        $this->assertSame(1, $product->fresh()->order_quantity_step);
        $this->assertFalse(SystemSetting::query()->where('key', HerreraClientDefaults::APPLIED_KEY)->exists());
    }

    public function test_only_exact_normalized_confirmed_translation_names_receive_supplier_stock_and_future_policy(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera d.o.o.');
        $confirmed = collect(['NoPaLlUx', 'as–Schwabe', 'KNAUF'])->map(fn ($name) => $this->manufacturer($name));
        $excluded = collect(['Knauf Insulation', 'Nopallux d.o.o.', 'Unrelated Brand'])->map(fn ($name) => $this->manufacturer($name));
        $excluded->last()->update(['code' => 'nopallux']);
        $products = $confirmed->merge($excluded)->map(fn ($brand) => $this->product($brand));

        $result = app(HerreraClientDefaults::class)->apply();
        $this->assertEqualsCanonicalizing($confirmed->pluck('id')->all(), $result['manufacturer_ids']);
        foreach ($products as $product) {
            $isConfirmed = $confirmed->contains('id', $product->manufacturer_id);
            $this->assertSame($isConfirmed ? 100000 : 3, $product->fresh()->supplier_stock_qty);
            $this->assertSame(7, $product->fresh()->stock_qty);
        }
        foreach ($confirmed as $brand) {
            $this->assertSame('fixed', data_get($brand->fresh()->payload, 'supplier_availability.mode'));
            $future = $this->product($brand, ['stock_qty' => 22]);
            $this->assertSame(100000, $future->fresh()->supplier_stock_qty);
            $this->assertSame(22, $future->fresh()->stock_qty);
        }
    }

    public function test_preconfigured_manufacturers_and_any_existing_fixed_product_preserve_manual_csv_state(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $brandPolicy = $this->manufacturer('Nopallux', ['supplier_availability' => ['mode' => 'fixed', 'quantity' => 13]]);
        $manual = $this->product($brandPolicy);
        $manual->updateQuietly(['supplier_stock_qty' => 9]);
        $partialPolicy = $this->manufacturer('Knauf');
        $fixed = $this->product($partialPolicy, ['supplier_stock_qty' => 27, 'payload' => ['supplier_availability' => ['mode' => 'fixed', 'source' => 'manual_csv', 'quantity' => 27]]]);
        $sibling = $this->product($partialPolicy);

        $result = app(HerreraClientDefaults::class)->apply();
        $this->assertSame([], $result['manufacturer_ids']);
        $this->assertEqualsCanonicalizing([$brandPolicy->id, $partialPolicy->id], $result['preserved_manufacturer_ids']);
        $this->assertSame(9, $manual->fresh()->supplier_stock_qty);
        $this->assertSame(13, data_get($brandPolicy->fresh()->payload, 'supplier_availability.quantity'));
        $this->assertSame(27, $fixed->fresh()->supplier_stock_qty);
        $this->assertSame('manual_csv', data_get($fixed->fresh()->payload, 'supplier_availability.source'));
        $this->assertSame(3, $sibling->fresh()->supplier_stock_qty);
        $this->assertNull(data_get($partialPolicy->fresh()->payload, 'supplier_availability.mode'));
    }

    public function test_quantity_repair_is_conservative_and_replaying_the_migration_preserves_later_manual_edits(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $brand = $this->manufacturer('as-Schwabe');
        $repair = $this->product($brand, ['minimum_order_quantity' => 4, 'payload' => ['opencart' => ['minimum' => 4]]]);
        $manual = $this->product(null, ['minimum_order_quantity' => 3, 'order_quantity_step' => 3, 'payload' => ['opencart' => ['minimum' => 4]]]);
        $explicit = $this->product(null, ['minimum_order_quantity' => 4, 'payload' => ['opencart' => ['minimum' => 4], 'ordering_quantities' => ['manual_override' => true]]]);
        $native = $this->product(null);
        $migration = require database_path('migrations/2026_11_09_122000_apply_herrera_client_defaults.php');
        $migration->up();
        $this->assertSame(4, $repair->fresh()->minimum_order_quantity);
        $this->assertSame(4, $repair->fresh()->order_quantity_step);
        $this->assertSame(3, $manual->fresh()->order_quantity_step);
        $this->assertSame(1, $explicit->fresh()->order_quantity_step);
        $this->assertSame(1, $native->fresh()->minimum_order_quantity);

        $file = tempnam(sys_get_temp_dir(), 'herrera-defaults-');
        try {
            file_put_contents($file, "sku,quantity\n".$repair->sku.",12\n");
            app(FixedSupplierAvailabilityService::class)->importCsv($file, true);
        } finally {
            unlink($file);
        }
        $brand->refresh()->update(['payload' => ['supplier_availability' => ['mode' => 'fixed', 'quantity' => 77]]]);
        $repair->refresh()->update(['minimum_order_quantity' => 2, 'order_quantity_step' => 2]);
        $migration->down();
        $migration->up();
        $this->assertSame(12, $repair->fresh()->supplier_stock_qty);
        $this->assertSame('manual_csv', data_get($repair->fresh()->payload, 'supplier_availability.source'));
        $this->assertSame(7, $repair->fresh()->stock_qty);
        $this->assertSame(2, $repair->fresh()->order_quantity_step);
        $this->assertSame(77, data_get($brand->fresh()->payload, 'supplier_availability.quantity'));
        $this->assertSame(77, $this->product($brand)->fresh()->supplier_stock_qty);
        $this->assertSame('already_applied', app(HerreraClientDefaults::class)->apply()['reason']);
    }

    public function test_failed_stock_configuration_rolls_back_the_repairs_and_application_marker(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        $ambiguous = $this->manufacturer('Nopallux');
        $ambiguous->update(['code' => 'videx']);
        $repair = $this->product($ambiguous, ['minimum_order_quantity' => 4, 'payload' => ['opencart' => ['minimum' => 4]]]);
        try {
            app(HerreraClientDefaults::class)->apply();
            $this->fail('A manufacturer identified as XML-synchronized must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('XML', $exception->getMessage());
        }
        $this->assertSame(1, $repair->fresh()->order_quantity_step);
        $this->assertSame(3, $repair->fresh()->supplier_stock_qty);
        $this->assertFalse(SystemSetting::query()->where('key', HerreraClientDefaults::APPLIED_KEY)->exists());
    }

    private function manufacturer(string $name, array $payload = []): Manufacturer
    {
        $brand = Manufacturer::query()->create(['code' => uniqid('manufacturer-'), 'payload' => $payload]);
        $brand->translations()->create(['locale' => 'hr', 'name' => $name, 'slug' => 'manufacturer-'.$brand->id]);

        return $brand;
    }

    private function product(?Manufacturer $brand, array $attributes = []): Product
    {
        $code = uniqid('defaults-');

        return Product::query()->create($attributes + ['code' => $code, 'sku' => $code, 'manufacturer_id' => $brand?->id, 'stock_qty' => 7, 'supplier_stock_qty' => 3, 'minimum_order_quantity' => 1, 'order_quantity_step' => 1]);
    }
}
