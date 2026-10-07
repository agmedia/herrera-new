<?php

namespace Tests\Feature\Integrations;

use App\Models\Catalog\Product\Product;
use App\Services\Integrations\Stock\StockFeedClient;
use App\Services\Integrations\Stock\StockSyncService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class StockSyncFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_supplier_stock_uses_original_sku_and_preserves_own_stock_and_unlisted_products(): void
    {
        $this->configure('braytron');
        $first = $this->product('first', ['sku' => 'adjusted-oc-1', 'stock_qty' => 5, 'supplier_stock_qty' => 2, 'payload' => ['opencart' => ['product_id' => 1, 'sku' => '00123']]]);
        $duplicate = $this->product('duplicate', ['sku' => '00123-oc-2', 'payload' => ['opencart' => ['product_id' => 2, 'sku' => '00123']]]);
        $unchanged = $this->product('unchanged', ['sku' => 'SAME', 'supplier_stock_qty' => 9]);
        $unlisted = $this->product('unlisted', ['sku' => 'MISSING', 'supplier_stock_qty' => 8]);
        $this->feed('braytron', [['identifier' => '00123', 'quantity' => 7], ['identifier' => 'SAME', 'quantity' => 9], ['identifier' => 'NO-MATCH', 'quantity' => 3]]);

        $run = app(StockSyncService::class)->run('braytron', 'manual', 12);

        $this->assertSame('completed', $run->status);
        $this->assertSame(12, $run->actor_id);
        $this->assertSame(3, $run->matched_count);
        $this->assertSame(2, $run->updated_count);
        $this->assertSame(1, $run->unchanged_count);
        $this->assertSame(1, $run->unmatched_count);
        $this->assertSame(5, $first->fresh()->stock_qty);
        $this->assertSame(7, $first->fresh()->supplier_stock_qty);
        $this->assertSame(7, $duplicate->fresh()->supplier_stock_qty);
        $this->assertSame(9, $unchanged->fresh()->supplier_stock_qty);
        $this->assertSame(8, $unlisted->fresh()->supplier_stock_qty);
        $this->assertDatabaseHas('stock_sync_items', ['run_id' => $run->id, 'product_id' => $first->id, 'old_quantity' => 2, 'new_quantity' => 7, 'status' => 'updated']);
        $this->assertCount(4, $run->items);
    }

    public function test_vayox_matches_original_ean_even_if_import_removed_a_duplicate_barcode(): void
    {
        $this->configure('vayox');
        $product = $this->product('ean', ['sku' => 'WRONG', 'barcode' => null, 'payload' => ['opencart' => ['product_id' => 1, 'ean' => '0012345678901']]]);
        $blank = $this->product('blank', ['barcode' => 'WRONG', 'supplier_stock_qty' => 3, 'payload' => ['opencart' => ['product_id' => 2, 'ean' => '']]]);
        $this->feed('vayox', [['identifier' => '0012345678901', 'quantity' => 4]]);
        app(StockSyncService::class)->run('vayox');
        $this->assertSame(4, $product->fresh()->supplier_stock_qty);
        $this->assertSame(3, $blank->fresh()->supplier_stock_qty);
    }

    public function test_erp_snapshot_matches_model_and_zeros_missing_imported_stock_while_preserving_native_and_supplier_stock(): void
    {
        $this->configure('eracuni');
        $matched = $this->product('erp-match', ['stock_qty' => 6, 'supplier_stock_qty' => 12, 'payload' => ['opencart' => ['product_id' => 1, 'model' => 'ERP-1']]]);
        $missing = $this->product('erp-missing', ['stock_qty' => 8, 'supplier_stock_qty' => 13, 'payload' => ['opencart' => ['product_id' => 2, 'model' => 'ERP-2']]]);
        $native = $this->product('native', ['stock_qty' => 15]);
        $this->feed('eracuni', [['identifier' => 'ERP-1', 'quantity' => 2]]);
        $run = app(StockSyncService::class)->run('eracuni', 'cron');
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $matched->fresh()->stock_qty);
        $this->assertSame(0, $missing->fresh()->stock_qty);
        $this->assertSame(12, $matched->fresh()->supplier_stock_qty);
        $this->assertSame(13, $missing->fresh()->supplier_stock_qty);
        $this->assertSame(15, $native->fresh()->stock_qty);
        $this->assertSame(1, $run->summary['reset_count']);
    }

    public function test_unrelated_erp_feed_rolls_back_all_stock_changes_and_report_items(): void
    {
        $this->configure('eracuni');
        $product = $this->product('protected', ['stock_qty' => 7, 'payload' => ['opencart' => ['product_id' => 1, 'model' => 'ERP-1']]]);
        $this->feed('eracuni', [['identifier' => 'UNKNOWN', 'quantity' => 9]]);
        $run = app(StockSyncService::class)->run('eracuni');
        $this->assertSame('failed', $run->status);
        $this->assertSame(7, $product->fresh()->stock_qty);
        $this->assertSame(0, $run->updated_count);
        $this->assertCount(0, $run->items);
    }

    public function test_silently_capped_erp_stock_response_cannot_zero_missing_imported_products(): void
    {
        $this->configure('eracuni');
        $matched = $this->product('match-cap', ['stock_qty' => 7, 'payload' => ['opencart' => ['product_id' => 1, 'model' => 'ERP-1']]]);
        $missing = $this->product('missing-cap', ['stock_qty' => 8, 'payload' => ['opencart' => ['product_id' => 2, 'model' => 'ERP-MISSING']]]);
        $rows = [];
        for ($i = 0; $i < 10000; $i++) {
            $rows[] = ['StockQuantityInfo' => ['productCode' => $i === 0 ? 'ERP-1' : 'CODE-'.$i, 'quantityOnStock' => 2]];
        }
        Http::fake(['feeds.example/*' => Http::response(['response' => ['result' => $rows]])]);

        $run = app(StockSyncService::class)->run('eracuni');

        $this->assertSame('failed', $run->status);
        $this->assertSame(7, $matched->fresh()->stock_qty);
        $this->assertSame(8, $missing->fresh()->stock_qty);
        $this->assertCount(0, $run->items);
        Http::assertSentCount(1);
    }

    public function test_new_erp_and_csv_drafts_keep_the_source_model_for_later_warehouse_crons(): void
    {
        $this->configure('eracuni');
        $erp = $this->product('eracuni-hash', ['payload' => ['eracuni' => ['productCode' => '00001']]]);
        $csv = $this->product('ideus-hash', ['payload' => ['ideus_csv' => ['model' => '00002']]]);
        $removed = $this->product('ideus-removed', ['stock_qty' => 8, 'payload' => ['ideus_csv' => ['model' => '00003']]]);
        $this->feed('eracuni', [['identifier' => '00001', 'quantity' => 2], ['identifier' => '00002', 'quantity' => 3]]);

        $run = app(StockSyncService::class)->run('eracuni');

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $erp->fresh()->stock_qty);
        $this->assertSame(3, $csv->fresh()->stock_qty);
        $this->assertSame(0, $removed->fresh()->stock_qty);
    }

    public function test_invalid_and_conflicting_snapshots_never_change_stock(): void
    {
        $this->configure('master');
        $product = $this->product('master', ['sku' => 'SKU', 'supplier_stock_qty' => 8]);
        foreach ([
            ['rows' => [], 'complete' => true],
            ['rows' => [['identifier' => 'SKU', 'quantity' => 1]], 'complete' => false],
            ['rows' => [['identifier' => 'SKU', 'quantity' => 1]], 'complete' => true, 'invalid_count' => 1],
            ['rows' => [['identifier' => 'SKU', 'quantity' => 1], ['identifier' => 'SKU', 'quantity' => 2]], 'complete' => true],
        ] as $feed) {
            $this->mock(StockFeedClient::class)->shouldReceive('fetch')->once()->with('master')->andReturn($feed);
            $run = app(StockSyncService::class)->run('master');
            $this->assertSame('failed', $run->status);
            $this->assertSame(8, $product->fresh()->supplier_stock_qty);
            $this->assertCount(0, $run->items);
        }
    }

    public function test_failed_request_has_a_safe_report_and_can_run_again(): void
    {
        $this->configure('master');
        $this->mock(StockFeedClient::class)->shouldReceive('fetch')->once()->andThrow(new RuntimeException('https://secret.example/?token=SECRET'));
        $run = app(StockSyncService::class)->run('master', 'cron');
        $this->assertSame('failed', $run->status);
        $this->assertNotNull($run->completed_at);
        $this->assertStringNotContainsString('SECRET', $run->error_message);
        $this->assertTrue(Cache::lock('herrera-stock-sync:supplier_stock_qty', 600)->get());
    }

    public function test_automatic_disabled_source_can_be_run_manually(): void
    {
        $this->configure('dpm');
        $product = $this->product('dpm', ['barcode' => '00123']);
        $this->feed('dpm', [['identifier' => '00123', 'quantity' => 3]]);
        $this->assertFalse(app(StockSyncSettingsService::class)->enabled('dpm'));
        $this->assertSame('completed', app(StockSyncService::class)->run('dpm')->status);
        $this->assertSame(3, $product->fresh()->supplier_stock_qty);
        $this->expectException(RuntimeException::class);
        app(StockSyncService::class)->run('dpm', 'cron');
    }

    public function test_lock_rejects_overlap_without_creating_a_run_or_fetching_feed(): void
    {
        $this->configure('master');
        $lock = Cache::lock('herrera-stock-sync:supplier_stock_qty', 600);
        $lock->get();
        $this->mock(StockFeedClient::class)->shouldNotReceive('fetch');
        try {
            app(StockSyncService::class)->run('master');
            $this->fail('Expected busy rejection');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('stock_sync_runs', 0);
        } finally {
            $lock->release();
        }
    }

    public function test_public_cron_requires_correct_source_token_and_does_not_run_on_head(): void
    {
        $settings = $this->configure('master');
        $this->mock(StockFeedClient::class)->shouldNotReceive('fetch');
        $this->get('/integrations/stock/master')->assertForbidden();
        $this->get('/integrations/stock/master?token=wrong')->assertForbidden();
        $this->get(str_replace('/master?', '/videx?', $settings->cronUrl('master')))->assertForbidden();
        $this->head($settings->cronUrl('master'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('stock_sync_runs', 0);
    }

    public function test_cron_returns_completed_report_after_saving_stock_and_never_requires_admin_login(): void
    {
        $settings = $this->configure('master');
        $product = $this->product('public-cron', ['sku' => 'TEST']);
        $this->feed('master', [['identifier' => 'TEST', 'quantity' => 6]]);
        $this->get($settings->cronUrl('master'))->assertOk()->assertJsonPath('status', 'completed')
            ->assertJsonPath('updated', 1)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(6, $product->fresh()->supplier_stock_qty);
        $this->assertDatabaseHas('stock_sync_runs', ['supplier' => 'master', 'trigger' => 'cron', 'actor_id' => null, 'status' => 'completed']);
    }

    public function test_failed_cron_returns_error_status_for_easycron_and_keeps_stock(): void
    {
        $settings = $this->configure('master');
        $product = $this->product('failure', ['stock_qty' => 5, 'supplier_stock_qty' => 7]);
        $this->mock(StockFeedClient::class)->shouldReceive('fetch')->andThrow(new RuntimeException('SECRET'));
        $this->get($settings->cronUrl('master'))->assertStatus(502)->assertJsonPath('status', 'failed')->assertDontSee('SECRET');
        $this->assertSame(7, $product->fresh()->supplier_stock_qty);
        $this->assertSame(5, $product->fresh()->stock_qty);
    }

    public function test_settings_are_encrypted_tokens_stable_and_missing_integrations_are_unconfigured(): void
    {
        $settings = $this->configure('master');
        $url = $settings->cronUrl('master');
        $settings->ensureToken('master');
        $this->assertSame($url, $settings->cronUrl('master'));
        $stored = app(SystemSettingsService::class)->all();
        $this->assertStringNotContainsString('feeds.example', $stored['stock_sync_master_connection']);
        $this->assertFalse($settings->configured('brock'));
        $this->assertFalse($settings->configured('eracuni'));
        $this->assertFalse($settings->validToken('master', ''));
    }

    private function configure(string $supplier): StockSyncSettingsService
    {
        $settings = app(StockSyncSettingsService::class);
        $settings->saveConnection($supplier, ['url' => 'https://feeds.example/'.$supplier, 'username' => 'user', 'password' => 'password', 'token' => 'token']);

        return $settings;
    }

    private function product(string $code, array $attributes = []): Product
    {
        return Product::query()->create($attributes + ['code' => $code, 'sku' => $code, 'stock_qty' => 0, 'supplier_stock_qty' => 0]);
    }

    private function feed(string $supplier, array $rows): void
    {
        $this->mock(StockFeedClient::class)->shouldReceive('fetch')->once()->with($supplier)
            ->andReturn(['rows' => $rows, 'invalid_count' => 0, 'skipped_count' => 0, 'complete' => true]);
    }
}
