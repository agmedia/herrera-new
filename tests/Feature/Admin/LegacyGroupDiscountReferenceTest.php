<?php

namespace Tests\Feature\Admin;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Pricing\LegacyGroupDiscountReference;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Product\Product;
use App\Models\User\CustomerGroup;
use App\Services\Import\HerreraLegacyPriceRuleArchiveService;
use App\Services\Pricing\LegacyGroupDiscountReferenceService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LegacyGroupDiscountReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        DB::purge('legacy_rule_test_source');
        parent::tearDown();
    }

    public function test_archive_is_idempotent_read_only_for_source_and_all_prices_and_preserves_full_definition_scope(): void
    {
        [$catalog, $group, $other, $brand, $category, $product] = $this->fixture();
        $service = app(HerreraLegacyPriceRuleArchiveService::class);
        $before = $catalog->entries()->get()->toArray();
        $sourceBefore = DB::connection('legacy_rule_test_source')->table('oc_mega_sales')->get()->map(fn ($row) => (array) $row)->all();
        $first = $service->archive('legacy_rule_test_source');
        $this->assertSame(2, $first['new_references']);
        $this->assertSame(1, $first['supported_references']);
        $this->assertSame(0, $service->archive('legacy_rule_test_source')['new_references']);
        $this->assertSame($before, $catalog->entries()->get()->toArray());
        $this->assertSame($sourceBefore, DB::connection('legacy_rule_test_source')->table('oc_mega_sales')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(0, $catalog->discountRules()->count());
        $this->assertNull(DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
        $reference = LegacyGroupDiscountReference::query()->where('source_sale_id', 362)->sole();
        $this->assertSame([$group->id, $other->id], $reference->customer_group_ids);
        $this->assertSame([$brand->id], $reference->manufacturer_ids);
        $this->assertSame([$category->id], $reference->category_ids);
        $this->assertSame([$product->id], $reference->excluded_product_ids);
        $this->assertSame('40.0000', $reference->percent);
        $this->assertSame(362, $reference->definition['sale']['id']);
        $this->assertSame('68', $reference->definition['manufacturer_ids'][0]['manufacturer_id']);
        $this->assertTrue($reference->is_supported);
    }

    public function test_prefill_returns_all_original_groups_and_scopes_without_creating_any_executable_price_rule(): void
    {
        [$catalog, $group, $other, $brand, $category, $product] = $this->fixture();
        app(HerreraLegacyPriceRuleArchiveService::class)->archive('legacy_rule_test_source');
        $service = app(LegacyGroupDiscountReferenceService::class);
        $references = $service->forGroup($catalog, $group->id);
        $this->assertCount(2, $references);
        $reference = $references->firstWhere('source_sale_id', 362);
        $before = $catalog->entries()->count();
        $data = $service->draftData($catalog, $reference->id);
        $this->assertSame([$group->id, $other->id], $data['form']['customer_group_ids']);
        $this->assertSame([$brand->id], $data['form']['manufacturer_ids']);
        $this->assertSame([$category->id], $data['form']['category_ids']);
        $this->assertSame([$product->id], $data['form']['excluded_product_ids']);
        $this->assertSame('2026-08-10T00:00', $data['form']['starts_at']);
        $this->assertSame('2026-12-31T00:00', $data['form']['ends_at']);
        $this->assertTrue($data['form']['include_descendants']);
        $this->assertNotEmpty($data['warnings']);
        $this->assertSame(0, $catalog->discountRules()->count());
        $this->assertSame($before, $catalog->entries()->count());
    }

    public function test_saved_standard_group_template_keeps_brand_scope_and_is_not_misrepresented_as_an_executable_universal_discount(): void
    {
        [$catalog, $group, , $brand] = $this->fixture();
        app(HerreraLegacyPriceRuleArchiveService::class)->archive('legacy_rule_test_source');
        $reference = app(LegacyGroupDiscountReferenceService::class)->forGroup($catalog, $group->id)->firstWhere('source_type', 'cigroup_template');
        $this->assertSame('20.0000', $reference->percent);
        $this->assertSame([$brand->id], $reference->manufacturer_ids);
        $this->assertFalse($reference->is_supported);
        $this->assertFalse($reference->is_active);
        $this->assertStringContainsString('GROUP', implode(' ', $reference->warnings));
        $this->expectException(ValidationException::class);
        app(LegacyGroupDiscountReferenceService::class)->draftData($catalog, $reference->id);
    }

    public function test_rounding_destructive_removal_filters_and_unmapped_scope_disable_automatic_prefill(): void
    {
        [$catalog] = $this->fixture();
        $source = DB::connection('legacy_rule_test_source');
        foreach ([363 => ['round_prices' => 1], 364 => ['remove_individual_specials' => 1], 365 => [], 366 => []] as $id => $extra) {
            $sale = (array) $source->table('oc_mega_sales')->where('id', 362)->first();
            $source->table('oc_mega_sales')->insert(array_replace($sale, ['id' => $id], $extra));
            $source->table('oc_mega_customer_group_to_sale')->insert(['sale_id' => (string) $id, 'customer_group_id' => '10']);
        }
        $source->table('oc_mega_filter_to_sale')->insert(['sale_id' => '365', 'filter_id' => '99']);
        $source->table('oc_mega_manufacturer_to_sale')->insert(['sale_id' => '366', 'manufacturer_id' => '999']);
        $result = app(HerreraLegacyPriceRuleArchiveService::class)->archive('legacy_rule_test_source');
        $this->assertSame(1, $result['supported_references']);
        foreach ([363 => '0,05', 364 => 'remove_individual_specials=1', 365 => 'filtre', 366 => '999'] as $id => $warning) {
            $reference = LegacyGroupDiscountReference::query()->where('source_sale_id', $id)->sole();
            $this->assertFalse($reference->is_supported);
            $this->assertStringContainsString($warning, implode(' ', $reference->warnings));
            try {
                app(LegacyGroupDiscountReferenceService::class)->draftData($catalog, $reference->id);
                $this->fail('Unsupported conditions must not silently disappear from a new rule.');
            } catch (ValidationException) {
                $this->assertSame(0, $catalog->discountRules()->count());
            }
        }
    }

    public function test_references_only_belong_to_matching_source_snapshot_and_verified_checksum_and_are_immutable(): void
    {
        [$catalog, $group] = $this->fixture();
        app(HerreraLegacyPriceRuleArchiveService::class)->archive('legacy_rule_test_source');
        $reference = LegacyGroupDiscountReference::query()->where('source_sale_id', 362)->sole();
        foreach ([['source_snapshot' => 'unrelated'], ['source_checksum' => str_repeat('b', 64)], ['source_system' => 'unrelated']] as $extra) {
            $unrelated = $catalog->replicate();
            $unrelated->fill($extra)->save();
            $this->assertCount(0, app(LegacyGroupDiscountReferenceService::class)->forGroup($unrelated, $group->id));
            try {
                app(LegacyGroupDiscountReferenceService::class)->draftData($unrelated, $reference->id);
                $this->fail('Unrelated catalog must never inherit old definitions.');
            } catch (ModelNotFoundException) {
                $this->assertSame('40.0000', $reference->fresh()->percent);
            }
        }
        $this->expectException(ValidationException::class);
        $reference->update(['percent' => 99]);
    }

    public function test_changed_snapshot_definition_and_same_database_guard_leave_existing_references_and_prices_unchanged(): void
    {
        [$catalog] = $this->fixture();
        $archive = app(HerreraLegacyPriceRuleArchiveService::class);
        $archive->archive('legacy_rule_test_source');
        DB::connection('legacy_rule_test_source')->table('oc_mega_sales')->where('id', 362)->update(['discount_value' => 50]);
        try {
            $archive->archive('legacy_rule_test_source');
            $this->fail('The historical source snapshot cannot change beneath a preserved reference.');
        } catch (\RuntimeException) {
            $this->assertSame('40.0000', LegacyGroupDiscountReference::query()->where('source_sale_id', 362)->sole()->percent);
            $this->assertSame('12.2880', $catalog->entries()->sole()->price);
        }
        $this->expectException(\RuntimeException::class);
        $archive->archive(config('database.default'));
    }

    private function fixture(): array
    {
        config(['database.connections.legacy_rule_test_source' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('legacy_rule_test_source');
        $schema = Schema::connection('legacy_rule_test_source');
        $schema->create('oc_mega_sales', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('date_start');
            $table->string('date_end');
            $table->integer('discount_value');
            $table->string('discount_type');
            $table->integer('exclude_child');
            $table->integer('remove_individual_specials');
            $table->integer('priority');
            $table->integer('round_prices');
        });
        foreach (['mega_customer_group_to_sale' => 'customer_group_id', 'mega_category_to_sale' => 'category_id', 'mega_manufacturer_to_sale' => 'manufacturer_id', 'mega_exclude_products' => 'product_id', 'mega_filter_to_sale' => 'filter_id'] as $name => $column) {
            $schema->create('oc_'.$name, function (Blueprint $table) use ($column): void {
                $table->id();
                $table->string('sale_id');
                $table->string($column);
            });
        }
        $schema->create('oc_cigroupprice_template', function (Blueprint $table): void {
            $table->integer('template_id')->primary();
            $table->string('name');
            $table->string('type');
            $table->text('setting');
        });
        $group = CustomerGroup::query()->create(['code' => 'b2b20', 'name' => 'B2B20', 'is_active' => true]);
        $other = CustomerGroup::query()->create(['code' => 'b2b30', 'name' => 'B2B30', 'is_active' => true]);
        $brand = Manufacturer::query()->create(['code' => 'braytron', 'is_active' => true]);
        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'measuring', 'is_active' => true]);
        $product = Product::query()->create(['code' => 'reference', 'sku' => 'reference', 'base_price' => '20.4800', 'is_active' => true]);
        foreach ([['customer_group', 10, $group->id], ['customer_group', 11, $other->id], ['manufacturer', 68, $brand->id], ['category', 943, $category->id], ['product', 100, $product->id]] as [$entity, $sourceId, $targetId]) {
            DB::table('herrera_import_maps')->insert(['source' => 'herrera-opencart', 'entity' => $entity, 'source_id' => (string) $sourceId, 'target_id' => $targetId, 'checksum' => str_repeat('a', 64)]);
        }
        $catalog = PriceCatalog::query()->create(['name' => 'Original verified source', 'status' => 'draft', 'source_system' => 'herrera-opencart', 'source_snapshot' => ':memory:', 'source_checksum' => str_repeat('a', 64), 'metadata' => ['import_complete' => true]]);
        $catalog->entries()->create(['source_key' => 'original', 'product_id' => $product->id, 'kind' => 'group', 'customer_group_id' => $group->id, 'price' => '12.2880']);
        $source = DB::connection('legacy_rule_test_source');
        $source->table('oc_mega_sales')->insert(['id' => 362, 'date_start' => '2026-08-10', 'date_end' => '2026-12-31', 'discount_value' => 40, 'discount_type' => 'percent', 'exclude_child' => 0, 'remove_individual_specials' => 0, 'priority' => 0, 'round_prices' => 0]);
        foreach ([10, 11] as $id) {
            $source->table('oc_mega_customer_group_to_sale')->insert(['sale_id' => '362', 'customer_group_id' => (string) $id]);
        }
        $source->table('oc_mega_category_to_sale')->insert(['sale_id' => '362', 'category_id' => '943']);
        $source->table('oc_mega_manufacturer_to_sale')->insert(['sale_id' => '362', 'manufacturer_id' => '68']);
        $source->table('oc_mega_exclude_products')->insert(['sale_id' => '362', 'product_id' => '100']);
        $source->table('oc_cigroupprice_template')->insert(['template_id' => 1, 'name' => 'Svi defaultni', 'type' => 'product', 'setting' => json_encode(['customer_group_price' => ['10' => ['status' => '1', 'basedon' => '1', 'type' => 'P', 'value' => '20', 'action' => '-']], 'filter_type' => 'custom_manufacturer', 'manufacturers' => ['68']])]);

        return [$catalog, $group, $other, $brand, $category, $product];
    }
}
