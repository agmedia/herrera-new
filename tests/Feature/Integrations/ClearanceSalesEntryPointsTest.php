<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\SyncCompanySalesForClearance;
use App\Livewire\Admin\Integrations\Stock\Clearance;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductClearance;
use App\Models\User;
use App\Services\Catalog\Clearance\ClearanceService;
use App\Services\Catalog\Clearance\CompanySalesClearanceSync;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class ClearanceSalesEntryPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_authorized_refresh_queues_private_background_work_without_changing_products_or_prices(): void
    {
        $admin = $this->admin();
        $this->connection();
        $product = Product::query()->create(['code' => 'ERP-TEST', 'sku' => 'ERP-TEST', 'base_price' => 100, 'stock_qty' => 10, 'is_active' => true]);
        $before = $product->fresh()->toArray();
        $this->actingAs($admin);

        Livewire::test(Clearance::class)->call('refreshCompanySales')->assertHasNoErrors()
            ->assertSee('stavljena je u pozadinsku obradu')
            ->assertDontSee('test-private-password')->assertDontSee('test-private-token');

        Queue::assertPushed(SyncCompanySalesForClearance::class, fn ($job): bool => $job->actorId === $admin->id
            && $job->connection === 'database' && $job->queue === 'integrations');
        Queue::assertPushed(SyncCompanySalesForClearance::class, 1);
        $this->assertSame($before, $product->fresh()->toArray());
        $this->assertDatabaseCount('product_clearances', 0);
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
        Http::assertNothingSent();
    }

    public function test_refresh_rechecks_authorization_after_the_panel_has_already_been_opened(): void
    {
        $this->connection();
        $this->actingAs($this->admin());
        $panel = Livewire::test(Clearance::class);
        $this->actingAs(User::factory()->create());

        $panel->call('refreshCompanySales')->assertForbidden();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_missing_erp_connection_does_not_queue_a_job_or_expose_credentials(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Clearance::class)->call('refreshCompanySales')->assertHasNoErrors()
            ->assertSee('Najprije provjerite postojeći e-Računi pristup');

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_command_rejects_applying_clearance_without_saving_evidence_before_making_requests(): void
    {
        $this->artisan('herrera:sync-clearance-sales', ['--run-clearance' => true])
            ->expectsOutputToContain('prvo treba spremiti potvrđeni API pregled')->assertExitCode(1);

        Http::assertNothingSent();
        $this->assertDatabaseCount('product_clearances', 0);
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
    }

    public function test_command_enables_and_applies_clearance_only_after_full_company_api_and_stock_checks(): void
    {
        $this->connection();
        $product = Product::query()->create([
            'code' => 'ERP-ELIGIBLE', 'sku' => 'ERP-ELIGIBLE', 'base_price' => 100, 'stock_qty' => 10, 'is_active' => true,
            'payload' => ['eracuni' => ['productCode' => 'ERP-ELIGIBLE']],
        ]);
        Http::fake(fn ($request) => Http::response(['response' => ['result' => str_ends_with($request->url(), '/WarehouseGetArticleStockQuantity')
            ? [['StockQuantityInfo' => ['productCode' => 'ERP-ELIGIBLE', 'quantityOnStock' => 10]]]
            : []]]));

        $this->artisan('herrera:sync-clearance-sales', ['--apply' => true, '--enable' => true, '--run-clearance' => true])
            ->expectsOutputToContain('Automatska rasprodaja je uključena.')->assertSuccessful();

        $category = Category::query()->where('scope', 'catalog')->where('code', 'rasprodaja')->sole();
        $this->assertSame('Rasprodaja', $category->translations()->where('locale', 'hr')->sole()->name);
        $this->assertTrue(app(ClearanceService::class)->settings()['enabled']);
        $this->assertSame('active', data_get($product->fresh()->payload, 'clearance.status'));
        $this->assertDatabaseHas('catalog_product_group_prices', ['product_id' => $product->id, 'price' => 40]);
        Http::assertSentCount(9);

        $this->artisan('herrera:sync-clearance-sales', ['--apply' => true, '--enable' => true, '--run-clearance' => true])->assertSuccessful();
        $this->assertSame($category->id, Category::query()->where('scope', 'catalog')->where('code', 'rasprodaja')->sole()->id);
        $this->assertDatabaseCount('catalog_product_group_prices', 1);
        Http::assertSentCount(18);
    }

    public function test_command_does_not_create_a_clearance_category_or_enable_rules_after_an_api_failure(): void
    {
        $this->connection();
        Http::fake(fn () => Http::response(['response' => ['error' => 'upstream unavailable']], 503));

        $this->artisan('herrera:sync-clearance-sales', ['--apply' => true, '--enable' => true, '--run-clearance' => true])->assertExitCode(1);

        $this->assertFalse(app(ClearanceService::class)->settings()['enabled']);
        $this->assertDatabaseMissing('categories', ['code' => 'rasprodaja']);
        $this->assertDatabaseCount('product_clearances', 0);
        $this->assertDatabaseCount('catalog_product_group_prices', 0);
    }

    #[DataProvider('changesAfterZeroSnapshot')]
    public function test_a_zero_snapshot_cannot_retire_an_article_changed_after_the_snapshot(string $change): void
    {
        $product = $this->activeClearance();
        $fingerprint = str_repeat('e', 64);
        app(SystemSettingsService::class)->put(CompanySalesClearanceSync::STATE_KEY, [
            'status' => 'completed', 'source' => CompanySalesClearanceSync::SOURCE,
            'as_of' => today()->toDateString(), 'fingerprint' => $fingerprint,
            'zero_stock_product_ids' => [$product->id],
            'zero_stock_product_proofs' => [$product->id => app(CompanySalesClearanceSync::class)->productFingerprint($product)],
        ]);
        if ($change === 'identity') {
            $payload = $product->payload;
            $payload['eracuni']['productCode'] = 'DIFFERENT-ERP-ARTICLE';
            $product->update(['payload' => $payload]);
        } elseif ($change === 'stock') {
            $product->update(['stock_qty' => 25]);
        } else {
            DB::table('products')->where('id', $product->id)->update(['updated_at' => $product->updated_at->copy()->addSecond()]);
        }

        $result = app(ClearanceService::class)->run(true, $fingerprint);

        $this->assertSame(0, $result['retired']);
        $this->assertTrue($product->fresh()->is_active);
        $this->assertSame('active', ProductClearance::query()->where('product_id', $product->id)->sole()->status);
        Http::assertNothingSent();
    }

    public static function changesAfterZeroSnapshot(): array
    {
        return ['ERP identity changed' => ['identity'], 'stock received' => ['stock'], 'record updated' => ['version']];
    }

    public function test_a_bare_product_id_without_a_bound_zero_stock_proof_cannot_retire_an_article(): void
    {
        $product = $this->activeClearance();
        $fingerprint = str_repeat('e', 64);
        app(SystemSettingsService::class)->put(CompanySalesClearanceSync::STATE_KEY, [
            'status' => 'completed', 'as_of' => today()->toDateString(), 'fingerprint' => $fingerprint,
            'zero_stock_product_ids' => [$product->id],
        ]);

        $this->assertSame(0, app(ClearanceService::class)->run(true, $fingerprint)['retired']);
        $this->assertTrue($product->fresh()->is_active);
        $this->assertSame('active', ProductClearance::query()->where('product_id', $product->id)->sole()->status);
        Http::assertNothingSent();
    }

    private function activeClearance(): Product
    {
        $product = Product::query()->create([
            'code' => 'ERP-ACTIVE', 'sku' => 'ERP-ACTIVE', 'base_price' => 100, 'stock_qty' => 10, 'is_active' => true,
            'payload' => ['eracuni' => ['productCode' => 'ERP-ACTIVE'], 'clearance' => ['status' => 'active']],
        ])->fresh();
        ProductClearance::query()->create([
            'product_id' => $product->id, 'source' => 'eracuni_company_export', 'source_fingerprint' => str_repeat('a', 64),
            'coverage_from' => today()->subDays(120), 'snapshot_date' => today(),
            'confirmed_company_scope' => true, 'status' => 'active',
        ]);
        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'clearance']);
        app(ClearanceService::class)->saveSettings(['enabled' => true, 'category_id' => $category->id]);

        return $product;
    }

    private function admin(): User
    {
        $user = User::factory()->create(['account_type' => 'staff']);
        Bouncer::allow($user)->to('integrations.stock.manage');

        return $user;
    }

    private function connection(): void
    {
        app(StockSyncSettingsService::class)->saveConnection('eracuni', [
            'url' => 'https://erp.example.test/api', 'username' => 'test-user',
            'password' => 'test-private-password', 'token' => 'test-private-token',
        ]);
    }
}
