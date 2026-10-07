<?php

namespace Tests\Feature\Admin;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\CatalogGroupDiscountService;
use App\Services\Pricing\PriceCatalogQuery;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\PriceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CatalogGroupDiscountServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
    }

    public function test_preview_and_rule_compile_intersecting_scopes_descendants_exclusions_and_multiple_groups(): void
    {
        [$admin, $catalog, $group] = $this->fixture();
        $other = $this->group('other');
        $brand = Manufacturer::query()->create(['code' => 'brand', 'is_active' => true]);
        $wrongBrand = Manufacturer::query()->create(['code' => 'wrong-brand', 'is_active' => true]);
        $parent = Category::query()->create(['code' => 'parent', 'scope' => 'catalog', 'is_active' => true]);
        $child = Category::query()->create(['code' => 'child', 'scope' => 'catalog', 'is_active' => true, 'parent_id' => $parent->id]);
        $target = $this->product('target', ['manufacturer_id' => $brand->id]);
        $excluded = $this->product('excluded', ['manufacturer_id' => $brand->id]);
        $wrong = $this->product('wrong', ['manufacturer_id' => $wrongBrand->id]);
        foreach ([$target, $excluded, $wrong] as $product) {
            $product->categories()->attach($child);
        }
        $this->entry($catalog, $target, PriceCatalogEntry::BASE, '20.4800');
        $data = $this->data([$group, $other], ['percent' => '40', 'manufacturer_ids' => [$brand->id], 'category_ids' => [$parent->id], 'excluded_product_ids' => [$excluded->id]]);
        $service = app(CatalogGroupDiscountService::class);
        $preview = $service->previewRule($catalog, $data, $admin);
        $this->assertSame(1, $preview['product_count']);
        $this->assertSame(2, $preview['entry_count']);
        $this->assertSame('12.2880', $preview['samples'][0]['price']);
        $this->assertSame(0, $catalog->discountRules()->count());
        $rule = $service->saveRule($catalog, $data, $admin);
        $this->assertSame(1, $rule->materialized_product_count);
        $this->assertSame(2, $rule->materialized_entry_count);
        $this->assertSame(['12.2880', '12.2880'], $rule->entries()->pluck('price')->all());
        $this->assertSame([$group->id, $other->id], $rule->entries()->orderBy('customer_group_id')->pluck('customer_group_id')->all());
        $noDescendants = $service->previewRule($catalog, array_replace($data, ['include_descendants' => false]), $admin);
        $this->assertSame(0, $noDescendants['product_count']);
    }

    public function test_new_rule_replaces_old_final_actions_only_in_its_scope_without_stacking(): void
    {
        [$admin, $catalog, $group] = $this->fixture();
        $customer = $this->customer($group);
        $otherCustomer = $this->customer($this->group('other'));
        $product = $this->product('target');
        $outside = $this->product('outside');
        $this->entry($catalog, $product, PriceCatalogEntry::BASE, '100.1234');
        $imported = $this->entry($catalog, $product, PriceCatalogEntry::SPECIAL, '20.1234', $group);
        $this->entry($catalog, $outside, PriceCatalogEntry::SPECIAL, '20.4321', $group);
        $rule = app(CatalogGroupDiscountService::class)->saveRule($catalog, $this->data([$group], ['percent' => '40', 'excluded_product_ids' => [$outside->id]]), $admin);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        $resolved = app(PriceCatalogResolver::class)->resolve($product, $customer);
        $this->assertSame(60.074, $resolved?->price);
        $this->assertSame('price_catalog_group_discount', $resolved?->source_type);
        $this->assertSame($rule->id, $resolved?->rule_id);
        $this->assertSame('20.1234', $imported->fresh()->price);
        $this->assertSame(20.4321, app(PriceCatalogResolver::class)->resolve($outside, $customer)?->price);
        $this->assertSame(100.1234, app(PriceCatalogResolver::class)->resolve($product, $otherCustomer)?->price);
        $this->assertNull(app(PriceCatalogResolver::class)->resolve($product, null));
    }

    public function test_rule_start_is_inclusive_end_exclusive_and_sql_matches_individual_resolution(): void
    {
        [$admin, $catalog, $group] = $this->fixture();
        $customer = $this->customer($group);
        $product = $this->product('target');
        $this->entry($catalog, $product, PriceCatalogEntry::BASE, '100');
        $this->entry($catalog, $product, PriceCatalogEntry::SPECIAL, '70', $group);
        app(CatalogGroupDiscountService::class)->saveRule($catalog, $this->data([$group], ['percent' => '40', 'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-12-01 00:00:00']), $admin);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        foreach (['2026-10-31 23:59:59' => 70.0, '2026-11-01 00:00:00' => 60.0, '2026-11-15 00:00:00' => 60.0, '2026-12-01 00:00:00' => 70.0] as $time => $expected) {
            $this->travelTo(\Illuminate\Support\Carbon::parse($time));
            $this->app->forgetInstance(PriceCatalogQuery::class);
            $stored = app(PriceCatalogQuery::class)->storedPrice($customer);
            $queried = Product::query()->whereKey($product->id)->selectRaw($stored['sql'].' AS effective_price', $stored['bindings'])->firstOrFail();
            $this->assertSame($expected, (float) $queried->effective_price);
            $this->assertSame($expected, app(PriceCatalogResolver::class)->resolve($product, $customer)?->price);
        }
        $this->travelBack();
    }

    public function test_catalog_without_new_rules_preserves_imported_prices_priority_and_exclusive_dates_in_sql_and_resolver(): void
    {
        [$admin, $catalog, $group] = $this->fixture();
        $customer = $this->customer($group);
        $product = $this->product('original');
        $this->entry($catalog, $product, PriceCatalogEntry::BASE, '20.4800');
        $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '12.2880', $group);
        $special = $this->entry($catalog, $product, PriceCatalogEntry::SPECIAL, '15.7777', $group);
        $special->update(['starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-12-01 00:00:00']);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        foreach (['2026-10-31 23:59:59' => 12.288, '2026-11-01 00:00:00' => 12.288, '2026-11-01 00:00:01' => 15.7777, '2026-12-01 00:00:00' => 12.288] as $time => $expected) {
            $this->travelTo(\Illuminate\Support\Carbon::parse($time));
            $this->app->forgetInstance(PriceCatalogQuery::class);
            $stored = app(PriceCatalogQuery::class)->storedPrice($customer);
            $queried = Product::query()->whereKey($product->id)->selectRaw($stored['sql'].' AS effective_price', $stored['bindings'])->firstOrFail();
            $this->assertSame($expected, (float) $queried->effective_price);
            $this->assertSame($expected, app(PriceCatalogResolver::class)->resolve($product, $customer)?->price);
        }
        $this->assertSame('15.7777', $special->fresh()->price);
        $this->assertSame(0, $catalog->discountRules()->count());
        $this->travelBack();
    }

    public function test_rule_priority_and_largest_discount_tie_are_deterministic_without_stacking(): void
    {
        [$admin, $catalog, $group] = $this->fixture();
        $product = $this->product('target');
        $this->entry($catalog, $product, PriceCatalogEntry::BASE, '100');
        $service = app(CatalogGroupDiscountService::class);
        $service->saveRule($catalog, $this->data([$group], ['percent' => '90', 'priority' => 2]), $admin);
        $service->saveRule($catalog, $this->data([$group], ['percent' => '20', 'priority' => 1]), $admin);
        $winner = $service->saveRule($catalog, $this->data([$group], ['percent' => '30', 'priority' => 1]), $admin);
        $service->saveRule($catalog, $this->data([$group], ['percent' => '30', 'priority' => 1]), $admin);
        $resolved = app(PriceCatalogResolver::class)->resolve($product, $this->customer($group), catalog: $catalog);
        $this->assertSame(70.0, $resolved?->price);
        $this->assertSame($winner->id, $resolved?->rule_id);
    }

    public function test_publication_rebuilds_draft_bases_and_clone_keeps_source_and_remapped_rules_immutable(): void
    {
        [$admin, $catalog, $group] = $this->fixture();
        $product = $this->product('target');
        $customer = $this->customer($group);
        $base = $this->entry($catalog, $product, PriceCatalogEntry::BASE, '100');
        $rule = app(CatalogGroupDiscountService::class)->saveRule($catalog, $this->data([$group], ['percent' => '40']), $admin);
        $this->assertSame('60.0000', $rule->entries()->first()->price);
        $base->update(['price' => '200']);
        $catalogService = app(PriceCatalogService::class);
        $catalogService->activate($catalog, $admin);
        $this->assertSame(120.0, app(PriceCatalogResolver::class)->resolve($product, $customer)?->price);
        $copy = $catalogService->cloneToDraft($catalog, $admin);
        $copyRule = $copy->discountRules()->firstOrFail();
        $this->assertNotSame($rule->id, $copyRule->id);
        $this->assertSame('120.0000', $copyRule->entries()->firstOrFail()->price);
        $this->assertSame($copy->id, $copyRule->entries()->firstOrFail()->price_catalog_id);
        app(CatalogGroupDiscountService::class)->saveRule($copy, $this->data([$group], ['percent' => '50']), $admin, $copyRule->id);
        $this->assertSame(120.0, app(PriceCatalogResolver::class)->resolve($product, $customer)?->price);
        $this->assertSame('40.0000', $rule->fresh()->percent);
        $catalogService->activate($copy, $admin);
        $this->assertSame(100.0, app(PriceCatalogResolver::class)->resolve($product, $customer)?->price);
        $this->assertSame('120.0000', $rule->entries()->firstOrFail()->price);
        $this->assertDatabaseHas('catalog_price_catalog_audits', ['price_catalog_id' => $copy->id, 'event' => 'group_discount_updated']);
    }

    public function test_update_and_delete_only_regenerate_own_rule_and_leave_imports_unchanged(): void
    {
        [$admin, $catalog, $group] = $this->fixture();
        $product = $this->product('target');
        $import = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '10', $group);
        $service = app(CatalogGroupDiscountService::class);
        $rule = $service->saveRule($catalog, $this->data([$group]), $admin);
        $other = $service->saveRule($catalog, $this->data([$group]), $admin);
        $otherEntry = $other->entries()->firstOrFail()->id;
        $service->saveRule($catalog, $this->data([$group], ['percent' => '60', 'is_active' => false]), $admin, $rule->id);
        $this->assertFalse($rule->entries()->firstOrFail()->is_active);
        $this->assertSame($otherEntry, $other->entries()->firstOrFail()->id);
        $service->deleteRule($catalog, $rule->id, $admin);
        $this->assertSame(0, $catalog->entries()->where('discount_rule_id', $rule->id)->count());
        $this->assertSame('10.0000', $import->fresh()->price);
        $this->assertDatabaseHas('catalog_price_catalog_audits', ['price_catalog_id' => $catalog->id, 'event' => 'group_discount_deleted']);
    }

    public function test_active_rules_cannot_be_changed_through_service_or_stale_models(): void
    {
        [$admin, $catalog, $group] = $this->fixture();
        $this->product('target');
        $service = app(CatalogGroupDiscountService::class);
        $rule = $service->saveRule($catalog, $this->data([$group]), $admin);
        app(PriceCatalogService::class)->activate($catalog, $admin);
        foreach ([fn () => $service->saveRule($catalog, $this->data([$group]), $admin, $rule->id), fn () => $service->deleteRule($catalog, $rule->id, $admin), fn () => $rule->update(['percent' => 90])] as $operation) {
            try {
                $operation();
                $this->fail('Published definitions must remain immutable.');
            } catch (ValidationException) {
                $this->assertSame('40.0000', $rule->fresh()->percent);
            }
        }
    }

    public function test_validation_authorization_and_cross_catalog_ids_cannot_modify_rules(): void
    {
        [$admin, $catalog, $group] = $this->fixture();
        $this->product('target');
        $service = app(CatalogGroupDiscountService::class);
        foreach ([['percent' => '0'], ['percent' => '100.0001'], ['percent' => '10.12345'], ['customer_group_ids' => []], ['ends_at' => '2026-01-01', 'starts_at' => '2026-01-01']] as $invalid) {
            try {
                $service->saveRule($catalog, array_replace($this->data([$group]), $invalid), $admin);
                $this->fail('Invalid rule must be rejected.');
            } catch (ValidationException) {
                $this->assertSame(0, $catalog->discountRules()->count());
            }
        }
        try {
            $service->saveRule($catalog, $this->data([$group]), User::factory()->create());
            $this->fail('Unauthorized user must be rejected.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $other = app(PriceCatalogService::class)->createDraft('Other', $admin);
        $rule = $service->saveRule($other, $this->data([$group]), $admin);
        try {
            $service->deleteRule($catalog, $rule->id, $admin);
            $this->fail('Cross-catalog rule ID must not delete anything.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame(1, $other->discountRules()->count());
        }
    }

    public function test_group_price_table_is_atomic_blank_cells_unchanged_zero_allowed_and_manual_overrides_imported_group_only(): void
    {
        [$admin, $catalog, $group] = $this->fixture();
        $other = $this->group('other');
        $product = $this->product('target');
        $customer = $this->customer($group);
        $import = $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '5', $group);
        $this->entry($catalog, $product, PriceCatalogEntry::GROUP, '9', $other);
        $service = app(PriceCatalogService::class);
        $this->assertSame(1, $service->saveGroupPrices($catalog, $product->id, [$group->id => '12.2880', $other->id => ''], $admin));
        $this->assertSame(12.288, app(PriceCatalogResolver::class)->resolve($product, $customer, catalog: $catalog)?->price);
        $this->assertSame('5.0000', $import->fresh()->price);
        $service->saveGroupPrices($catalog, $product->id, [$group->id => '0'], $admin);
        $this->assertSame(0.0, app(PriceCatalogResolver::class)->resolve($product, $customer, catalog: $catalog)?->price);
        $this->assertSame(1, $catalog->entries()->where('source_key', 'manual-group:'.$product->id.':'.$group->id)->count());
        try {
            $service->saveGroupPrices($catalog, $product->id, [$group->id => '1', $other->id => '-1'], $admin);
            $this->fail('All rows must validate before any price is changed.');
        } catch (ValidationException) {
            $this->assertSame(0.0, app(PriceCatalogResolver::class)->resolve($product, $customer, catalog: $catalog)?->price);
        }
        $this->entry($catalog, $product, PriceCatalogEntry::SPECIAL, '7', $group);
        $this->assertSame(7.0, app(PriceCatalogResolver::class)->resolve($product, $customer, catalog: $catalog)?->price);
    }

    public function test_four_decimal_math_handles_half_up_zero_and_large_values_without_float_loss(): void
    {
        $service = app(CatalogGroupDiscountService::class);
        foreach ([['20.4800', '40', '12.2880'], ['0.0001', '50', '0.0001'], ['100.1234', '33.3333', '66.7490'], ['1234.5678', '100', '0.0000'], ['9999999999999999.9999', '0.0001', '9999989999999999.9999'], ['0', '99.9999', '0.0000']] as [$base, $percent, $expected]) {
            $this->assertSame($expected, $service->discountedPrice($base, $percent));
        }
    }

    public function test_generated_prices_cannot_be_hand_edited_or_detached_and_cross_catalog_links_block_publication(): void
    {
        [$admin, $catalog, $group] = $this->fixture();
        $product = $this->product('target');
        $rule = app(CatalogGroupDiscountService::class)->saveRule($catalog, $this->data([$group]), $admin);
        $entry = $rule->entries()->firstOrFail();
        $service = app(PriceCatalogService::class);
        foreach ([fn () => $service->deleteEntry($catalog, $entry->id, $admin), fn () => $service->saveEntry($catalog, ['product_id' => $product->id, 'kind' => 'group', 'customer_group_id' => $group->id, 'user_id' => null, 'minimum_quantity' => 1, 'price' => '1', 'priority' => 0, 'starts_at' => null, 'ends_at' => null, 'is_active' => true], $admin, $entry->id)] as $operation) {
            try {
                $operation();
                $this->fail('Generated prices must be edited through their rule.');
            } catch (ValidationException) {
                $this->assertSame('60.0000', $entry->fresh()->price);
            }
        }
        $other = $service->createDraft('Other', $admin);
        $bad = $other->entries()->create(['source_key' => 'bad-rule-link', 'product_id' => $product->id, 'kind' => PriceCatalogEntry::GROUP_DISCOUNT, 'price' => '1', 'customer_group_id' => $group->id, 'discount_rule_id' => $rule->id]);
        try {
            $service->activate($other, $admin);
            $this->fail('A rule must belong to the entry catalog.');
        } catch (ValidationException) {
            $this->assertSame('draft', $other->fresh()->status);
            $this->assertNotNull($bad->fresh());
        }
    }

    private function fixture(): array
    {
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);

        return [$admin, app(PriceCatalogService::class)->createDraft('Test', $admin), $this->group('partners')];
    }

    private function group(string $code): CustomerGroup
    {
        return CustomerGroup::query()->create(['code' => $code, 'name' => $code, 'is_active' => true]);
    }

    private function customer(CustomerGroup $group): User
    {
        $customer = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $customer->id, 'company_name' => 'Test d.o.o.', 'oib' => '12345678901', 'status' => 'approved', 'customer_group_id' => $group->id]);

        return $customer;
    }

    private function product(string $code, array $extra = []): Product
    {
        return Product::query()->create($extra + ['code' => $code, 'sku' => $code, 'base_price' => '100', 'is_active' => true]);
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, string $price, ?CustomerGroup $group = null): PriceCatalogEntry
    {
        return $catalog->entries()->create(['source_key' => 'test:'.uniqid(), 'product_id' => $product->id, 'kind' => $kind, 'price' => $price, 'customer_group_id' => $group?->id, 'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true]);
    }

    private function data(array $groups, array $extra = []): array
    {
        return $extra + ['name' => 'Popust partneri', 'percent' => '40', 'customer_group_ids' => array_map(fn ($group) => $group->id, $groups), 'manufacturer_ids' => [], 'category_ids' => [], 'include_descendants' => true, 'excluded_product_ids' => [], 'starts_at' => null, 'ends_at' => null, 'priority' => 0, 'is_active' => true];
    }
}
