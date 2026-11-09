<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Admin\Integrations\Stock\Clearance;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Pricing\PriceCatalog;
use App\Models\Catalog\Pricing\PriceCatalogEntry;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductClearance;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Catalog\Clearance\ClearanceService;
use App\Services\Catalog\Clearance\CompanyOutgoingImport;
use App\Services\Feeds\CustomerCatalogFeedAccess;
use App\Services\Front\CartService;
use App\Services\Pricing\PriceCatalogResolver;
use App\Services\Pricing\ProductPricePresentationService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ClearanceFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['commerce.b2b_only' => false]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
    }

    public function test_csv_preview_requires_explicit_company_scope_and_does_not_infer_webshop_sales(): void
    {
        $product = $this->product('ERP-1');
        $file = $this->report('ERP-1');
        try {
            $import = app(CompanyOutgoingImport::class);
            $this->assertSame(1, $import->run($file)['products']);
            $this->assertDatabaseCount('product_clearances', 0);
            try {
                $import->run($file, true);
                $this->fail('Unconfirmed scope must never be imported.');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('product_clearances', 0);
            }
            $import->run($file, true, true);
            $row = ProductClearance::query()->sole();
            $this->assertTrue($row->confirmed_company_scope);
            $this->assertSame('eracuni_company_export', $row->source);
            $this->assertSame('observed', $row->status);
            $this->assertFalse(isset($product->fresh()->payload['clearance']));
            Http::assertNothingSent();
        } finally {
            unlink($file);
        }
    }

    public function test_activation_requires_fresh_full_100_day_evidence_and_own_stock_and_is_idempotent(): void
    {
        $eligible = $this->product('ELIGIBLE');
        $recent = $this->product('RECENT');
        $stale = $this->product('STALE');
        $short = $this->product('SHORT');
        $supplierOnly = $this->product('SUPPLIER', ['stock_qty' => 0, 'supplier_stock_qty' => 99]);
        $inactive = $this->product('INACTIVE', ['is_active' => false]);
        $this->observe($eligible);
        $this->observe($recent, ['last_outgoing_at' => today()->subDays(99)]);
        $this->observe($stale, ['snapshot_date' => today()->subDay()]);
        $this->observe($short, ['coverage_from' => today()->subDays(99)]);
        $this->observe($supplierOnly);
        $this->observe($inactive);
        $service = $this->enable();
        $this->assertSame(1, $service->run()['eligible']);
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
        $this->assertSame(1, $service->run(true)['activated']);
        $this->assertSame('active', data_get($eligible->fresh()->payload, 'clearance.status'));
        $this->assertEquals(100, $eligible->fresh()->base_price);
        $this->assertEquals(40, ProductGroupPrice::query()->sole()->price);
        $this->assertSame('B2B60', ProductGroupPrice::query()->sole()->customerGroup->name);
        $this->assertTrue($eligible->fresh()->categories()->where('categories.code', 'clearance')->exists());
        $this->assertSame(0, $service->run(true)['activated']);
        $this->assertDatabaseCount('catalog_product_group_prices', 1);
    }

    public function test_automation_cannot_enable_without_current_company_report(): void
    {
        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'clearance']);
        $this->expectException(RuntimeException::class);
        app(ClearanceService::class)->saveSettings(['enabled' => true, 'category_id' => $category->id]);
    }

    public function test_products_with_variants_are_excluded_until_erp_proves_variant_stock_and_movements(): void
    {
        $product = $this->product('VARIANT');
        $this->observe($product);
        $option = Option::query()->create(['code' => 'size', 'type' => Option::TYPE_SELECT, 'is_active' => true]);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'large', 'is_active' => true]);
        $product->optionValues()->create(['option_value_id' => $value->id, 'combination_hash' => 'single:'.$value->id, 'stock_qty' => 10, 'is_active' => false]);
        $this->assertSame(0, $this->enable()->run(true)['activated']);
        $this->assertSame('observed', ProductClearance::query()->sole()->status);
    }

    public function test_clearance_uses_full_wholesale_once_and_cart_coupon_cannot_stack(): void
    {
        $product = $this->product('PRICE');
        $this->observe($product);
        $this->enable()->run(true);
        $product = $product->fresh();
        $user = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'contract', 'name' => 'Contract', 'is_active' => true]);
        $user->customerGroups()->attach($group);
        $user->b2bAccount()->create(['company_name' => 'Company', 'oib' => '11000000001', 'status' => 'approved', 'customer_group_id' => $group->id]);
        ProductGroupPrice::query()->create(['product_id' => $product->id, 'customer_group_id' => $group->id, 'minimum_quantity' => 1, 'price' => 20, 'currency_code' => 'EUR', 'is_active' => true]);
        CatalogAction::query()->create(['code' => 'other-discount', 'scope' => 'product', 'type' => 'percentage', 'discount_value' => 80, 'target_type' => 'all', 'audience_type' => 'all', 'is_active' => true]);
        CatalogAction::query()->create(['code' => 'coupon', 'scope' => 'cart', 'type' => 'percentage', 'discount_value' => 10, 'target_type' => 'all', 'audience_type' => 'all', 'coupon_code' => 'TEN', 'is_active' => true]);
        $price = app(ProductPricePresentationService::class)->forProduct($product, $user);
        $this->assertEquals(40, $price['current_net']);
        $this->assertSame('clearance', $price['price_source']);
        $this->actingAs($user);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, 2));
        $line = $cart->lines('hr', 'TEN')->sole();
        $this->assertEquals(40, $line['unit_price']);
        $this->assertSame('B2B60', $line['action_code']);
        $this->assertSame('clearance', $line['price_source']);
        $this->assertEquals(0, $cart->summary('hr', 'TEN')['cart_discount_total']);
    }

    public function test_sold_out_clearance_stays_inactive_despite_supplier_stock_and_can_be_manually_reactivated(): void
    {
        $product = $this->product('SOLD', ['supplier_stock_qty' => 100]);
        $this->observe($product);
        $service = $this->enable();
        $service->run(true);
        $product = $product->fresh();
        $this->assertSame(10, $product->availableStockQuantity());
        $product->update(['stock_qty' => 0]);
        $service->reconcile($product);
        $this->assertFalse($product->fresh()->is_active);
        $this->assertSame('sold_out', ProductClearance::query()->sole()->status);
        $this->assertSame(100, $product->fresh()->supplier_stock_qty);
        $product = $product->fresh();
        $product->update(['stock_qty' => 5]);
        $service->run(true);
        $this->assertFalse($product->fresh()->is_active);
        $product->update(['is_active' => true]);
        $service->recordManualReactivation($product);
        $this->assertSame('manual_override', ProductClearance::query()->sole()->status);
        $this->assertNull($service->price($product->fresh()));
        $service->run(true);
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_clearance_matches_export_full_base_even_when_product_and_customer_prices_differ(): void
    {
        $product = $this->product('BASE-200');
        $this->observe($product);
        $catalog = PriceCatalog::query()->create(['name' => 'Full wholesale', 'status' => 'draft', 'currency_code' => 'EUR']);
        PriceCatalogEntry::query()->create(['price_catalog_id' => $catalog->id, 'source_key' => 'base:'.$product->id, 'product_id' => $product->id, 'kind' => 'base', 'price' => 200, 'minimum_quantity' => 1, 'is_active' => true]);
        $catalog->update(['status' => 'active']);
        DB::table('catalog_price_catalog_state')->updateOrInsert(['id' => 1], ['price_catalog_id' => $catalog->id]);
        app(PriceCatalogResolver::class)->forgetActiveCatalog();
        $this->enable()->run(true);
        $product = $product->fresh();
        $this->assertEquals(100, $product->base_price);
        $this->assertEquals(200, ProductClearance::query()->sole()->base_price_at_activation);
        $this->assertEquals(80, ProductGroupPrice::query()->sole()->price);
        $prices = app(ProductPricePresentationService::class);
        $this->assertEquals(80, $prices->forProduct($product)['current_net']);
        $buyer = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'base-contract', 'name' => 'Contract', 'is_active' => true]);
        $buyer->customerGroups()->attach($group);
        $buyer->b2bAccount()->create(['company_name' => 'BASE Company', 'oib' => '11000000001', 'status' => 'approved', 'customer_group_id' => $group->id]);
        ProductGroupPrice::query()->create(['product_id' => $product->id, 'customer_group_id' => $group->id, 'minimum_quantity' => 1, 'price' => 20, 'currency_code' => 'EUR', 'is_active' => true]);
        $this->assertEquals(80, $prices->forProduct($product, $buyer)['current_net']);
        $this->assertEquals(200, $prices->forProduct($product, $buyer)['base_gross']);
        $this->actingAs($buyer);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($product, 1));
        $this->assertEquals(80, $cart->lines('hr')->sole()['unit_price']);
        $admin = User::factory()->create(['account_type' => 'staff']);
        Bouncer::assign('superadmin')->to($admin);
        $access = app(CustomerCatalogFeedAccess::class);
        $access->saveAssortment($buyer, $admin, [$product->id], false);
        $token = $access->issueToken($buyer, $admin);
        $xml = simplexml_load_string($this->get(route('feed.customer-wholesale', ['token' => $token]))->assertOk()->streamedContent());
        $this->assertSame('200.0000', (string) $xml->products->product->price);
    }

    public function test_unknown_erp_code_or_invalid_dates_cannot_partially_apply(): void
    {
        $this->product('ERP-1');
        $file = $this->report('ERP-1');
        file_put_contents($file, 'UNKNOWN,,'.today()->subDays(120)->toDateString().','.today()->toDateString()."\n", FILE_APPEND);
        try {
            $this->expectException(RuntimeException::class);
            app(CompanyOutgoingImport::class)->run($file, true, true);
        } finally {
            $this->assertDatabaseCount('product_clearances', 0);
            unlink($file);
        }
    }

    public function test_full_wholesale_bases_are_loaded_in_one_batch_for_multiple_clearance_candidates(): void
    {
        $catalog = PriceCatalog::query()->create(['name' => 'Batch wholesale', 'status' => 'draft', 'currency_code' => 'EUR']);
        for ($index = 0; $index < 3; $index++) {
            $product = $this->product('BATCH-'.$index);
            $this->observe($product);
            PriceCatalogEntry::query()->create(['price_catalog_id' => $catalog->id, 'source_key' => 'base:'.$product->id, 'product_id' => $product->id, 'kind' => 'base', 'price' => 200, 'minimum_quantity' => 1, 'is_active' => true]);
        }
        $catalog->update(['status' => 'active']);
        DB::table('catalog_price_catalog_state')->updateOrInsert(['id' => 1], ['price_catalog_id' => $catalog->id]);
        app(PriceCatalogResolver::class)->forgetActiveCatalog();
        $service = $this->enable();
        DB::enableQueryLog();
        try {
            $this->assertSame(3, $service->run(true)['activated']);
            $reads = collect(DB::getQueryLog())->filter(fn ($query) => str_contains(strtolower($query['query']), 'from "catalog_price_entries"'));
            $this->assertCount(1, $reads);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_clearance_admin_panel_is_forbidden_without_stock_permission(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(Clearance::class)->assertForbidden();
    }

    public function test_admin_can_change_percentage_and_days_without_stacking_or_reviving_sold_products(): void
    {
        $active = $this->product('CONFIG-ACTIVE');
        $candidate = $this->product('CONFIG-CANDIDATE', ['is_active' => false]);
        $sold = $this->product('CONFIG-SOLD');
        foreach ([$active, $candidate, $sold] as $product) {
            $this->observe($product);
        }
        $service = $this->enable();
        $service->run(true);
        $sold->refresh()->update(['stock_qty' => 0]);
        $service->reconcile($sold);
        $this->actingAs($admin = User::factory()->create(['account_type' => 'staff']));
        Bouncer::assign('superadmin')->to($admin);

        Livewire::test(Clearance::class)
            ->assertSee('Dana bez prodaje')->assertSee('Popust (%)')
            ->set('settings.inactivity_days', 180)
            ->set('settings.discount_percent', 35)
            ->call('saveSettings')->assertHasNoErrors();

        $this->assertSame(180, $service->inactivityDays());
        $this->assertSame(35.0, $service->discountPercent());
        $this->assertEquals(65, $service->price($active->fresh()));
        $this->assertEquals(65, ProductGroupPrice::query()->where('product_id', $active->id)->sole()->price);
        $this->assertEquals(35, data_get($active->fresh()->payload, 'clearance.discount_percent'));
        $this->assertFalse(ProductClearance::query()->where('product_id', $candidate->id)->sole()->confirmed_company_scope);
        $this->assertFalse($sold->fresh()->is_active);
        $this->assertSame('sold_out', ProductClearance::query()->where('product_id', $sold->id)->sole()->status);
        $candidate->update(['is_active' => true]);
        $this->assertSame(0, $service->run(true)['activated']);

        // A new report must cover the new threshold, not just be dated today.
        ProductClearance::query()->where('product_id', $candidate->id)->update([
            'coverage_from' => today()->subDays(180), 'last_outgoing_at' => null, 'confirmed_company_scope' => true,
        ]);
        $this->assertSame(1, $service->run(true)['activated']);
        $this->assertEquals(65, app(ProductPricePresentationService::class)->forProduct($candidate->fresh())['current_net']);
        $cart = app(CartService::class);
        $this->assertTrue($cart->add($active->fresh(), 1));
        $line = $cart->lines('hr')->sole();
        $this->assertEquals(65, $line['unit_price']);
        $this->assertSame('B2B35', $line['action_code']);
    }

    public function test_invalid_admin_rules_leave_settings_and_active_prices_unchanged(): void
    {
        $product = $this->product('INVALID-CONFIG');
        $this->observe($product);
        $service = $this->enable();
        $service->run(true);
        $this->actingAs($admin = User::factory()->create(['account_type' => 'staff']));
        Bouncer::assign('superadmin')->to($admin);
        foreach ([['inactivity_days', 0], ['inactivity_days', 366], ['inactivity_days', 2.5],
            ['discount_percent', 0], ['discount_percent', 100], ['discount_percent', 25.555]] as [$field, $value]) {
            Livewire::test(Clearance::class)->set('settings.'.$field, $value)->call('saveSettings')
                ->assertHasErrors(['settings.'.$field]);
        }
        $this->assertSame(100, $service->inactivityDays());
        $this->assertEquals(60, $service->discountPercent());
        $this->assertEquals(40, $service->price($product->fresh()));
    }

    private function enable(): ClearanceService
    {
        $category = Category::query()->firstOrCreate(['code' => 'clearance'], ['scope' => 'catalog']);
        $service = app(ClearanceService::class);
        $service->saveSettings(['enabled' => true, 'category_id' => $category->id]);

        return $service;
    }

    private function product(string $code, array $extra = []): Product
    {
        return Product::query()->create(array_replace(['code' => $code, 'sku' => $code, 'base_price' => 100, 'is_active' => true, 'stock_qty' => 10, 'minimum_order_quantity' => 1, 'order_quantity_step' => 1, 'payload' => ['eracuni' => ['productCode' => $code]]], $extra));
    }

    private function observe(Product $product, array $extra = []): ProductClearance
    {
        return ProductClearance::query()->create(array_replace(['product_id' => $product->id, 'source' => 'eracuni_company_export', 'source_fingerprint' => str_repeat('a', 64), 'coverage_from' => today()->subDays(120), 'snapshot_date' => today(), 'last_outgoing_at' => today()->subDays(100), 'confirmed_company_scope' => true], $extra));
    }

    private function report(string $code): string
    {
        $file = tempnam(sys_get_temp_dir(), 'clearance-test-');
        file_put_contents($file, "product_code,last_outgoing_at,coverage_from,as_of\n".$code.','.today()->subDays(100)->toDateString().','.today()->subDays(120)->toDateString().','.today()->toDateString()."\n");

        return $file;
    }
}
