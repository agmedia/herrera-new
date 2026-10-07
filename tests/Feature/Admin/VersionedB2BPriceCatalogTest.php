<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Catalog\Pricing\PriceCatalogManager;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\PriceCatalogService;
use App\Services\Pricing\ProductGroupPriceResolver;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class VersionedB2BPriceCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
    }

    public function test_precedence_and_exact_source_prices_are_preserved(): void
    {
        [$customer, $group, $product] = $this->customerProduct();
        $catalog = $this->draft();
        $this->entry($catalog, $product, 'base', '120.1234');
        $this->entry($catalog, $product, 'group', '100.1234', $group);
        $this->entry($catalog, $product, 'customer', '90.1234', customer: $customer);
        $this->entry($catalog, $product, 'quantity', '80.1234', $group, ['minimum_quantity' => 5]);
        $this->activate($catalog);
        $resolver = app(ProductGroupPriceResolver::class);
        $this->assertSame(90.1234, $resolver->resolve($product, $customer, 1)?->price);
        $this->assertSame(80.1234, $resolver->resolve($product, $customer, 5)?->price);
        $copy = app(PriceCatalogService::class)->cloneToDraft($catalog, $this->admin());
        $this->entry($copy, $product, 'special', '110.5678', $group);
        $this->activate($copy);
        $price = $resolver->resolve($product, $customer, 5);
        // OpenCart special overrides even a cheaper personal/quantity price.
        $this->assertSame(110.5678, $price?->price);
        $this->assertSame('price_catalog_special', $price?->source_type);
        $this->assertSame($copy->id, $price?->catalog_id);
        $this->assertTrue($price?->is_final);
    }

    public function test_quantity_ties_match_legacy_quantity_priority_price_order(): void
    {
        [$customer, $group, $product] = $this->customerProduct();
        $catalog = $this->draft();
        $this->entry($catalog, $product, 'quantity', '10', $group, ['minimum_quantity' => 2, 'priority' => 1]);
        $this->entry($catalog, $product, 'quantity', '70', $group, ['minimum_quantity' => 10, 'priority' => 5]);
        $winner = $this->entry($catalog, $product, 'quantity', '65', $group, ['minimum_quantity' => 10, 'priority' => 5]);
        $this->entry($catalog, $product, 'quantity', '50', $group, ['minimum_quantity' => 10, 'priority' => 6]);
        $this->activate($catalog);
        $price = app(PriceCatalogResolver::class)->resolve($product, $customer, 10);
        $this->assertSame(65.0, $price?->price);
        $this->assertSame($winner->id, $price?->catalog_entry_id);
    }

    public function test_special_dates_are_exclusive_like_opencart_and_zero_dates_map_to_no_boundary(): void
    {
        [$customer, $group, $product] = $this->customerProduct();
        $catalog = $this->draft();
        $this->entry($catalog, $product, 'group', '70', $group);
        $this->entry($catalog, $product, 'special', '50', $group, ['starts_at' => '2026-01-01 00:00:00', 'ends_at' => '2026-01-03 00:00:00']);
        $resolver = app(PriceCatalogResolver::class);
        $this->assertSame(70.0, $resolver->resolve($product, $customer, catalog: $catalog, at: '2026-01-01 00:00:00')?->price);
        $this->assertSame(50.0, $resolver->resolve($product, $customer, catalog: $catalog, at: '2026-01-02 00:00:00')?->price);
        $this->assertSame(70.0, $resolver->resolve($product, $customer, catalog: $catalog, at: '2026-01-03 00:00:00')?->price);
    }

    public function test_primary_account_group_only_and_base_snapshot_are_authoritative(): void
    {
        [$customer, $group, $product] = $this->customerProduct();
        $other = CustomerGroup::query()->create(['code' => 'other', 'name' => 'Other', 'is_active' => true]);
        $customer->customerGroups()->attach($other);
        $catalog = $this->draft();
        $this->entry($catalog, $product, 'base', '99.1234');
        $this->entry($catalog, $product, 'group', '1', $other);
        $this->activate($catalog);
        $product->update(['base_price' => 250]);
        $price = app(ProductGroupPriceResolver::class)->resolve($product, $customer);
        $this->assertSame(99.1234, $price?->price);
        $this->assertSame('price_catalog_base', $price?->source_type);
        $this->assertSame($group->id, $price?->customer_group_id);
    }

    public function test_pending_and_expired_accounts_cannot_resolve_any_snapshot_prices(): void
    {
        [$customer, $group, $product] = $this->customerProduct();
        $catalog = $this->draft();
        $this->entry($catalog, $product, 'group', '45', $group);
        $this->activate($catalog);
        $customer->b2bAccount()->update(['status' => 'pending']);
        $customer->unsetRelation('b2bAccount');
        $this->assertNull(app(PriceCatalogResolver::class)->resolve($product, $customer));
        $customer->b2bAccount()->update(['status' => 'approved', 'contract_ends_at' => now()->subDay()]);
        $customer->unsetRelation('b2bAccount');
        $this->assertNull(app(PriceCatalogResolver::class)->resolve($product, $customer));
        $this->assertNull(app(PriceCatalogResolver::class)->resolve($product, null));
    }

    public function test_snapshot_does_not_stack_inherited_promotions(): void
    {
        [$customer, $group, $product] = $this->customerProduct();
        $catalog = $this->draft();
        $this->entry($catalog, $product, 'group', '80', $group);
        $this->activate($catalog);
        CatalogAction::query()->create(['code' => 'old-50', 'name' => 'Inherited promotion', 'scope' => 'product', 'type' => 'percentage', 'discount_value' => 50, 'audience_type' => 'all', 'target_type' => 'all', 'is_active' => true]);
        $price = app(ProductPricePresentationService::class)->forProduct($product, $customer);
        $this->assertSame(80.0, $price['current_gross']);
        $this->assertFalse($price['has_promotional_discount']);
        $this->assertSame($catalog->id, $price['price_catalog_id']);
    }

    public function test_publication_is_atomic_and_clone_leaves_original_untouched(): void
    {
        [$customer, $group, $product] = $this->customerProduct();
        $admin = $this->admin();
        $service = app(PriceCatalogService::class);
        $first = $service->createDraft('Prva verzija', $admin);
        $original = $this->entry($first, $product, 'group', '80', $group);
        $service->activate($first, $admin);
        $copy = $service->cloneToDraft($first->fresh(), $admin);
        $copyEntry = $copy->entries()->firstOrFail();
        $service->saveEntry($copy, $this->data($product, $group, price: '75.1234'), $admin, $copyEntry->id);
        $this->assertSame(80.0, app(PriceCatalogResolver::class)->resolve($product, $customer)?->price);
        $service->activate($copy, $admin);
        $this->assertSame(75.1234, app(PriceCatalogResolver::class)->resolve($product, $customer)?->price);
        $this->assertSame('80.0000', $original->fresh()->price);
        $this->assertSame('retired', $first->fresh()->status);
        $this->assertSame('active', $copy->fresh()->status);
        $this->assertSame($copy->id, DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
        $this->assertDatabaseHas('catalog_price_catalog_audits', ['price_catalog_id' => $copy->id, 'actor_id' => $admin->id, 'event' => 'activated']);
        $this->assertDatabaseHas('catalog_price_catalog_audits', ['price_catalog_id' => $copy->id, 'event' => 'entry_updated']);
    }

    public function test_b2b_display_stays_net_while_vat_and_original_snapshot_are_correct(): void
    {
        [$customer, $group, $product] = $this->customerProduct();
        config(['commerce.b2b_display_net' => true]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
        $tax = TaxRate::query()->create(['code' => 'VAT25', 'name' => 'PDV 25%', 'rate_type' => 'percent', 'rate' => 25, 'is_active' => true, 'is_default' => true]);
        $product->update(['tax_rate_id' => $tax->id]);
        $catalog = $this->draft();
        $entry = $this->entry($catalog, $product, 'group', '91.2300', $group);
        $this->activate($catalog);
        $price = app(ProductPricePresentationService::class)->forProduct($product, $customer);
        $this->assertSame(91.23, $price['current_net']);
        $this->assertSame(114.04, $price['current_gross']);
        $this->assertSame(91.23, $price['display_current']);
        $this->assertFalse($price['display_includes_tax']);
        $this->assertSame('91.2300', $entry->fresh()->price);
        config(['commerce.b2b_display_net' => false]);
        $grossDisplay = app(ProductPricePresentationService::class)->forProduct($product, $customer);
        $this->assertSame(114.04, $grossDisplay['display_current']);
        $this->assertTrue($grossDisplay['display_includes_tax']);
        $hidden = app(ProductPricePresentationService::class)->forProduct($product);
        $this->assertNull($hidden['display_current']);
        $this->assertNull($hidden['display_includes_tax']);
    }

    public function test_active_entries_cannot_be_edited_even_using_stale_model(): void
    {
        [, $group, $product] = $this->customerProduct();
        $catalog = $this->draft();
        $entry = $this->entry($catalog, $product, 'group', '80', $group);
        $this->activate($catalog);
        $this->expectException(ValidationException::class);
        $entry->update(['price' => 1]);
    }

    public function test_failed_validation_does_not_replace_current_catalog(): void
    {
        [$customer, $group, $product] = $this->customerProduct();
        $catalog = $this->draft();
        $this->entry($catalog, $product, 'group', '80', $group);
        $this->activate($catalog);
        $draft = $this->draft();
        $this->entry($draft, $product, 'group', '10', $group);
        $draft->update(['metadata' => ['import_expected_entries' => 2, 'import_complete' => true]]);
        try {
            $this->activate($draft);
            $this->fail('Incomplete import must not activate.');
        } catch (ValidationException) {
            $this->assertSame(80.0, app(PriceCatalogResolver::class)->resolve($product, $customer)?->price);
            $this->assertSame('active', $catalog->fresh()->status);
            $this->assertSame('draft', $draft->fresh()->status);
        }
    }

    public function test_service_requires_scoped_permissions_and_valid_audience(): void
    {
        [, $group, $product] = $this->customerProduct();
        $catalog = $this->draft();
        $ordinary = User::factory()->create();
        try {
            app(PriceCatalogService::class)->saveEntry($catalog, $this->data($product, $group), $ordinary);
            $this->fail('Ordinary user cannot manage prices.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->expectException(ValidationException::class);
        app(PriceCatalogService::class)->saveEntry($catalog, array_replace($this->data($product, $group), ['user_id' => $ordinary->id]), $this->admin());
    }

    public function test_incomplete_import_cannot_publish_or_bypass_readiness_by_cloning(): void
    {
        [, $group, $product] = $this->customerProduct();
        $catalog = $this->draft();
        $catalog->update(['source_system' => 'opencart']);
        $this->entry($catalog, $product, 'group', '80', $group);
        $service = app(PriceCatalogService::class);
        $admin = $this->admin();
        foreach (['activate', 'cloneToDraft'] as $operation) {
            try {
                $service->{$operation}($catalog, $admin);
                $this->fail('Missing import completion must block '.$operation);
            } catch (ValidationException) {
                $this->assertSame(1, PriceCatalog::query()->count());
                $this->assertNull(DB::table('catalog_price_catalog_state')->where('id', 1)->value('price_catalog_id'));
            }
        }
        $catalog->update(['metadata' => ['import_complete' => true, 'import_expected_entries' => 1]]);
        $copy = $service->cloneToDraft($catalog, $admin);
        $this->assertTrue($copy->metadata['import_complete']);
        $this->assertArrayNotHasKey('import_expected_entries', $copy->metadata);
        $this->entry($copy, $product, 'base', '100');
        $service->deleteEntry($copy, $copy->entries()->where('kind', 'group')->value('id'), $admin);
        $service->activate($copy, $admin);
        $this->assertSame('active', $copy->fresh()->status);
    }

    public function test_admin_backend_can_manage_draft_and_simulate_effective_customer_price(): void
    {
        [$customer, $group, $product] = $this->customerProduct();
        $admin = $this->admin();
        $catalog = $this->draft();
        $this->actingAs($admin)->get(route('admin.b2b-prices.catalogs'))->assertOk()->assertSee('B2B cjenici i popusti');
        Livewire::actingAs($admin)->test(PriceCatalogManager::class)
            ->call('selectCatalog', $catalog->id)
            ->set('form', $this->data($product, $group, price: '65.4321'))
            ->call('saveEntry')->assertHasNoErrors()
            ->set('simulation.product_id', $product->id)->set('simulation.user_id', $customer->id)
            ->call('simulate')->assertHasNoErrors()->assertSet('preview.price', '65.4321')
            ->call('activate')->assertHasNoErrors();
        $this->assertSame(65.4321, app(PriceCatalogResolver::class)->resolve($product, $customer)?->price);
    }

    private function customerProduct(): array
    {
        $group = CustomerGroup::query()->create(['code' => 'partners', 'name' => 'Partneri', 'is_active' => true]);
        $customer = User::factory()->create();
        B2BAccount::query()->create(['user_id' => $customer->id, 'company_name' => 'Partner d.o.o.', 'oib' => '12345678901', 'status' => 'approved', 'customer_group_id' => $group->id]);
        $product = Product::query()->create(['code' => 'TEST', 'sku' => 'TEST', 'base_price' => 120, 'is_active' => true, 'stock_qty' => 100]);

        return [$customer, $group, $product];
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        Bouncer::assign('superadmin')->to($admin);

        return $admin;
    }

    private function draft(): PriceCatalog
    {
        return PriceCatalog::query()->create(['name' => 'Snapshot', 'status' => 'draft', 'currency_code' => 'EUR']);
    }

    private function activate(PriceCatalog $catalog): void
    {
        app(PriceCatalogService::class)->activate($catalog, $this->admin());
    }

    private function entry(PriceCatalog $catalog, Product $product, string $kind, string $price, ?CustomerGroup $group = null, array $extra = [], ?User $customer = null): PriceCatalogEntry
    {
        return $catalog->entries()->create($extra + ['source_key' => 'source:'.uniqid(), 'product_id' => $product->id, 'kind' => $kind, 'price' => $price, 'customer_group_id' => $group?->id, 'user_id' => $customer?->id, 'minimum_quantity' => 1, 'priority' => 0, 'is_active' => true]);
    }

    private function data(Product $product, CustomerGroup $group, string $price = '80'): array
    {
        return ['product_id' => $product->id, 'kind' => 'group', 'customer_group_id' => $group->id, 'user_id' => null, 'minimum_quantity' => 1, 'price' => $price, 'priority' => 0, 'starts_at' => '', 'ends_at' => '', 'is_active' => true];
    }
}
