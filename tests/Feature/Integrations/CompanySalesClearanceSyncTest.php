<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductClearance;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\User\CustomerGroup;
use App\Services\Catalog\Clearance\ClearanceService;
use App\Services\Catalog\Clearance\CompanySalesClearanceSync;
use App\Services\Integrations\Eracuni\EracuniCompanySalesClient;
use App\Services\Integrations\Stock\StockFeedClient;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use App\Services\Settings\SystemSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CompanySalesClearanceSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        app(StockSyncSettingsService::class)->saveConnection('eracuni', [
            'url' => 'https://erp.example.test/api', 'username' => 'test-user', 'password' => 'test-password', 'token' => 'test-token',
        ]);
    }

    public function test_dry_run_preserves_products_and_existing_outgoing_evidence(): void
    {
        $product = $this->product('ELIGIBLE');
        $old = $this->observe($this->product('OMITTED'));
        $beforeProduct = $product->toArray();
        $beforeEvidence = $old->toArray();
        $this->sources([['identifier' => 'ELIGIBLE', 'quantity' => 20]]);

        app(CompanySalesClearanceSync::class)->run();

        $this->assertSame($beforeProduct, $product->fresh()->toArray());
        $this->assertSame($beforeEvidence, $old->fresh()->toArray());
        $this->assertDatabaseCount('product_clearances', 1);
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
        Http::assertNothingSent();
    }

    public function test_erp_fetch_and_candidate_proof_use_configured_inactivity_period(): void
    {
        $product = $this->product('CUSTOM-PERIOD');
        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'clearance']);
        app(ClearanceService::class)->saveSettings(['enabled' => false, 'category_id' => $category->id,
            'inactivity_days' => 180, 'discount_percent' => 25]);
        $this->sources([['identifier' => 'CUSTOM-PERIOD', 'quantity' => 10]]);

        $result = app(CompanySalesClearanceSync::class)->run(true);

        $this->assertSame(180, $result['inactivity_days']);
        $this->assertSame(today()->subDays(180)->toDateString(), $result['coverage_from']);
        $service = app(ClearanceService::class);
        $service->saveSettings(['enabled' => true, 'category_id' => $category->id]);
        $this->assertSame(1, $service->run(true)['activated']);
        $this->assertEquals(75, $service->price($product->fresh()));
    }

    public function test_large_payload_catalogue_stays_bounded_and_preserves_cross_chunk_identity_and_snapshot_rules(): void
    {
        $shadowed = $this->product('EARLY', ['payload' => ['eracuni' => ['productCode' => 'SHARED']]]);
        $largeMetadata = str_repeat('x', 128 * 1024);
        $paddingPayload = json_encode(['unused_import_metadata' => $largeMetadata], JSON_THROW_ON_ERROR);
        for ($batch = 0; $batch < 12; $batch++) {
            $rows = [];
            for ($offset = 0; $offset < 50; $offset++) {
                $code = 'PADDING-'.($batch * 50 + $offset);
                $rows[] = ['code' => $code, 'sku' => $code, 'base_price' => 100, 'is_active' => true, 'stock_qty' => 0, 'payload' => $paddingPayload];
            }
            DB::table('products')->insert($rows);
        }
        unset($rows, $paddingPayload);
        $this->product('LATE-DUPLICATE', ['stock_qty' => 0, 'payload' => ['eracuni' => ['productCode' => 'SHARED']]]);
        $eligible = $this->product('ELIGIBLE', ['payload' => ['eracuni' => ['productCode' => 'ERP-ELIGIBLE'], 'unused_import_metadata' => $largeMetadata]]);
        $recent = $this->product('RECENT', ['payload' => ['eracuni' => ['productCode' => 'ERP-RECENT'], 'opencart' => ['model' => 'RECENT-ALIAS'], 'unused_import_metadata' => $largeMetadata]]);
        $zero = $this->product('ZERO', ['payload' => ['eracuni' => ['productCode' => 'ERP-ZERO'], 'unused_import_metadata' => $largeMetadata]]);
        $omitted = $this->observe($this->product('OMITTED'));
        unset($largeMetadata);
        $this->sources([
            ['identifier' => 'SHARED', 'quantity' => 20],
            ['identifier' => 'ERP-ELIGIBLE', 'quantity' => 20],
            ['identifier' => 'ERP-RECENT', 'quantity' => 20],
            ['identifier' => 'ERP-ZERO', 'quantity' => 0],
        ], ['recent_product_codes' => ['RECENT-ALIAS']], 2);
        $service = app(CompanySalesClearanceSync::class);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memoryBefore = memory_get_usage(true);

        $preview = $service->run();

        $this->assertLessThan(24 * 1024 * 1024, memory_get_peak_usage(true) - $memoryBefore, 'The 75 MB catalogue payload must be processed in bounded chunks.');
        $this->assertSame(1, $preview['observed']);
        $this->assertSame(1, $preview['recent']);
        $this->assertSame($service->productFingerprint($zero), $preview['zero_stock_product_proofs'][$zero->id]);
        $this->assertTrue($omitted->fresh()->confirmed_company_scope);
        $this->assertDatabaseCount('product_clearances', 1);

        $service->run(true);

        $this->assertTrue(ProductClearance::query()->where('product_id', $eligible->id)->sole()->confirmed_company_scope);
        $this->assertFalse($omitted->fresh()->confirmed_company_scope);
        foreach ([$shadowed, $recent, $zero] as $excluded) {
            $this->assertDatabaseMissing('product_clearances', ['product_id' => $excluded->id]);
        }
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
        Http::assertNothingSent();
    }

    public function test_only_unambiguous_products_with_positive_current_erp_and_local_own_stock_receive_proof(): void
    {
        $eligible = $this->product('LOCAL', ['payload' => ['eracuni' => ['productCode' => 'ERP-001']]]);
        $missing = $this->product('MISSING');
        $erpZero = $this->product('ERP-ZERO');
        $localZero = $this->product('LOCAL-ZERO', ['stock_qty' => 0, 'supplier_stock_qty' => 100000]);
        $ambiguousA = $this->product('SHARED-A', ['payload' => ['eracuni' => ['productCode' => 'SHARED']]]);
        $ambiguousB = $this->product('SHARED-B', ['payload' => ['eracuni' => ['productCode' => 'SHARED']]]);
        $this->sources([
            ['identifier' => 'ERP-001', 'quantity' => 20],
            ['identifier' => 'ERP-ZERO', 'quantity' => 0],
            ['identifier' => 'LOCAL-ZERO', 'quantity' => 20],
            ['identifier' => 'SHARED', 'quantity' => 20],
            ['identifier' => 'ERP-ONLY', 'quantity' => 20],
        ]);

        app(CompanySalesClearanceSync::class)->run(true);

        $evidence = ProductClearance::query()->sole();
        $this->assertSame($eligible->id, $evidence->product_id);
        $this->assertTrue($evidence->confirmed_company_scope);
        $this->assertTrue($evidence->snapshot_date->isSameDay(today()));
        $this->assertTrue($evidence->coverage_from->lte(today()->subDays(100)));
        $this->assertNull($evidence->last_outgoing_at);
        foreach ([$missing, $erpZero, $localZero, $ambiguousA, $ambiguousB] as $excluded) {
            $this->assertDatabaseMissing('product_clearances', ['product_id' => $excluded->id]);
        }
        $this->assertSame(10, $eligible->fresh()->stock_qty);
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
    }

    #[DataProvider('recentAliases')]
    public function test_recent_sales_on_any_product_alias_cannot_become_inactivity_proof(string $recentCode): void
    {
        $product = $this->product('LOCAL', ['payload' => [
            'eracuni' => ['productCode' => 'ERP-001'],
            'opencart' => ['model' => 'LEGACY-001'],
            'ideus_csv' => ['model' => 'IDEUS-001'],
        ]]);
        $this->sources([['identifier' => 'ERP-001', 'quantity' => 20]], ['recent_product_codes' => [$recentCode]]);

        app(CompanySalesClearanceSync::class)->run(true);

        $evidence = ProductClearance::query()->where('product_id', $product->id)->first();
        if ($evidence) {
            $this->assertNotNull($evidence->last_outgoing_at);
            $this->assertTrue($evidence->last_outgoing_at->gt(today()->subDays(100)));
        }
        $this->assertNull(data_get($product->fresh()->payload, 'clearance.status'));
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
    }

    public static function recentAliases(): array
    {
        return ['ERP code' => ['ERP-001'], 'old model' => ['LEGACY-001'], 'CSV model' => ['IDEUS-001'], 'local code' => ['LOCAL']];
    }

    public function test_source_failure_cannot_apply_an_earlier_today_snapshot(): void
    {
        $product = $this->product('ELIGIBLE');
        $evidence = $this->observe($product, ['source' => CompanySalesClearanceSync::SOURCE]);
        $this->enable();
        $this->mock(EracuniCompanySalesClient::class)->shouldReceive('fetch')->once()->andThrow(new RuntimeException('Incomplete ERP source'));
        $this->mock(StockFeedClient::class)->shouldReceive('fetch')->with('eracuni')->zeroOrMoreTimes()->andReturn($this->stock([['identifier' => 'ELIGIBLE', 'quantity' => 20]]));

        try {
            app(CompanySalesClearanceSync::class)->run(true, true);
        } catch (RuntimeException) {
        }

        $this->assertFalse($evidence->fresh()->confirmed_company_scope);
        $this->assertSame('observed', $evidence->fresh()->status);
        $this->assertSame(str_repeat('a', 64), $evidence->fresh()->source_fingerprint);
        $this->assertNull(data_get($product->fresh()->payload, 'clearance.status'));
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
    }

    public function test_successful_replacement_invalidates_omitted_observed_proof(): void
    {
        $included = $this->product('INCLUDED');
        $omitted = $this->observe($this->product('OMITTED'));
        $this->sources([['identifier' => 'INCLUDED', 'quantity' => 20]]);

        app(CompanySalesClearanceSync::class)->run(true);

        $this->assertFalse($omitted->fresh()->confirmed_company_scope);
        $this->assertSame('observed', $omitted->fresh()->status);
        $this->assertTrue(ProductClearance::query()->where('product_id', $included->id)->sole()->confirmed_company_scope);
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
    }

    #[DataProvider('unprovenStockSnapshots')]
    public function test_unproven_current_stock_cannot_activate_clearance(array $stockOverrides): void
    {
        $product = $this->product('ELIGIBLE');
        $evidence = $this->observe($product, ['source' => CompanySalesClearanceSync::SOURCE]);
        $this->enable();
        $this->sources([['identifier' => 'ELIGIBLE', 'quantity' => 20]], stockOverrides: $stockOverrides);

        try {
            $this->expectException(RuntimeException::class);
            app(CompanySalesClearanceSync::class)->run(true, true);
        } finally {
            $this->assertFalse($evidence->fresh()->confirmed_company_scope);
            $this->assertSame('observed', $evidence->fresh()->status);
            $this->assertSame(10, $product->fresh()->stock_qty);
            $this->assertNull(data_get($product->fresh()->payload, 'clearance.status'));
            $this->assertDatabaseCount('catalog_product_group_prices', 0);
        }
    }

    public static function unprovenStockSnapshots(): array
    {
        return [
            'incomplete snapshot' => [['complete' => false]],
            'invalid rows omitted upstream' => [['invalid_count' => 1]],
            'empty snapshot' => [['rows' => []]],
            'conflicting duplicate code' => [['rows' => [['identifier' => 'ELIGIBLE', 'quantity' => 20], ['identifier' => 'ELIGIBLE', 'quantity' => 0]]]],
            'negative stock' => [['rows' => [['identifier' => 'ELIGIBLE', 'quantity' => -1]]]],
        ];
    }

    public function test_refresh_preserves_sold_out_and_manual_override_statuses_and_existing_prices(): void
    {
        $active = $this->product('ACTIVE', ['payload' => ['clearance' => ['status' => 'active']]]);
        $sold = $this->product('SOLD', ['is_active' => false, 'payload' => ['clearance' => ['status' => 'sold_out']]]);
        $manual = $this->product('MANUAL', ['payload' => ['clearance' => ['status' => 'manual_override']]]);
        $activeEvidence = $this->observe($active, ['status' => 'active']);
        $soldEvidence = $this->observe($sold, ['status' => 'sold_out']);
        $manualEvidence = $this->observe($manual, ['status' => 'manual_override']);
        $group = CustomerGroup::query()->create(['code' => 'B2B60', 'name' => 'B2B60', 'is_active' => true]);
        $price = ProductGroupPrice::query()->create(['product_id' => $active->id, 'customer_group_id' => $group->id, 'minimum_quantity' => 1, 'price' => 40, 'currency_code' => 'EUR', 'is_active' => true, 'payload' => ['source' => 'automatic_clearance']])->fresh();
        $beforePrice = $price->toArray();
        $beforeProducts = [$active->toArray(), $sold->toArray(), $manual->toArray()];
        $this->sources([
            ['identifier' => 'ACTIVE', 'quantity' => 20],
            ['identifier' => 'SOLD', 'quantity' => 20],
            ['identifier' => 'MANUAL', 'quantity' => 20],
        ]);

        app(CompanySalesClearanceSync::class)->run(true);

        $this->assertSame('active', $activeEvidence->fresh()->status);
        $this->assertSame('sold_out', $soldEvidence->fresh()->status);
        $this->assertSame('manual_override', $manualEvidence->fresh()->status);
        $this->assertSame($beforePrice, $price->fresh()->toArray());
        $this->assertSame($beforeProducts, [$active->fresh()->toArray(), $sold->fresh()->toArray(), $manual->fresh()->toArray()]);
    }

    public function test_clearance_runs_only_after_fresh_applied_sync_when_requested_and_enabled(): void
    {
        $product = $this->product('ELIGIBLE');
        $evidence = $this->observe($product);
        $this->enable();
        $this->sources([['identifier' => 'ELIGIBLE', 'quantity' => 20]], [], 2);
        $service = app(CompanySalesClearanceSync::class);

        try {
            $service->run(false, true);
            $this->fail('A preview must never apply clearance.');
        } catch (RuntimeException) {
        }
        $this->assertSame(str_repeat('a', 64), $evidence->fresh()->source_fingerprint);
        $this->assertDatabaseCount('catalog_product_group_prices', 0);

        $service->run(true, false);
        $this->assertNotSame(str_repeat('a', 64), $evidence->fresh()->source_fingerprint);
        $this->assertDatabaseCount('catalog_product_group_prices', 0);

        $service->run(true, true);
        $this->assertSame('active', $evidence->fresh()->status);
        $this->assertSame('active', data_get($product->fresh()->payload, 'clearance.status'));
        $this->assertEquals(40, ProductGroupPrice::query()->sole()->price);
    }

    public function test_sync_does_not_enable_clearance_or_discount_products_implicitly(): void
    {
        $product = $this->product('ELIGIBLE');
        $this->sources([['identifier' => 'ELIGIBLE', 'quantity' => 20]]);

        app(CompanySalesClearanceSync::class)->run(true, true);

        $this->assertFalse(app(ClearanceService::class)->settings()['enabled']);
        $this->assertSame('observed', ProductClearance::query()->where('product_id', $product->id)->sole()->status);
        $this->assertNull(data_get($product->fresh()->payload, 'clearance.status'));
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
    }

    public function test_current_erp_zero_retires_active_clearance_without_overwriting_local_inventory(): void
    {
        $product = $this->product('SOLD', ['supplier_stock_qty' => 100000]);
        $evidence = $this->observe($product);
        $this->enable();
        $this->assertSame(1, app(ClearanceService::class)->run(true)['activated']);
        $this->sources([['identifier' => 'SOLD', 'quantity' => 0]]);

        $result = app(CompanySalesClearanceSync::class)->run(true, true);

        $this->assertSame(1, $result['clearance']['retired']);
        $this->assertSame('sold_out', $evidence->fresh()->status);
        $this->assertFalse($product->fresh()->is_active);
        $this->assertSame('sold_out', data_get($product->fresh()->payload, 'clearance.status'));
        $this->assertSame(10, $product->fresh()->stock_qty);
        $this->assertSame(100000, $product->fresh()->supplier_stock_qty);
        $this->assertFalse(ProductGroupPrice::query()->sole()->is_active);
    }

    public function test_failed_source_and_stale_zero_snapshot_cannot_retire_active_clearance(): void
    {
        $product = $this->product('ACTIVE');
        $evidence = $this->observe($product);
        $this->enable();
        $clearance = app(ClearanceService::class);
        $this->assertSame(1, $clearance->run(true)['activated']);
        app(SystemSettingsService::class)->put(CompanySalesClearanceSync::STATE_KEY, [
            'status' => 'completed', 'as_of' => today()->subDay()->toDateString(),
            'fingerprint' => str_repeat('a', 64), 'zero_stock_product_ids' => [$product->id],
        ]);
        $this->assertSame(0, $clearance->run(true)['retired']);
        $this->mock(EracuniCompanySalesClient::class)->shouldReceive('fetch')->once()->andThrow(new RuntimeException('Incomplete ERP source'));
        $this->mock(StockFeedClient::class)->shouldReceive('fetch')->with('eracuni')->zeroOrMoreTimes()->andReturn($this->stock([['identifier' => 'ACTIVE', 'quantity' => 0]]));

        try {
            app(CompanySalesClearanceSync::class)->run(true, true);
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $clearance->run(true)['retired']);
        $this->assertSame('active', $evidence->fresh()->status);
        $this->assertTrue($product->fresh()->is_active);
        $this->assertSame('active', data_get($product->fresh()->payload, 'clearance.status'));
        $this->assertSame(10, $product->fresh()->stock_qty);
        $this->assertTrue(ProductGroupPrice::query()->sole()->is_active);
    }

    public function test_api_proof_requires_matching_completed_current_sync_state(): void
    {
        $product = $this->product('ELIGIBLE');
        $this->observe($product, ['source' => CompanySalesClearanceSync::SOURCE]);
        $this->enable();
        $settings = app(SystemSettingsService::class);
        $clearance = app(ClearanceService::class);
        foreach ([
            [],
            ['status' => 'completed', 'as_of' => today()->toDateString(), 'fingerprint' => str_repeat('b', 64)],
            ['status' => 'completed', 'as_of' => today()->subDay()->toDateString(), 'fingerprint' => str_repeat('a', 64)],
            ['status' => 'running', 'as_of' => today()->toDateString(), 'fingerprint' => str_repeat('a', 64)],
        ] as $unprovenState) {
            $settings->put(CompanySalesClearanceSync::STATE_KEY, $unprovenState);
            $this->assertSame(0, $clearance->run(true)['activated']);
            $this->assertDatabaseCount('catalog_product_group_prices', 0);
            $this->assertNull(data_get($product->fresh()->payload, 'clearance.status'));
        }

        $settings->put(CompanySalesClearanceSync::STATE_KEY, [
            'status' => 'completed', 'as_of' => today()->toDateString(), 'fingerprint' => str_repeat('a', 64),
        ]);
        $this->assertSame(1, $clearance->run(true)['activated']);
        $this->assertSame('active', data_get($product->fresh()->payload, 'clearance.status'));
    }

    private function sources(array $stockRows, array $salesOverrides = [], int $times = 1, array $stockOverrides = []): void
    {
        $days = app(ClearanceService::class)->inactivityDays();
        $this->mock(EracuniCompanySalesClient::class)->shouldReceive('fetch')->times($times)
            ->withArgs(fn ($from, $asOf) => CarbonImmutable::parse($from)->isSameDay(today()->subDays($days)) && CarbonImmutable::parse($asOf)->isSameDay(today()))
            ->andReturn(array_replace([
                'complete' => true, 'coverage_from' => today()->subDays($days)->toDateString(), 'as_of' => today()->toDateString(),
                'recent_product_codes' => ['RECENT'], 'document_count' => 3, 'request_count' => 8,
                'sources' => ['SalesInvoiceList', 'POSSalesInvoiceList', 'DeliveryNoteList'], 'fingerprint' => str_repeat('b', 64),
            ], $salesOverrides));
        $this->mock(StockFeedClient::class)->shouldReceive('fetch')->with('eracuni')->times($times)->andReturn(array_replace($this->stock($stockRows), $stockOverrides));
    }

    private function stock(array $rows): array
    {
        return ['rows' => $rows, 'complete' => true, 'invalid_count' => 0, 'skipped_count' => 0, 'clamped_count' => 0, 'duplicate_count' => 0];
    }

    private function product(string $code, array $extra = []): Product
    {
        return Product::query()->create(array_replace(['code' => $code, 'sku' => $code, 'base_price' => 100, 'is_active' => true, 'stock_qty' => 10, 'minimum_order_quantity' => 1, 'order_quantity_step' => 1, 'payload' => ['eracuni' => ['productCode' => $code]]], $extra))->fresh();
    }

    private function observe(Product $product, array $extra = []): ProductClearance
    {
        return ProductClearance::query()->create(array_replace(['product_id' => $product->id, 'source' => 'eracuni_company_export', 'source_fingerprint' => str_repeat('a', 64), 'coverage_from' => today()->subDays(120), 'snapshot_date' => today(), 'last_outgoing_at' => today()->subDays(100), 'confirmed_company_scope' => true], $extra))->fresh();
    }

    private function enable(): void
    {
        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'clearance']);
        app(ClearanceService::class)->saveSettings(['enabled' => true, 'category_id' => $category->id]);
    }
}
