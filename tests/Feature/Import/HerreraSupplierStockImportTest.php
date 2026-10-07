<?php

namespace Tests\Feature\Import;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Services\Import\HerreraOpenCartImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HerreraSupplierStockImportTest extends TestCase
{
    use RefreshDatabase;

    private string $sourceFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourceFile = tempnam(sys_get_temp_dir(), 'herrera-supplier-test-');
        config(['database.connections.supplier_test_source' => [
            'driver' => 'sqlite', 'database' => $this->sourceFile, 'prefix' => '', 'foreign_key_constraints' => true,
        ]]);
        DB::purge('supplier_test_source');
        Schema::connection('supplier_test_source')->create('oc_product', function (Blueprint $table): void {
            $table->unsignedInteger('product_id')->primary();
            $table->integer('suplierqty')->default(0);
            $table->integer('quantity')->default(0);
            foreach (['model', 'sku', 'ean', 'upc', 'manufacturer_id', 'tax_class_id', 'status', 'price', 'minimum', 'weight', 'length', 'width', 'height', 'image'] as $field) {
                $table->text($field)->nullable();
            }
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('supplier_test_source');
        unlink($this->sourceFile);
        parent::tearDown();
    }

    public function test_initial_catalog_import_maps_supplier_quantity_without_combining_local_stock(): void
    {
        DB::connection('supplier_test_source')->table('oc_product')->insert($this->sourceProduct(30, 493, 0));
        app(HerreraOpenCartImportService::class)->import('supplier_test_source', 'oc_', false, ['catalog']);
        $product = Product::query()->sole();
        $this->assertSame(0, $product->stock_qty);
        $this->assertSame(493, $product->supplier_stock_qty);
        $this->assertSame(493, $product->availableStockQuantity());
        $this->assertTrue($product->storefrontIsPurchasable());
        $this->assertSame(493, $product->payload['opencart']['suplierqty']);
        $this->assertSame(1, Product::query()->visibleOnStorefront(true)->count());
    }

    public function test_explicit_backfill_changes_only_new_field_and_is_idempotent(): void
    {
        $row = $this->sourceProduct(30, 493, 0);
        DB::connection('supplier_test_source')->table('oc_product')->insert($row);
        $product = $this->mappedProduct($row, ['stock_qty' => 777, 'base_price' => '20.4800', 'is_active' => false]);
        $before = $product->fresh()->getAttributes();
        $mapBefore = DB::table('herrera_import_maps')->get()->map(fn ($row) => (array) $row)->all();
        $sourceBefore = DB::connection('supplier_test_source')->table('oc_product')->get()->map(fn ($row) => (array) $row)->all();

        $importer = app(HerreraOpenCartImportService::class);
        $first = $importer->import('supplier_test_source', 'oc_', false, ['supplier_stock']);
        $after = $product->fresh()->getAttributes();
        $this->assertSame(493, $after['supplier_stock_qty']);
        unset($before['supplier_stock_qty'], $after['supplier_stock_qty']);
        $this->assertSame($before, $after);
        $this->assertSame(1, $first['supplier_stock_repaired']);
        $this->assertSame(0, $first['supplier_stock_source_mismatch']);
        $second = $importer->import('supplier_test_source', 'oc_', false, ['supplier_stock']);
        $this->assertSame(0, $second['supplier_stock_repaired']);
        $this->assertSame(1, $second['supplier_stock_unchanged']);
        $this->assertSame($mapBefore, DB::table('herrera_import_maps')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($sourceBefore, DB::connection('supplier_test_source')->table('oc_product')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertDatabaseCount('catalog_price_entries', 0);
        $this->assertDatabaseCount('catalog_price_catalogs', 0);
        $product->fresh()->update(['supplier_stock_qty' => 0]);
        $third = $importer->import('supplier_test_source', 'oc_', false, ['supplier_stock']);
        $this->assertSame(0, $product->fresh()->supplier_stock_qty);
        $this->assertSame(0, $third['supplier_stock_repaired']);
        $this->assertSame(1, $third['supplier_stock_local_preserved']);
    }

    public function test_repair_never_adds_unmapped_products_and_preserves_local_supplier_edits(): void
    {
        $rows = [$this->sourceProduct(30, 493), $this->sourceProduct(31, 666), $this->sourceProduct(32, 1000), $this->sourceProduct(33, 500)];
        DB::connection('supplier_test_source')->table('oc_product')->insert($rows);
        $local = $this->mappedProduct($rows[0], ['supplier_stock_qty' => 55]);
        $mismatch = $this->mappedProduct($rows[1], ['payload' => ['opencart' => $rows[1] + []]]);
        $mismatch->update(['payload' => ['opencart' => array_replace($rows[1], ['suplierqty' => 1])]]);
        $wrongIdentity = $this->mappedProduct($rows[3], ['payload' => ['opencart' => array_replace($rows[3], ['product_id' => 999])]]);
        $summary = app(HerreraOpenCartImportService::class)->import('supplier_test_source', 'oc_', false, ['supplier_stock']);
        $this->assertSame(55, $local->fresh()->supplier_stock_qty);
        $this->assertSame(0, $mismatch->fresh()->supplier_stock_qty);
        $this->assertSame(0, $wrongIdentity->fresh()->supplier_stock_qty);
        $this->assertSame(1, $summary['supplier_stock_local_preserved']);
        $this->assertSame(1, $summary['supplier_stock_unmapped']);
        $this->assertSame(2, $summary['supplier_stock_source_mismatch']);
        $this->assertDatabaseCount('products', 3);
    }

    public function test_repair_uses_keyset_chunks_and_one_product_update_per_chunk(): void
    {
        $rows = array_map(fn (int $id) => $this->sourceProduct($id, 500), range(1000, 1500));
        DB::connection('supplier_test_source')->table('oc_product')->insert($rows);
        foreach ($rows as $row) {
            $this->mappedProduct($row);
        }
        $updates = [];
        DB::listen(function ($event) use (&$updates): void {
            if (str_starts_with(strtolower($event->sql), 'update products set supplier_stock_qty')) {
                $updates[] = $event->sql;
            }
        });
        $summary = app(HerreraOpenCartImportService::class)->import('supplier_test_source', 'oc_', false, ['supplier_stock']);
        $this->assertSame(501, $summary['supplier_stock_checked']);
        $this->assertSame(501, $summary['supplier_stock_repaired']);
        $this->assertSame(501, Product::query()->where('supplier_stock_qty', 500)->count());
        $this->assertCount(2, $updates);
    }

    public function test_repair_dry_run_is_non_mutating_and_cannot_be_combined_with_other_stages(): void
    {
        $row = $this->sourceProduct(30, 493);
        DB::connection('supplier_test_source')->table('oc_product')->insert($row);
        $product = $this->mappedProduct($row);
        $before = $product->fresh()->getAttributes();
        $importer = app(HerreraOpenCartImportService::class);
        $importer->import('supplier_test_source', 'oc_', true, ['supplier_stock']);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertDatabaseCount('herrera_import_runs', 0);
        try {
            $importer->import('supplier_test_source', 'oc_', false, ['supplier_stock', 'catalog']);
            $this->fail('Repair stages cannot be combined.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('separate explicit stage', $exception->getMessage());
            $this->assertDatabaseCount('herrera_import_runs', 0);
        }
        $this->expectException(\RuntimeException::class);
        $importer->import(config('database.default'), 'oc_', true, ['supplier_stock']);
    }

    public function test_repair_requires_original_supplier_column_before_any_write(): void
    {
        Schema::connection('supplier_test_source')->table('oc_product', fn (Blueprint $table) => $table->dropColumn('suplierqty'));
        try {
            app(HerreraOpenCartImportService::class)->import('supplier_test_source', 'oc_', false, ['supplier_stock']);
            $this->fail('Missing source quantity must never become an invented zero.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('source quantity is missing', $exception->getMessage());
            $this->assertDatabaseCount('herrera_import_runs', 0);
            $this->assertDatabaseCount('products', 0);
        }
    }

    public function test_negative_stock_is_preserved_but_never_offsets_available_positive_stock(): void
    {
        $product = $this->product('negative-local', -5, 8);
        $this->assertSame(8, $product->availableStockQuantity());
        $this->assertTrue($product->storefrontIsPurchasable());
        $product = $this->product('negative-supplier', 3, -10);
        $this->assertSame(3, $product->availableStockQuantity());
        $native = Product::query()->create(['code' => 'native-default', 'stock_qty' => 0, 'is_active' => true]);
        $this->assertSame(0, $native->fresh()->supplier_stock_qty);
        $this->assertSame(0, $native->availableStockQuantity());
        $this->assertFalse($native->storefrontIsPurchasable());
    }

    public function test_stock_visibility_includes_supplier_but_never_inactive_products(): void
    {
        $local = $this->product('local', 2, 0);
        $supplier = $this->product('supplier', 0, 493);
        $mixed = $this->product('mixed', 1, 4);
        $this->product('unavailable', 0, 0);
        $this->product('negative', -1, -1);
        $inactive = $this->product('inactive', 0, 500);
        $inactive->update(['is_active' => false]);
        $this->assertEqualsCanonicalizing([$local->id, $supplier->id, $mixed->id], Product::query()->visibleOnStorefront(true)->pluck('id')->all());
        $this->assertSame(5, Product::query()->visibleOnStorefront(false)->count());
    }

    public function test_visible_variants_still_require_their_own_stock_even_with_product_supplier_stock(): void
    {
        $product = $this->product('variant', 0, 1000);
        $option = Option::query()->create(['code' => 'size', 'type' => 'select', 'is_active' => true]);
        $value = $option->values()->create(['code' => 'large', 'is_active' => true]);
        $variant = ProductOptionValue::query()->create([
            'product_id' => $product->id, 'option_value_id' => $value->id,
            'stock_qty' => 0, 'is_active' => true, 'mode' => 'single', 'combination_hash' => hash('sha256', 'variant'),
        ]);
        $this->assertFalse($product->storefrontIsPurchasable());
        $variant->update(['stock_qty' => 2]);
        $this->assertTrue($product->fresh()->storefrontIsPurchasable());
        $product->update(['supplier_stock_qty' => 0]);
        $this->assertSame(1, Product::query()->visibleOnStorefront(true)->count());
    }

    public function test_storefront_energy_eager_load_only_fetches_exact_imported_energy_specifications(): void
    {
        $product = $this->product('energy', 1, 0);
        foreach ([
            ['herrera-opencart', 'Razina energetske učinkovitosti', ['F']],
            ['herrera-opencart', 'Klasa energetske učinkovitosti EEi', ['A+']],
            ['herrera-opencart', 'Materijal', ['Metal']],
            ['msan', 'Razina energetske učinkovitosti', ['A']],
        ] as [$source, $name, $values]) {
            $product->technicalSpecificationRows()->create([
                'source' => $source, 'source_key' => hash('sha256', $source.$name),
                'group_name' => 'Specifikacije', 'item_name' => $name, 'values' => $values,
            ]);
        }
        $loaded = Product::query()->withStorefrontEnergyData()->findOrFail($product->id);
        $this->assertTrue($loaded->relationLoaded('technicalSpecificationRows'));
        $this->assertSame(2, $loaded->technicalSpecificationRows->count());
        $this->assertSame([['F'], ['A+']], $loaded->technicalSpecificationRows->pluck('values')->all());
        $this->assertDatabaseCount('catalog_product_specifications', 4);
    }

    private function sourceProduct(int $id, int $supplier, int $local = 0): array
    {
        return ['product_id' => $id, 'model' => 'SKU-'.$id, 'sku' => 'SKU-'.$id, 'ean' => '', 'upc' => '',
            'manufacturer_id' => 0, 'tax_class_id' => 0, 'status' => 1, 'price' => '20.4800',
            'quantity' => $local, 'suplierqty' => $supplier, 'minimum' => 1,
            'weight' => '1', 'length' => '0', 'width' => '0', 'height' => '0', 'image' => 'catalog/main.jpg'];
    }

    private function mappedProduct(array $source, array $overrides = []): Product
    {
        $product = Product::query()->create(array_replace([
            'code' => 'herrera-oc-product-'.$source['product_id'], 'sku' => $source['sku'],
            'stock_qty' => $source['quantity'], 'supplier_stock_qty' => 0,
            'base_price' => $source['price'], 'is_active' => true, 'payload' => ['opencart' => $source],
        ], $overrides));
        DB::table('herrera_import_maps')->insert([
            'source' => 'herrera-opencart', 'entity' => 'product', 'source_id' => (string) $source['product_id'],
            'target_id' => $product->id, 'checksum' => hash('sha256', json_encode($source)),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $product;
    }

    private function product(string $code, int $local, int $supplier): Product
    {
        return Product::query()->create(['code' => $code, 'stock_qty' => $local, 'supplier_stock_qty' => $supplier, 'is_active' => true]);
    }
}
