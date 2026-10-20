<?php

namespace Tests\Feature\Pricing;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Pricing\LegacyGroupDiscountReference;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Pricing\CustomerDiscountService;
use App\Services\Pricing\PriceCatalogGroupOverview;
use App\Services\Pricing\PriceCatalogQuery;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class LegacyCatalogDiscountPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_original_category_and_brand_formula_follows_new_membership_using_snapshot_base_and_cart_prices(): void
    {
        [$catalog, $group, $buyer] = $this->fixture();
        $brand = Manufacturer::query()->create(['code' => 'brand', 'is_active' => true]);
        $parent = $this->category('parent');
        $child = $this->category('child', $parent->id);
        $existing = $this->product(['manufacturer_id' => $brand->id]);
        $new = $this->product(['manufacturer_id' => $brand->id]);
        $wrong = $this->product();
        $excluded = $this->product(['manufacturer_id' => $brand->id]);
        $this->entry($catalog, $existing, PriceCatalogEntry::BASE, '200');
        $this->entry($catalog, $existing, PriceCatalogEntry::GROUP, '100', ['customer_group_id' => $group->id]);
        $existing->update(['base_price' => '900']);
        $reference = $this->reference($catalog, $group, ['manufacturer_ids' => [$brand->id], 'category_ids' => [$parent->id], 'excluded_product_ids' => [$excluded->id]]);
        foreach ([$existing, $new, $wrong, $excluded] as $product) {
            $product->categories()->attach($child);
        }
        $this->assertPrices($buyer, [$existing->id => 80.0, $new->id => 40.0, $wrong->id => 50.0, $excluded->id => 50.0]);
        $resolved = app(PriceCatalogResolver::class)->resolve($new, $buyer);
        $this->assertSame('price_catalog_legacy_category_discount', $resolved->source_type);
        $this->assertSame($reference->id, $resolved->rule_id);
        $this->assertSame(50.0, $resolved->previous_price);
        $this->actingAs($buyer);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($new, 3));
        $this->assertSame(40.0, $cart->lines()->sole()['unit_price']);
        $this->assertSame(120.0, $cart->lines()->sole()['line_total']);
        $this->assertSame('price_catalog_legacy_category_discount', $cart->lines()->sole()['b2b_source_type']);
        $new->categories()->detach();
        $existing->categories()->detach();
        app(PriceCatalogResolver::class)->forgetActiveCatalog();
        $this->assertPrices($buyer, [$existing->id => 100.0, $new->id => 50.0]);
        $this->assertSame(50.0, $cart->lines()->sole()['unit_price']);
    }

    public function test_original_rounding_exclusive_dates_and_priority_discount_ties_match_sql_and_batch(): void
    {
        [$catalog, $group, $buyer] = $this->fixture();
        $product = $this->product(['base_price' => '20.4800']);
        $this->reference($catalog, $group, ['percent' => '90', 'priority' => 2]);
        $this->reference($catalog, $group, ['percent' => '20', 'priority' => 1]);
        $winner = $this->reference($catalog, $group, ['percent' => '40', 'priority' => 1,
            'starts_at' => '2026-10-20 12:00:00', 'ends_at' => '2026-10-20 12:00:02'], ['round_prices' => 1]);
        foreach (['12:00:00' => 16.384, '12:00:01' => 12.3, '12:00:02' => 16.384] as $time => $price) {
            $this->travelTo(Carbon::parse('2026-10-20 '.$time));
            $this->assertPrices($buyer, [$product->id => $price]);
            if ($price === 12.3) {
                $this->assertSame($winner->id, app(PriceCatalogResolver::class)->resolve($product, $buyer)->rule_id);
            }
        }
        $this->assertSame(1, app(PriceCatalogResolver::class)->legacyDiscounts()->supportReport($catalog)['rounded']);
    }

    public function test_original_final_special_quantity_and_individual_prices_remain_authoritative(): void
    {
        [$catalog, $group, $buyer] = $this->fixture();
        $special = $this->product();
        $quantity = $this->product();
        $individual = $this->product();
        $this->entry($catalog, $special, PriceCatalogEntry::SPECIAL, '73', ['customer_group_id' => $group->id]);
        $this->entry($catalog, $quantity, PriceCatalogEntry::QUANTITY, '74', ['customer_group_id' => $group->id]);
        $this->entry($catalog, $individual, PriceCatalogEntry::CUSTOMER, '75', ['user_id' => $buyer->id]);
        $this->reference($catalog, $group, [], ['remove_individual_specials' => 1]);
        $this->assertPrices($buyer, [$special->id => 73.0, $quantity->id => 74.0, $individual->id => 75.0]);
        $this->assertDatabaseCount('catalog_price_entries', 3);
        $this->assertSame(1, app(PriceCatalogResolver::class)->legacyDiscounts()->supportReport($catalog)['preserve_existing_specials']);
    }

    public function test_original_template_fills_after_exact_group_before_primary_default_and_never_broadens_category_scope(): void
    {
        [$catalog, $group, $buyer] = $this->fixture();
        $category = $this->category('template-category');
        $child = $this->category('template-child', $category->id);
        $exact = $this->product();
        $new = $this->product();
        $descendant = $this->product();
        $this->entry($catalog, $exact, PriceCatalogEntry::GROUP, '35', ['customer_group_id' => $group->id]);
        $this->entry($catalog, $new, PriceCatalogEntry::BASE, '200');
        foreach ([$exact, $new] as $product) {
            $product->categories()->attach($category);
        }
        $descendant->categories()->attach($child);
        $reference = $this->reference($catalog, $group, ['source_type' => 'cigroup_template', 'category_ids' => [$category->id], 'percent' => '55']);
        $this->assertPrices($buyer, [$exact->id => 35.0, $new->id => 90.0, $descendant->id => 50.0]);
        $this->assertSame('price_catalog_legacy_group_discount', app(PriceCatalogResolver::class)->resolve($new, $buyer)->source_type);
        $rows = app(PriceCatalogGroupOverview::class)->rows($catalog, $group->id, collect([$new]));
        $this->assertSame('90.0000', $rows[$new->id]['effective_price']);
        $this->assertSame($reference->name, $rows[$new->id]['source_name']);
        $this->assertSame('price_catalog_legacy_group_discount', $rows[$new->id]['source_type']);
    }

    public function test_unsupported_and_incompletely_mapped_definitions_are_skipped_without_expanding_scope(): void
    {
        [$catalog, $group, $buyer] = $this->fixture();
        $product = $this->product();
        $filter = $this->reference($catalog, $group);
        $unmapped = $this->reference($catalog, $group);
        $fixed = $this->reference($catalog, $group);
        $template = $this->reference($catalog, $group, ['source_type' => 'cigroup_template']);
        $definition = $filter->definition;
        $definition['filter_ids'] = [['filter_id' => 9]];
        DB::table($filter->getTable())->where('id', $filter->id)->update(['definition' => json_encode($definition)]);
        $definition = $unmapped->definition;
        $definition['manufacturer_ids'] = [['manufacturer_id' => 999]];
        DB::table($unmapped->getTable())->where('id', $unmapped->id)->update(['definition' => json_encode($definition)]);
        $definition = $fixed->definition;
        $definition['sale']['discount_type'] = 'value';
        DB::table($fixed->getTable())->where('id', $fixed->id)->update(['definition' => json_encode($definition)]);
        $flags = $template->source_flags;
        $flags['basedon'] = '0';
        DB::table($template->getTable())->where('id', $template->id)->update(['source_flags' => json_encode($flags)]);
        $this->assertPrices($buyer, [$product->id => 50.0]);
        $report = app(PriceCatalogResolver::class)->legacyDiscounts()->supportReport($catalog);
        $this->assertSame(0, $report['supported']);
        $this->assertCount(4, $report['skipped']);
    }

    public function test_original_excluded_source_products_stay_excluded_without_a_target_mapping_including_later_imports(): void
    {
        [$catalog, $group, $buyer] = $this->fixture();
        $mapped = $this->product();
        $reference = $this->reference($catalog, $group, ['excluded_product_ids' => [$mapped->id]]);
        $definition = $reference->definition;
        $definition['excluded_product_ids'][] = ['product_id' => 8675309];
        DB::table($reference->getTable())->where('id', $reference->id)->update(['definition' => json_encode($definition),
            'warnings' => json_encode(['Nedostaje prenesena poveznica za excluded_product_ids (izvorni ID 8675309). Opseg se ne smije automatski proširiti.'])]);
        // The source product is imported later with a different local ID and no import-map row.
        $later = $this->product(['payload' => ['opencart' => ['product_id' => 8675309]]]);
        $other = $this->product(['payload' => ['opencart' => ['product_id' => 1234567]]]);
        $local = $this->product();
        $this->assertNotSame(8675309, $later->id);
        $this->assertPrices($buyer, [$mapped->id => 50.0, $later->id => 50.0, $other->id => 40.0, $local->id => 40.0]);
        $this->assertSame(1, app(PriceCatalogResolver::class)->legacyDiscounts()->supportReport($catalog)['supported']);
    }

    public function test_partial_positive_scopes_apply_only_proven_mapped_branches_and_fully_unmapped_scopes_never_become_all(): void
    {
        [$catalog, $group, $buyer] = $this->fixture();
        $categories = collect(range(1, 6))->map(fn ($number) => $this->category('mapped-'.$number));
        $reference = $this->reference($catalog, $group, ['category_ids' => $categories->pluck('id')->all()]);
        $definition = $reference->definition;
        $definition['category_ids'][] = ['category_id' => 999999];
        DB::table($reference->getTable())->where('id', $reference->id)->update(['definition' => json_encode($definition),
            'warnings' => json_encode(['Nedostaje prenesena poveznica za category_ids (izvorni ID 999999). Opseg se ne smije automatski proširiti.'])]);
        foreach ($categories as $category) {
            DB::table('herrera_import_maps')->insert(['source' => $catalog->source_system, 'entity' => 'category',
                'source_id' => $category->id + 10000, 'target_id' => $category->id, 'checksum' => str_repeat('c', 64)]);
        }
        $unmapped = $this->reference($catalog, $group, ['percent' => '90']);
        $definition = $unmapped->definition;
        $definition['category_ids'] = [['category_id' => 777777]];
        DB::table($unmapped->getTable())->where('id', $unmapped->id)->update(['definition' => json_encode($definition)]);
        $expected = [];
        foreach ($categories as $category) {
            $product = $this->product();
            $product->categories()->attach($category);
            $expected[$product->id] = 40.0;
        }
        $outside = $this->product();
        $outside->categories()->attach($this->category('outside-source-scope'));
        $expected[$outside->id] = 50.0;
        $this->assertPrices($buyer, $expected);
        $report = app(PriceCatalogResolver::class)->legacyDiscounts()->supportReport($catalog);
        $this->assertSame(1, $report['supported']);
        $this->assertCount(1, $report['skipped']);
    }

    public function test_partial_scope_recovery_rejects_fabricated_targets_and_invalid_dates_remain_skipped(): void
    {
        [$catalog, $group, $buyer] = $this->fixture();
        $real = Manufacturer::query()->create(['code' => 'proven', 'is_active' => true]);
        $fabricated = Manufacturer::query()->create(['code' => 'fabricated', 'is_active' => true]);
        $reference = $this->reference($catalog, $group, ['manufacturer_ids' => [$fabricated->id]]);
        $definition = $reference->definition;
        $definition['manufacturer_ids'] = [['manufacturer_id' => 44444], ['manufacturer_id' => 55555]];
        DB::table($reference->getTable())->where('id', $reference->id)->update(['definition' => json_encode($definition)]);
        DB::table('herrera_import_maps')->insert(['source' => $catalog->source_system, 'entity' => 'manufacturer',
            'source_id' => 44444, 'target_id' => $real->id, 'checksum' => str_repeat('c', 64)]);
        $this->reference($catalog, $group, ['percent' => '90', 'starts_at' => '2026-12-31 00:00:00', 'ends_at' => '2026-12-30 00:00:00']);
        $valid = $this->product(['manufacturer_id' => $real->id]);
        $invalid = $this->product(['manufacturer_id' => $fabricated->id]);
        $this->assertPrices($buyer, [$valid->id => 40.0, $invalid->id => 50.0]);
        $report = app(PriceCatalogResolver::class)->legacyDiscounts()->supportReport($catalog);
        $this->assertSame(1, $report['supported']);
        $this->assertCount(1, $report['skipped']);
        $this->assertSame('Invalid date range.', $report['skipped'][0]['reason']);
    }

    public function test_references_are_strictly_bound_to_verified_catalog_source_and_native_customer_rules_still_win(): void
    {
        [$catalog, $group, $buyer] = $this->fixture();
        $product = $this->product();
        foreach (['source_system', 'source_snapshot', 'source_catalog_checksum'] as $field) {
            $this->reference($catalog, $group, [$field => 'unrelated']);
        }
        $this->assertPrices($buyer, [$product->id => 50.0]);
        $this->reference($catalog, $group);
        app(PriceCatalogResolver::class)->forgetActiveCatalog();
        $this->assertPrices($buyer, [$product->id => 40.0]);
        DB::table('catalog_group_discount_rules')->insert(['price_catalog_id' => $catalog->id, 'name' => 'Native', 'percent' => 65,
            'customer_group_ids' => json_encode([$group->id]), 'manufacturer_ids' => '[]', 'category_ids' => '[]', 'excluded_product_ids' => '[]', 'is_active' => true]);
        app(PriceCatalogResolver::class)->forgetActiveCatalog();
        $this->assertPrices($buyer, [$product->id => 35.0]);
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        app(CustomerDiscountService::class)->saveRule($buyer, ['name' => 'Customer', 'percent' => '70'], $admin);
        $this->assertPrices($buyer, [$product->id => 30.0]);
    }

    public function test_promotion_filter_and_group_overview_include_new_matching_products(): void
    {
        [$catalog, $group, $buyer] = $this->fixture();
        $category = $this->category('promo');
        $product = $this->product();
        $product->categories()->attach($category);
        $reference = $this->reference($catalog, $group, ['category_ids' => [$category->id]]);
        $query = Product::query();
        app(PriceCatalogQuery::class)->applyPromotionFilter($query, $buyer);
        $this->assertSame([$product->id], $query->pluck('products.id')->all());
        $overview = app(PriceCatalogGroupOverview::class);
        $this->assertContains($product->id, $overview->products($catalog, $group->id)->pluck('id')->all());
        $row = $overview->rows($catalog, $group->id, collect([$product]))[$product->id];
        $this->assertSame('40.0000', $row['effective_price']);
        $this->assertSame($reference->id, $row['source_id']);
        $this->assertSame('price_catalog_legacy_category_discount', $row['source_type']);
    }

    public function test_legacy_product_batches_run_a_bounded_number_of_queries_without_per_card_lookups(): void
    {
        [$catalog, $group, $buyer] = $this->fixture();
        $this->reference($catalog, $group);
        $this->reference($catalog, $group, ['source_type' => 'cigroup_template', 'percent' => '55']);
        $products = collect(range(1, 24))->map(fn () => $this->product());
        $buyer->load('b2bAccount.customerGroup');
        $resolver = app(PriceCatalogResolver::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $resolver->preloadProducts($products, $buyer);
            $count = count(DB::getQueryLog());
            $this->assertLessThanOrEqual(9, $count);
            foreach ($products as $product) {
                $this->assertSame(40.0, $resolver->resolve($product, $buyer)->price);
            }
            $this->assertCount($count, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function assertPrices(User $buyer, array $expected): void
    {
        $resolver = app(PriceCatalogResolver::class);
        $products = Product::query()->whereKey(array_keys($expected))->get();
        foreach ($products as $product) {
            $this->assertSame($expected[$product->id], $resolver->resolve($product, $buyer, at: now())->price);
        }
        $resolver->preloadProducts($products, $buyer);
        foreach ($products as $product) {
            $this->assertSame($expected[$product->id], $resolver->resolve($product, $buyer)->price);
        }
        $price = (new PriceCatalogQuery)->storedPrice($buyer);
        $rows = Product::query()->whereKey(array_keys($expected))->select('products.id')->selectRaw($price['sql'].' as actual_price', $price['bindings'])->get();
        foreach ($rows as $row) {
            $this->assertSame($expected[$row->id], (float) $row->actual_price);
        }
    }

    private function fixture(): array
    {
        $catalog = PriceCatalog::query()->create(['name' => 'Original', 'status' => PriceCatalog::DRAFT,
            'source_system' => 'herrera-opencart', 'source_snapshot' => 'original', 'source_checksum' => str_repeat('a', 64)]);
        $catalog->update(['status' => PriceCatalog::ACTIVE]);
        DB::table('catalog_price_catalog_state')->where('id', 1)->update(['price_catalog_id' => $catalog->id]);
        $group = CustomerGroup::query()->create(['code' => uniqid('group-'), 'name' => 'Partner', 'is_active' => true, 'default_discount_percent' => '50']);
        $buyer = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $buyer->id, 'company_name' => 'Partner', 'oib' => '12345678901',
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id]);

        return [$catalog, $group, $buyer];
    }

    private function reference(PriceCatalog $catalog, CustomerGroup $group, array $extra = [], array $flags = []): LegacyGroupDiscountReference
    {
        $data = $extra + ['source_system' => $catalog->source_system, 'source_snapshot' => $catalog->source_snapshot,
            'source_catalog_checksum' => $catalog->source_checksum, 'source_key' => uniqid('source-'), 'source_type' => 'mega_sale',
            'definition_checksum' => str_repeat('b', 64), 'name' => 'Original rule', 'percent' => '60', 'customer_group_ids' => [$group->id],
            'manufacturer_ids' => [], 'category_ids' => [], 'excluded_product_ids' => [], 'include_descendants' => true,
            'starts_at' => null, 'ends_at' => null, 'priority' => 0, 'is_supported' => false, 'warnings' => []];
        if ($data['source_type'] === 'mega_sale') {
            $data['source_flags'] = $flags + ['round_prices' => 0, 'remove_individual_specials' => 0, 'exclude_child' => $data['include_descendants'] ? 0 : 1];
            $data['definition'] = ['sale' => ['discount_type' => 'percent', 'discount_value' => $data['percent']] + $data['source_flags'], 'filter_ids' => []];
            foreach (['customer_group_ids' => 'customer_group_id', 'manufacturer_ids' => 'manufacturer_id', 'category_ids' => 'category_id', 'excluded_product_ids' => 'product_id'] as $field => $sourceField) {
                $data['definition'][$field] = array_map(fn ($id) => [$sourceField => $id + 10000], $data[$field]);
            }
        } else {
            $data['source_flags'] = $flags + ['status' => '1', 'basedon' => '1', 'type' => 'P', 'action' => '-', 'value' => $data['percent']];
            $data['definition'] = ['template' => ['type' => 'product'], 'source_customer_group_id' => 10000,
                'setting' => ['filter_type' => $data['manufacturer_ids'] !== [] ? 'custom_manufacturer' : ($data['category_ids'] !== [] ? 'custom_category' : 'all'),
                    'manufacturers' => $data['manufacturer_ids'], 'categories' => $data['category_ids'], 'filter_exclude_products' => $data['excluded_product_ids']]];
        }

        return LegacyGroupDiscountReference::query()->create($data);
    }

    private function product(array $extra = []): Product
    {
        return Product::query()->create($extra + ['code' => uniqid('product-'), 'base_price' => '100', 'stock_qty' => 20, 'is_active' => true]);
    }

    private function category(string $code, ?int $parentId = null): Category
    {
        return Category::query()->create(['code' => $code, 'parent_id' => $parentId, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, string $price, array $extra = []): void
    {
        DB::table('catalog_price_entries')->insert($extra + ['price_catalog_id' => $catalog->id, 'source_key' => uniqid('entry-'), 'product_id' => $product->id,
            'kind' => $kind, 'price' => $price, 'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true]);
    }
}
