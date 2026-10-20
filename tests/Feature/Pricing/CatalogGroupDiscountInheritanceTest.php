<?php

namespace Tests\Feature\Pricing;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Pricing\CatalogGroupDiscountRule;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Pricing\CatalogGroupDiscountService;
use App\Services\Pricing\PriceCatalogGroupOverview;
use App\Services\Pricing\PriceCatalogQuery;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\PriceCatalogService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CatalogGroupDiscountInheritanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        $this->travelTo(Carbon::parse('2026-10-14 09:00:00'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_new_products_inherit_scoped_rules_after_categories_are_attached_without_changing_the_snapshot(): void
    {
        [$admin, $catalog, $group, $buyer, $existing] = $this->fixture();
        $brand = Manufacturer::query()->create(['code' => 'brand', 'is_active' => true]);
        $wrongBrand = Manufacturer::query()->create(['code' => 'other-brand', 'is_active' => true]);
        $parent = $this->category('parent');
        $child = $this->category('child', $parent->id);
        $existing->update(['manufacturer_id' => $brand->id]);
        $existing->categories()->attach($child);
        $oldOutside = $this->product(['manufacturer_id' => $brand->id]);
        $rule = $this->rule($catalog, $group, $admin, ['manufacturer_ids' => [$brand->id], 'category_ids' => [$parent->id]]);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $before = $catalog->entries()->orderBy('id')->get()->toArray();
        $auditCount = $catalog->audits()->count();
        $this->assertSame($oldOutside->id, $catalog->fresh()->metadata['publication_product_id_ceiling']);

        // Importers attach categories after creating the product. No observer
        // may freeze the uncategorized price before that attachment exists.
        $new = $this->product(['manufacturer_id' => $brand->id, 'base_price' => '20.4800']);
        $wrong = $this->product(['manufacturer_id' => $wrongBrand->id]);
        $new->categories()->attach($child);
        $wrong->categories()->attach($child);
        $oldOutside->categories()->attach($child);
        $existing->update(['base_price' => '999.9999']);
        $this->assertPrice(12.288, $new, $buyer);
        $resolved = app(PriceCatalogResolver::class)->resolve($new, $buyer);
        $this->assertSame('price_catalog_group_discount', $resolved->source_type);
        $this->assertSame($rule->id, $resolved->rule_id);
        $this->assertNull($resolved->catalog_entry_id);
        $this->assertTrue($resolved->is_final);
        $this->assertPrice(100.0, $wrong, $buyer);
        $this->assertPrice(60.0, $oldOutside, $buyer);
        $this->assertPrice(60.0, $existing, $buyer);
        $this->assertPrice(20.48, $new, $this->buyer($this->group()));
        $this->assertSame($before, $catalog->entries()->orderBy('id')->get()->toArray());
        $this->assertSame($auditCount, $catalog->audits()->count());
        $this->assertSame(1, $rule->fresh()->materialized_product_count);
    }

    public function test_inherited_rules_obey_schedule_priority_discount_ties_and_zero_price_ties(): void
    {
        [$admin, $catalog, $group, $buyer] = $this->fixture();
        $lowPriority = $this->rule($catalog, $group, $admin, ['percent' => '90', 'priority' => 10]);
        $this->rule($catalog, $group, $admin, ['percent' => '20', 'priority' => 1]);
        $winner = $this->rule($catalog, $group, $admin, ['percent' => '40', 'priority' => 1,
            'starts_at' => '2026-10-14 10:00:00', 'ends_at' => '2026-10-14 11:00:00']);
        $this->rule($catalog, $group, $admin, ['percent' => '40', 'priority' => 1,
            'starts_at' => '2026-10-14 10:00:00', 'ends_at' => '2026-10-14 11:00:00']);
        $inactive = $this->rule($catalog, $group, $admin, ['percent' => '100', 'priority' => -1, 'is_active' => false]);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $new = $this->product();
        foreach (['09:59:59' => 80.0, '10:00:00' => 60.0, '10:30:00' => 60.0, '11:00:00' => 80.0] as $time => $price) {
            $this->travelTo(Carbon::parse('2026-10-14 '.$time));
            $this->assertPrice($price, $new, $buyer);
            if ($price === 60.0) {
                $this->assertSame($winner->id, app(PriceCatalogResolver::class)->resolve($new, $buyer)->rule_id);
            }
            $this->assertNotContains(app(PriceCatalogResolver::class)->resolve($new, $buyer)->rule_id, [$lowPriority->id, $inactive->id]);
        }
        $new->update(['base_price' => '0']);
        $this->travelTo(Carbon::parse('2026-10-14 10:30:00'));
        app(PriceCatalogResolver::class)->forgetActiveCatalog();
        $this->assertPrice(0.0, $new, $buyer);
        $firstAtPriority = $catalog->discountRules()->where('priority', 1)->orderBy('id')->first();
        $this->assertSame($firstAtPriority->id, app(PriceCatalogResolver::class)->resolve($new, $buyer)->rule_id);
    }

    public function test_legacy_publications_apply_current_descendants_and_exclusions_regardless_of_creation_date(): void
    {
        [$admin, $catalog, $group, $buyer, $existing] = $this->fixture();
        $parent = $this->category('parent');
        $child = $this->category('child', $parent->id);
        $new = $this->product();
        $excluded = $this->product();
        $older = $this->product();
        DB::table('products')->where('id', $older->id)->update(['created_at' => '2026-10-13 08:00:00']);
        foreach ([$new, $excluded, $older, $existing] as $product) {
            $product->categories()->attach($child);
        }
        // Older publications have no product-ID boundary in their metadata.
        $rule = CatalogGroupDiscountRule::query()->create(['price_catalog_id' => $catalog->id, 'name' => 'Legacy',
            'percent' => '40', 'customer_group_ids' => [$group->id], 'manufacturer_ids' => [],
            'category_ids' => [$parent->id], 'include_descendants' => true, 'excluded_product_ids' => [$excluded->id],
            'is_active' => true, 'priority' => 0]);
        $this->publishLegacy($catalog, '2026-10-13 09:00:00');
        $this->assertPrice(60.0, $new, $buyer);
        $this->assertSame($rule->id, app(PriceCatalogResolver::class)->resolve($new, $buyer)->rule_id);
        $this->assertPrice(100.0, $excluded, $buyer);
        $this->assertPrice(60.0, $older, $buyer);
        $this->assertPrice(60.0, $existing, $buyer);
    }

    public function test_exact_category_scope_does_not_inherit_to_child_categories(): void
    {
        [$admin, $catalog, $group, $buyer] = $this->fixture();
        $parent = $this->category('parent');
        $child = $this->category('child', $parent->id);
        $this->rule($catalog, $group, $admin, ['category_ids' => [$parent->id], 'include_descendants' => false]);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $direct = $this->product();
        $descendant = $this->product();
        $direct->categories()->attach($parent);
        $descendant->categories()->attach($child);
        $this->assertPrice(60.0, $direct, $buyer);
        $this->assertPrice(100.0, $descendant, $buyer);
    }

    public function test_existing_prices_do_not_block_a_current_matching_native_rule(): void
    {
        [$admin, $catalog, $group, $buyer] = $this->fixture();
        CatalogGroupDiscountRule::query()->create(['price_catalog_id' => $catalog->id, 'name' => 'Legacy',
            'percent' => '40', 'customer_group_ids' => [$group->id], 'manufacturer_ids' => [], 'category_ids' => [],
            'excluded_product_ids' => [], 'is_active' => true, 'priority' => 0]);
        $customer = $this->product();
        $groupProduct = $this->product();
        $expired = $this->product();
        $inactive = $this->product();
        $otherAudience = $this->product();
        $this->entry($catalog, $customer, PriceCatalogEntry::CUSTOMER, '77.1234', ['user_id' => $buyer->id]);
        $this->entry($catalog, $groupProduct, PriceCatalogEntry::GROUP, '88.1234', ['customer_group_id' => $group->id]);
        $this->entry($catalog, $expired, PriceCatalogEntry::SPECIAL, '44', ['customer_group_id' => $group->id, 'ends_at' => '2026-10-13 10:00:00']);
        $this->entry($catalog, $inactive, PriceCatalogEntry::GROUP, '33', ['customer_group_id' => $group->id, 'is_active' => false]);
        $this->entry($catalog, $otherAudience, PriceCatalogEntry::GROUP, '22', ['customer_group_id' => $this->group()->id]);
        $this->publishLegacy($catalog, '2026-10-13 09:00:00');
        $this->assertPrice(60.0, $customer, $buyer);
        $this->assertPrice(60.0, $groupProduct, $buyer);
        $this->assertPrice(60.0, $expired, $buyer);
        $this->assertPrice(60.0, $inactive, $buyer);
        $this->assertPrice(60.0, $otherAudience, $buyer);
    }

    public function test_category_membership_changes_replace_compiled_prices_and_use_the_catalog_base_in_every_channel(): void
    {
        [$admin, $catalog, $group, $buyer, $existing] = $this->fixture();
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
        $parent = $this->category('moving-parent');
        $child = $this->category('moving-child', $parent->id);
        $outside = $this->category('outside');
        $existing->categories()->attach($child);
        $existing->update(['stock_qty' => 20]);
        $this->entry($catalog, $existing, PriceCatalogEntry::SPECIAL, '25', ['customer_group_id' => $group->id]);
        $laterAssigned = $this->product(['stock_qty' => 20]);
        $this->entry($catalog, $laterAssigned, PriceCatalogEntry::BASE, '200');
        $this->entry($catalog, $laterAssigned, PriceCatalogEntry::GROUP, '7', ['customer_group_id' => $this->group()->id]);
        $rule = $this->rule($catalog, $group, $admin, ['percent' => '60', 'category_ids' => [$parent->id]]);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $compiled = $rule->entries()->where('product_id', $existing->id)->firstOrFail();
        $existing->update(['base_price' => '999']);
        $laterAssigned->update(['base_price' => '999']);
        $laterAssigned->categories()->attach($child);
        $this->assertPrice(40.0, $existing, $buyer);
        $this->assertPrice(80.0, $laterAssigned, $buyer);
        $resolver = app(PriceCatalogResolver::class);
        $resolver->preloadProducts([$existing, $laterAssigned], $buyer);
        $this->assertSame(40.0, $resolver->resolve($existing, $buyer)->price);
        $this->assertSame(80.0, $resolver->resolve($laterAssigned, $buyer)->price);
        $overview = app(PriceCatalogGroupOverview::class);
        $rows = $overview->rows($catalog, $group->id, collect([$existing, $laterAssigned]));
        $this->assertSame('80.0000', $rows[$laterAssigned->id]['effective_price']);
        $this->assertSame($rule->id, $rows[$laterAssigned->id]['effective_entry']->discount_rule_id);
        $this->actingAs($buyer);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($laterAssigned, 3));
        $this->assertSame(80.0, $cart->lines()->sole()['unit_price']);
        $this->assertSame(240.0, $cart->lines()->sole()['line_total']);
        $this->assertSame('price_catalog_group_discount', $cart->lines()->sole()['b2b_source_type']);

        $existing->categories()->sync([$outside->id]);
        $laterAssigned->categories()->sync([$outside->id]);
        $resolver->forgetActiveCatalog(); // A subsequent request has a fresh batch.
        $this->assertPrice(25.0, $existing, $buyer);
        $this->assertPrice(200.0, $laterAssigned, $buyer);
        $rows = $overview->rows($catalog, $group->id, collect([$existing, $laterAssigned]));
        $this->assertSame('25.0000', $rows[$existing->id]['effective_price']);
        $this->assertSame(PriceCatalogEntry::SPECIAL, $rows[$existing->id]['effective_entry']->kind);
        $this->assertSame('40.0000', $compiled->fresh()->price);
        $this->assertSame(200.0, $cart->lines()->sole()['unit_price']);
    }

    public function test_group_overview_includes_new_matching_products_without_any_snapshot_entry(): void
    {
        [$admin, $catalog, $group] = $this->fixture();
        $category = $this->category('new-category');
        $this->rule($catalog, $group, $admin, ['category_ids' => [$category->id]]);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $new = $this->product();
        $new->categories()->attach($category);
        $overview = app(PriceCatalogGroupOverview::class);
        $this->assertContains($new->id, $overview->products($catalog, $group->id)->pluck('id')->all());
        $this->assertSame('60.0000', $overview->rows($catalog, $group->id, collect([$new]))[$new->id]['effective_price']);
    }

    public function test_batch_inheritance_has_bounded_queries_and_refreshes_the_whole_page_at_a_schedule_boundary(): void
    {
        [$admin, $catalog, $group, $buyer] = $this->fixture();
        $this->rule($catalog, $group, $admin, ['starts_at' => '2026-10-14 09:00:01', 'ends_at' => '2026-10-14 09:00:03']);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $products = collect(range(1, 24))->map(fn () => $this->product());
        $buyer->load('b2bAccount.customerGroup');
        $resolver = app(PriceCatalogResolver::class);
        $resolver->activeCatalog();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $resolver->preloadProducts($products, $buyer);
            foreach ($products as $product) {
                $this->assertSame(100.0, $resolver->resolve($product, $buyer)->price);
            }
            $this->assertCount(4, DB::getQueryLog()); // Stored entries, group rules, inherited batch and cached customer-rule lookup.
            foreach (['09:00:01' => 60.0, '09:00:03' => 100.0] as $time => $price) {
                $this->travelTo(Carbon::parse('2026-10-14 '.$time));
                DB::flushQueryLog();
                foreach ($products as $product) {
                    $this->assertSame($price, $resolver->resolve($product, $buyer)->price);
                }
                $this->assertCount(2, DB::getQueryLog());
            }
        } finally {
            DB::disableQueryLog();
        }
    }

    private function assertPrice(float $expected, Product $product, User $buyer): void
    {
        $this->assertSame($expected, app(PriceCatalogResolver::class)->resolve($product, $buyer)->price);
        $this->app->forgetInstance(PriceCatalogQuery::class);
        $price = app(PriceCatalogQuery::class)->storedPrice($buyer);
        $row = Product::query()->whereKey($product->id)->selectRaw($price['sql'].' as effective_price', $price['bindings'])->firstOrFail();
        $this->assertSame($expected, (float) $row->effective_price);
    }

    private function fixture(): array
    {
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);
        $group = $this->group();
        $catalog = app(PriceCatalogService::class)->createDraft('Inheritance fixture', $admin);
        $product = $this->product();
        $this->entry($catalog, $product, PriceCatalogEntry::BASE, '100');

        return [$admin, $catalog, $group, $this->buyer($group), $product];
    }

    private function group(): CustomerGroup
    {
        return CustomerGroup::query()->create(['code' => uniqid('group-'), 'name' => 'Partner', 'is_active' => true]);
    }

    private function buyer(CustomerGroup $group): User
    {
        $buyer = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $buyer->id, 'company_name' => 'Partner', 'oib' => '12345678901',
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id]);

        return $buyer;
    }

    private function product(array $extra = []): Product
    {
        return Product::query()->create($extra + ['code' => uniqid('product-'), 'base_price' => '100', 'is_active' => true]);
    }

    private function category(string $code, ?int $parentId = null): Category
    {
        return Category::query()->create(['code' => $code, 'parent_id' => $parentId, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
    }

    private function rule(PriceCatalog $catalog, CustomerGroup $group, User $admin, array $extra = []): CatalogGroupDiscountRule
    {
        return app(CatalogGroupDiscountService::class)->saveRule($catalog, $extra + ['name' => 'Inherited rule', 'percent' => '40',
            'customer_group_ids' => [$group->id]], $admin);
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, string $price, array $extra = []): PriceCatalogEntry
    {
        return $catalog->entries()->create($extra + ['source_key' => uniqid('entry-'), 'product_id' => $product->id,
            'kind' => $kind, 'price' => $price, 'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true]);
    }

    private function publishLegacy(PriceCatalog $catalog, string $activatedAt): void
    {
        $catalog->update(['status' => PriceCatalog::ACTIVE, 'activated_at' => $activatedAt]);
        DB::table('catalog_price_catalog_state')->where('id', 1)->update(['price_catalog_id' => $catalog->id]);
        app(PriceCatalogResolver::class)->forgetActiveCatalog();
    }
}
