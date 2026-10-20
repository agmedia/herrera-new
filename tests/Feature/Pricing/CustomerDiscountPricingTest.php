<?php

namespace Tests\Feature\Pricing;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Pricing\CustomerDiscountRule;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use App\Services\Pricing\CustomerDiscountService;
use App\Services\Pricing\PriceCatalogQuery;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\PriceCatalogService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CustomerDiscountPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_customer_sixty_percent_replaces_group_fifty_without_stacking_or_affecting_other_buyers(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $otherBuyer = $this->buyer($group);
        $product = $this->product($catalog, $group);
        $product->update(['base_price' => '250']);
        $this->entry($catalog, $product, PriceCatalogEntry::SPECIAL, '30', $group);
        $rule = $this->save($buyer, $actor, ['percent' => '60']);
        $resolver = app(PriceCatalogResolver::class);
        $direct = $resolver->resolve($product, $buyer);
        $resolver->preloadProducts([$product], $buyer);
        $this->assertSame(get_object_vars($direct), get_object_vars($resolver->resolve($product, $buyer)));
        $this->assertSame(40.0, $direct->price);
        $this->assertSame('price_catalog_customer_discount', $direct->source_type);
        $this->assertSame($rule->id, $direct->rule_id);
        $this->assertSame($buyer->id, $direct->user_id);
        $this->assertSame($group->id, $direct->customer_group_id);
        $this->assertTrue($direct->is_final);
        $this->assertNull($direct->catalog_entry_id);
        $this->assertNull($direct->previous_price);
        $this->assertSame(30.0, $resolver->resolve($product, $otherBuyer)->price);
        $this->assertPrices($buyer, [$product->id => 40.0]);
        $this->assertPrices($otherBuyer, [$product->id => 30.0]);
        $this->assertDatabaseCount('catalog_price_entries', 3);
        $this->assertDatabaseHas('catalog_price_entries', ['product_id' => $product->id, 'kind' => 'group', 'price' => 50]);
    }

    public function test_scopes_combine_categories_and_manufacturers_and_specificity_precedes_priority(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $brand = Manufacturer::query()->create(['code' => 'brand', 'is_active' => true]);
        $otherBrand = Manufacturer::query()->create(['code' => 'other-brand', 'is_active' => true]);
        $category = Category::query()->create(['code' => 'parent', 'scope' => 'catalog', 'is_active' => true]);
        $child = Category::query()->create(['code' => 'child', 'scope' => 'catalog', 'parent_id' => $category->id, 'is_active' => true]);
        $otherCategory = Category::query()->create(['code' => 'other-category', 'scope' => 'catalog', 'is_active' => true]);
        $combo = $this->product($catalog, $group, $brand, $child);
        $categoryOnly = $this->product($catalog, $group, $otherBrand, $child);
        $manufacturerOnly = $this->product($catalog, $group, $brand, $otherCategory);
        $excluded = $this->product($catalog, $group, $brand, $child);
        $unmatched = $this->product($catalog, $group, $otherBrand, $otherCategory);
        $this->save($buyer, $actor, ['percent' => 20, 'manufacturer_ids' => [$brand->id], 'priority' => -20]);
        $this->save($buyer, $actor, ['percent' => 30, 'category_ids' => [$category->id], 'priority' => -10]);
        $winner = $this->save($buyer, $actor, ['percent' => 60, 'manufacturer_ids' => [$brand->id], 'category_ids' => [$category->id], 'excluded_product_ids' => [$excluded->id], 'priority' => 10]);
        $this->save($buyer, $actor, ['percent' => 65, 'manufacturer_ids' => [$brand->id], 'category_ids' => [$category->id], 'excluded_product_ids' => [$excluded->id], 'priority' => 20]);
        $this->assertSame($winner->id, app(PriceCatalogResolver::class)->resolve($combo, $buyer)->rule_id);
        $expected = [$combo->id => 40.0, $categoryOnly->id => 70.0, $manufacturerOnly->id => 80.0, $excluded->id => 70.0, $unmatched->id => 50.0];
        $this->assertPrices($buyer, $expected);
        foreach ([$combo, $categoryOnly, $manufacturerOnly, $excluded, $unmatched] as $product) {
            $this->assertSame($expected[$product->id], app(PriceCatalogResolver::class)->resolve($product, $buyer)->price);
        }
        $this->assertSame(1, app(CustomerDiscountService::class)->products($winner->toArray())->count());

        $this->save($buyer, $actor, ['percent' => 40, 'category_ids' => [$category->id], 'include_descendants' => false, 'priority' => -100]);
        $this->assertSame(70.0, app(PriceCatalogResolver::class)->resolve($categoryOnly, $buyer)->price);
        $this->save($buyer, $actor, ['percent' => 10, 'priority' => -1000]);
        $this->assertPrices($buyer, [$combo->id => 40.0, $unmatched->id => 90.0]);
    }

    public function test_dates_are_inclusive_at_start_exclusive_at_end_and_mutations_clear_primed_prices(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $product = $this->product($catalog, $group);
        $data = ['percent' => 60, 'starts_at' => '2026-10-20 12:00:01', 'ends_at' => '2026-10-20 12:00:03'];
        $rule = $this->save($buyer, $actor, $data);
        $resolver = app(PriceCatalogResolver::class);
        $resolver->preloadProducts([$product], $buyer);
        foreach (['12:00:00' => 50.0, '12:00:01' => 40.0, '12:00:02' => 40.0, '12:00:03' => 50.0] as $time => $price) {
            $this->travelTo(Carbon::parse('2026-10-20 '.$time));
            $this->assertSame($price, $resolver->resolve($product, $buyer)->price);
            $this->assertPrices($buyer, [$product->id => $price]);
        }
        $this->travelTo(Carbon::parse('2026-10-20 12:00:02'));
        $resolver->preloadProducts([$product], $buyer);
        $this->save($buyer, $actor, ['percent' => 20, 'is_active' => false], $rule->id);
        $this->assertSame(50.0, $resolver->resolve($product, $buyer)->price);
        $this->save($buyer, $actor, ['percent' => 0], $rule->id);
        $this->assertSame(100.0, $resolver->resolve($product, $buyer)->price);
        $this->assertPrices($buyer, [$product->id => 100.0]);
        app(CustomerDiscountService::class)->deleteRule($buyer, $rule->id, $actor);
        $this->assertSame(50.0, $resolver->resolve($product, $buyer)->price);
        $this->assertPrices($buyer, [$product->id => 50.0]);
    }

    public function test_no_base_snapshot_uses_product_base_with_four_decimals_and_full_discount_can_be_zero(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $product = Product::query()->create(['code' => 'unsnapshotted', 'base_price' => '120.1234', 'is_active' => true]);
        $rule = $this->save($buyer, $actor, ['percent' => '60']);
        $resolver = app(PriceCatalogResolver::class);
        $this->assertSame(48.0494, $resolver->resolve($product, $buyer, fallback: 500)->price);
        $this->assertPrices($buyer, [$product->id => 48.0494]);
        $this->save($buyer, $actor, ['percent' => '100'], $rule->id);
        $this->assertSame(0.0, $resolver->resolve($product, $buyer)->price);
        $this->assertPrices($buyer, [$product->id => 0.0]);
    }

    public function test_customer_scope_and_pricing_permissions_protect_rules_and_pending_account_can_be_configured(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $product = $this->product($catalog, $group);
        $service = app(CustomerDiscountService::class);
        $rule = $this->save($buyer, $actor);
        $otherBuyer = $this->buyer($group);
        try {
            $this->save($otherBuyer, $actor, [], $rule->id);
            $this->fail('Rules must be scoped to the selected buyer.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseHas('customer_discount_rules', ['id' => $rule->id, 'user_id' => $buyer->id]);
        }
        try {
            $service->deleteRule($otherBuyer, $rule->id, $actor);
            $this->fail('Deleting another buyer\'s rule must fail.');
        } catch (ModelNotFoundException) {
            $this->assertModelExists($rule);
        }
        try {
            $this->save($buyer, User::factory()->create());
            $this->fail('Pricing permissions are required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            $this->save(User::factory()->create(), $actor);
            $this->fail('A saved B2B profile is required.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer', $exception->errors());
        }
        $buyer->b2bAccount()->update(['status' => B2BAccount::STATUS_PENDING]);
        $buyer->unsetRelation('b2bAccount');
        $this->save($buyer, $actor, ['percent' => 70], $rule->id);
        $this->assertNull(app(PriceCatalogResolver::class)->resolve($product, $buyer));
        $this->assertNull(app(PriceCatalogQuery::class)->storedPrice($buyer));
        $buyer->b2bAccount()->update(['status' => B2BAccount::STATUS_APPROVED, 'contract_ends_at' => now()->subDay()->toDateString()]);
        $buyer->unsetRelation('b2bAccount');
        $this->assertNull(app(PriceCatalogResolver::class)->resolve($product, $buyer));
        $this->assertNull(app(PriceCatalogQuery::class)->storedPrice($buyer));
    }

    public function test_customer_agreement_survives_a_new_catalog_and_uses_the_new_base_snapshot(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $product = $this->product($catalog, $group);
        $rule = $this->save($buyer, $actor);
        $this->assertSame(40.0, app(PriceCatalogResolver::class)->resolve($product, $buyer)->price);
        $next = PriceCatalog::query()->create(['name' => 'Next catalog', 'status' => PriceCatalog::DRAFT, 'currency_code' => 'EUR']);
        $this->entry($next, $product, PriceCatalogEntry::BASE, '200');
        $this->entry($next, $product, PriceCatalogEntry::GROUP, '100', $group);
        app(PriceCatalogService::class)->activate($next, $actor);
        $price = app(PriceCatalogResolver::class)->resolve($product, $buyer);
        $this->assertSame($rule->id, $price->rule_id);
        $this->assertSame($next->id, $price->catalog_id);
        $this->assertSame(80.0, $price->price);
        $this->assertPrices($buyer, [$product->id => 80.0]);
        $this->assertSame(2, $catalog->entries()->count());
        $this->assertDatabaseCount('customer_discount_rules', 1);
    }

    public function test_category_agreement_applies_to_new_products_and_existing_products_as_categories_change(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $category = Category::query()->create(['code' => 'dynamic', 'scope' => 'catalog', 'is_active' => true]);
        $existing = $this->product($catalog, $group);
        $this->save($buyer, $actor, ['category_ids' => [$category->id]]);
        $resolver = app(PriceCatalogResolver::class);
        $resolver->preloadProducts([$existing], $buyer);
        $this->assertSame(50.0, $resolver->resolve($existing, $buyer)->price);
        $existing->categories()->attach($category);
        $resolver->forgetActiveCatalog(); // Next request after catalog membership changes.
        $this->assertSame(40.0, $resolver->resolve($existing, $buyer)->price);

        $new = Product::query()->create(['code' => 'new-category-product', 'base_price' => '120.1234', 'is_active' => true]);
        $new->categories()->attach($category);
        $this->assertSame(48.0494, $resolver->resolve($new, $buyer)->price);
        $this->assertPrices($buyer, [$existing->id => 40.0, $new->id => 48.0494]);
        $existing->categories()->detach($category);
        $new->categories()->detach($category);
        $resolver->forgetActiveCatalog();
        $this->assertSame(50.0, $resolver->resolve($existing, $buyer)->price);
        $this->assertSame(120.1234, $resolver->resolve($new, $buyer)->price);
        $this->assertPrices($buyer, [$existing->id => 50.0, $new->id => 120.1234]);
        $existing->categories()->attach($category);
        $resolver->forgetActiveCatalog();
        $resolver->preloadProducts([$existing], $buyer);
        $this->assertSame(40.0, $resolver->resolve($existing, $buyer)->price);
    }

    public function test_imported_category_rule_edits_disable_expiry_moves_and_deletion_never_restore_generated_customer_prices(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $category = Category::query()->create(['code' => 'legacy-category', 'scope' => 'catalog', 'is_active' => true]);
        $product = $this->product($catalog, $group, category: $category);
        $this->legacyEntry($catalog, $product, $buyer, '40', 1226);
        $rule = CustomerDiscountRule::query()->create([
            'user_id' => $buyer->id, 'name' => 'Imported category', 'percent' => 60,
            'category_ids' => [$category->id], 'source_system' => 'opencart', 'source_key' => 'opencart:customer-category:326:1226',
            'payload' => ['opencart' => ['customer_id' => 326, 'category_id' => 1226]],
        ]);
        $resolver = app(PriceCatalogResolver::class);
        $resolver->preloadProducts([$product], $buyer);
        $this->assertSame(40.0, $resolver->resolve($product, $buyer)->price);
        $this->save($buyer, $actor, ['percent' => 30, 'category_ids' => [$category->id]], $rule->id);
        $this->assertSame(70.0, $resolver->resolve($product, $buyer)->price);
        $this->assertPrices($buyer, [$product->id => 70.0]);
        $this->assertTrue($rule->fresh()->payload['customer_rule_edited']);
        $this->assertSame('opencart:customer-category:326:1226', $rule->fresh()->source_key);
        $this->save($buyer, $actor, ['percent' => 30, 'category_ids' => [$category->id], 'is_active' => false], $rule->id);
        $this->assertSame(50.0, $resolver->resolve($product, $buyer)->price);
        $this->assertPrices($buyer, [$product->id => 50.0]);
        $this->save($buyer, $actor, ['percent' => 30, 'category_ids' => [$category->id], 'ends_at' => now()->subSecond()->toDateTimeString()], $rule->id);
        $this->assertSame(50.0, $resolver->resolve($product, $buyer)->price);
        $this->save($buyer, $actor, ['percent' => 30, 'category_ids' => [$category->id]], $rule->id);
        $product->categories()->detach($category);
        $resolver->forgetActiveCatalog();
        $this->assertSame(50.0, $resolver->resolve($product, $buyer)->price);
        $this->assertPrices($buyer, [$product->id => 50.0]);
        $product->categories()->attach($category);
        $resolver->forgetActiveCatalog();
        $this->assertSame(70.0, $resolver->resolve($product, $buyer)->price);
        app(CustomerDiscountService::class)->deleteRule($buyer, $rule->id, $actor);
        $this->assertSoftDeleted('customer_discount_rules', ['id' => $rule->id]);
        $this->assertNull(CustomerDiscountRule::query()->find($rule->id));
        $this->assertSame($rule->source_key, CustomerDiscountRule::withTrashed()->findOrFail($rule->id)->source_key);
        $this->assertSame(50.0, $resolver->resolve($product, $buyer)->price);
        $this->assertPrices($buyer, [$product->id => 50.0]);
    }

    public function test_generated_customer_exclusion_is_limited_to_the_replaced_original_customer_and_category(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $category = Category::query()->create(['code' => 'legacy-category', 'scope' => 'catalog', 'is_active' => true]);
        $manual = $this->product($catalog, $group);
        $otherCategory = $this->product($catalog, $group);
        $otherSourceCustomer = $this->product($catalog, $group);
        $this->legacyEntry($catalog, $manual, $buyer, '20', 0);
        $this->legacyEntry($catalog, $otherCategory, $buyer, '35', 9999);
        $this->legacyEntry($catalog, $otherSourceCustomer, $buyer, '30', 1226, 999);
        CustomerDiscountRule::query()->create([
            'user_id' => $buyer->id, 'name' => 'Imported inactive rule', 'percent' => 60,
            'category_ids' => [$category->id], 'source_system' => 'opencart', 'source_key' => 'opencart:customer-category:326:1226',
            'payload' => ['opencart' => ['customer_id' => 326, 'category_id' => 1226]], 'is_active' => false,
        ]);
        foreach ([$manual->id => 20.0, $otherCategory->id => 35.0, $otherSourceCustomer->id => 30.0] as $id => $price) {
            $this->assertSame($price, app(PriceCatalogResolver::class)->resolve(Product::findOrFail($id), $buyer)->price);
        }
        $this->assertPrices($buyer, [$manual->id => 20.0, $otherCategory->id => 35.0, $otherSourceCustomer->id => 30.0]);
    }

    public function test_large_explicit_category_scope_keeps_direct_batch_and_query_prices_identical(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        Category::query()->insert(array_map(fn (int $id): array => ['code' => 'scope-'.$id, 'scope' => 'catalog', 'is_active' => true], range(1, 600)));
        $ids = Category::query()->pluck('id')->all();
        $product = $this->product($catalog, $group);
        $product->categories()->attach(end($ids));
        $this->save($buyer, $actor, ['category_ids' => $ids, 'include_descendants' => false]);
        $resolver = app(PriceCatalogResolver::class);
        $this->assertSame(40.0, $resolver->resolve($product, $buyer)->price);
        $resolver->preloadProducts([$product], $buyer);
        $this->assertSame(40.0, $resolver->resolve($product, $buyer)->price);
        $this->assertPrices($buyer, [$product->id => 40.0]);
    }

    public function test_rule_audit_records_customer_actor_and_before_after_values(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $rule = $this->save($buyer, $actor);
        $this->save($buyer, $actor, ['percent' => 65], $rule->id);
        app(CustomerDiscountService::class)->deleteRule($buyer, $rule->id, $actor);
        $audit = Activity::query()->where('log_name', 'catalog_b2b_prices')->orderBy('id')->get();
        $this->assertSame(['customer_discount_created', 'customer_discount_updated', 'customer_discount_deleted'], $audit->pluck('event')->all());
        foreach ($audit as $event) {
            $this->assertSame($actor->id, $event->causer_id);
            $this->assertSame($rule->id, $event->subject_id);
            $this->assertSame($buyer->id, $event->properties['user_id']);
        }
        $this->assertNull($audit[0]->properties['before']);
        $this->assertSame('60.0000', $audit[1]->properties['before']['percent']);
        $this->assertSame('65.0000', $audit[1]->properties['after']['percent']);
        $this->assertNull($audit[2]->properties['after']);
    }

    public function test_assigned_customer_scope_still_applies_to_staff_with_pricing_permissions(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $manager = User::factory()->create();
        Bouncer::assign('order_manager')->to($manager);
        Bouncer::allow($manager)->to(['admin.access', 'catalog.b2b_prices.view', 'catalog.b2b_prices.update', 'catalog.b2b_prices.delete']);
        try {
            $this->save($buyer, $manager);
            $this->fail('Unassigned customers must remain inaccessible.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $buyer->update(['account_type' => 'customer']);
        DB::table('admin_customer_assignments')->insert(['admin_user_id' => $manager->id, 'customer_user_id' => $buyer->id, 'created_at' => now(), 'updated_at' => now()]);
        $rule = $this->save($buyer, $manager);
        $this->assertSame($manager->id, $rule->created_by);
        DB::table('admin_customer_assignments')->where('admin_user_id', $manager->id)->delete();
        try {
            app(CustomerDiscountService::class)->deleteRule($buyer, $rule->id, $manager);
            $this->fail('Revoking customer assignment must also revoke discount editing.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertModelExists($rule);
    }

    public function test_percent_scope_and_date_validation_reject_invalid_rules(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $blog = Category::query()->create(['code' => 'blog', 'scope' => 'blog', 'is_active' => true]);
        $inactive = Manufacturer::query()->create(['code' => 'inactive', 'is_active' => false]);
        foreach ([
            ['percent' => 101], ['percent' => '-1'], ['percent' => '12.12345'],
            ['starts_at' => '2026-10-20 12:00:00', 'ends_at' => '2026-10-20 12:00:00'],
            ['category_ids' => [$blog->id]], ['manufacturer_ids' => [$inactive->id]], ['excluded_product_ids' => [99999]],
        ] as $invalid) {
            try {
                $this->save($buyer, $actor, $invalid);
                $this->fail('Invalid customer discount input must fail validation.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('customer_discount_rules', 0);
    }

    public function test_pricing_permission_does_not_allow_staff_to_edit_a_superadmin_customer(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        Bouncer::assign('superadmin')->to($buyer);
        $staff = User::factory()->create();
        Bouncer::allow($staff)->to(['admin.access', 'catalog.b2b_prices.view', 'catalog.b2b_prices.update']);
        try {
            $this->save($buyer, $staff);
            $this->fail('Pricing permissions cannot bypass protected user editing.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('customer_discount_rules', 0);
    }

    public function test_price_query_filter_sort_and_promotion_filter_use_the_customer_winner(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $brand = Manufacturer::query()->create(['code' => 'brand', 'is_active' => true]);
        $discounted = $this->product($catalog, $group, $brand);
        $regular = $this->product($catalog, $group);
        $this->entry($catalog, $discounted, PriceCatalogEntry::SPECIAL, '20', $group);
        $this->save($buyer, $actor, ['manufacturer_ids' => [$brand->id], 'percent' => 60]);
        $price = app(PriceCatalogQuery::class)->storedPrice($buyer);
        $query = Product::query()->select('products.id')->selectRaw($price['sql'].' as resolved_price', $price['bindings']);
        $this->assertSame([$discounted->id], (clone $query)->whereRaw($price['sql'].' < ?', [...$price['bindings'], 45])->pluck('id')->all());
        $this->assertSame([$regular->id, $discounted->id], (clone $query)->orderByRaw($price['sql'].' DESC', $price['bindings'])->pluck('id')->all());
        $promotions = Product::query();
        $this->assertTrue(app(PriceCatalogQuery::class)->applyPromotionFilter($promotions, $buyer));
        $this->assertSame([], $promotions->pluck('products.id')->all());
    }

    public function test_cart_uses_the_final_customer_price_at_quantity_without_stacking_promotions(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
        $product = $this->product($catalog, $group);
        $product->update(['stock_qty' => 20]);
        $rule = $this->save($buyer, $actor);
        CatalogAction::query()->create(['code' => 'UNUSED-PROMO', 'scope' => 'product', 'type' => 'percentage', 'discount_value' => 50, 'target_type' => 'all', 'audience_type' => 'all', 'is_active' => true]);
        $this->actingAs($buyer);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, 3));
        $line = $cart->lines()->sole();
        $this->assertSame(40.0, $line['unit_price']);
        $this->assertSame(120.0, $line['line_total']);
        $this->assertSame(3, $line['quantity']);
        $this->assertSame('price_catalog_customer_discount', $line['b2b_source_type']);
        $this->assertSame($rule->id, $line['b2b_rule_id']);
        $this->assertTrue($line['price_is_final']);
        $this->assertSame(4, $line['monetary_precision']);
        $this->assertNull($line['action_code']);
        $this->assertSame(0.0, $cart->summary()['cart_discount_total']);
    }

    public function test_preloading_many_products_runs_a_constant_number_of_queries(): void
    {
        [$buyer, $group, $catalog, $actor] = $this->fixture();
        $products = collect(range(1, 10))->map(fn () => $this->product($catalog, $group));
        $this->save($buyer, $actor);
        $resolver = app(PriceCatalogResolver::class);
        $buyer->load('b2bAccount.customerGroup');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $resolver->preloadProducts($products, $buyer);
            $queriesAfterPreload = count(DB::getQueryLog());
            foreach ($products as $product) {
                $this->assertSame(40.0, $resolver->resolve($product, $buyer)->price);
            }
            $this->assertLessThanOrEqual(7, $queriesAfterPreload);
            $this->assertCount($queriesAfterPreload, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    private function fixture(): array
    {
        $group = CustomerGroup::query()->create(['code' => uniqid('b2b-'), 'name' => 'B2B50', 'is_active' => true]);
        $catalog = PriceCatalog::query()->create(['name' => 'Published catalog', 'status' => PriceCatalog::DRAFT, 'currency_code' => 'EUR']);
        $catalog->update(['status' => PriceCatalog::ACTIVE]);
        DB::table('catalog_price_catalog_state')->where('id', 1)->update(['price_catalog_id' => $catalog->id]);
        $actor = User::factory()->create();
        Bouncer::assign('superadmin')->to($actor);

        return [$this->buyer($group), $group, $catalog, $actor];
    }

    private function buyer(CustomerGroup $group): User
    {
        $buyer = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $buyer->id, 'company_name' => 'Customer', 'oib' => '12345678901', 'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id]);

        return $buyer;
    }

    private function product(PriceCatalog $catalog, CustomerGroup $group, ?Manufacturer $manufacturer = null, ?Category $category = null): Product
    {
        $product = Product::query()->create(['code' => uniqid('product-'), 'base_price' => '100', 'manufacturer_id' => $manufacturer?->id, 'is_active' => true]);
        if ($category) {
            $product->categories()->attach($category);
        }
        $this->entry($catalog, $product, PriceCatalogEntry::BASE, '100');
        $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '50', $group);

        return $product;
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, string $price, ?CustomerGroup $group = null): void
    {
        // Fixtures insert immutable snapshots as the import pipeline does.
        DB::table('catalog_price_entries')->insert(['price_catalog_id' => $catalog->id, 'source_key' => uniqid('entry-'), 'product_id' => $product->id, 'kind' => $kind, 'customer_group_id' => $group?->id, 'price' => $price, 'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true]);
    }

    private function legacyEntry(PriceCatalog $catalog, Product $product, User $buyer, string $price, int $categoryId, int $customerId = 326): void
    {
        DB::table('catalog_price_entries')->insert([
            'price_catalog_id' => $catalog->id, 'source_key' => uniqid('legacy-customer-'), 'product_id' => $product->id,
            'kind' => PriceCatalogEntry::CUSTOMER, 'user_id' => $buyer->id, 'price' => $price, 'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true,
            'payload' => json_encode(['opencart' => ['customer_id' => $customerId, 'category_id' => $categoryId]], JSON_THROW_ON_ERROR),
        ]);
    }

    private function save(User $buyer, User $actor, array $extra = [], ?int $ruleId = null): CustomerDiscountRule
    {
        return app(CustomerDiscountService::class)->saveRule($buyer, $extra + ['name' => 'Customer agreement', 'percent' => 60], $actor, $ruleId);
    }

    private function assertPrices(User $buyer, array $expected): void
    {
        $stored = (new PriceCatalogQuery)->storedPrice($buyer);
        $query = Product::query()->whereKey(array_keys($expected))->select('products.id')->selectRaw($stored['sql'].' as resolved_price', $stored['bindings']);
        $actual = $query->get()->mapWithKeys(fn ($product) => [$product->id => (float) $product->resolved_price])->all();
        $this->assertEquals($expected, $actual);
    }
}
