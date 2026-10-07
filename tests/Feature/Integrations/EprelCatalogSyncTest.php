<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\Eprel\SyncEprelCatalogJob;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductEnergyDeclaration;
use App\Models\Integrations\Eprel\EprelCatalogSyncRun;
use App\Services\Integrations\Eprel\EprelCatalogSyncService;
use App\Services\Integrations\Eprel\EprelSettingsService;
use App\Services\Integrations\Msan\EprelProductIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EprelCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        RateLimiter::clear('eprel-catalog:requests');
        app(EprelSettingsService::class)->saveAdminValues(['eprel_enabled' => true, 'eprel_api_key' => 'synthetic-test-only']);
    }

    public function test_exact_native_match_is_saved_without_supplier_or_commercial_changes(): void
    {
        $p = $this->product();
        $before = $p->only(['code', 'sku', 'barcode', 'base_price', 'stock_qty', 'supplier_stock_qty', 'payload', 'eprel_lookup_product_group']);
        Http::fake(fn () => Http::response($this->record()));
        $service = app(EprelCatalogSyncService::class);
        $run = $service->start(1);
        Http::assertNothingSent();
        $service->processNext($run);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->matched_count);
        $this->assertSame($before, $p->fresh()->only(array_keys($before)));
        $declaration = $p->energyDeclarations()->sole();
        $this->assertSame('catalog_batch', $declaration->payload['origins'][0]);
        $this->assertTrue(EprelProductIdentity::matches($p->fresh(), $declaration->payload['product_identity']));
        Bus::assertDispatched(SyncEprelCatalogJob::class, fn ($job) => $job->queue === 'eprel' && $job->connection === 'eprel');
    }

    public function test_no_match_and_unsupported_article_do_not_change_product_data(): void
    {
        $p = $this->product();
        $other = Product::create(['code' => 'CABLE', 'is_active' => true]);
        Http::fake(fn () => Http::response(['hits' => []]));
        $service = app(EprelCatalogSyncService::class);
        $run = $service->startAll();
        $service->processNext($run);
        $this->assertSame('not_found', $run->items()->where('product_id', $p->id)->sole()->status);
        $this->assertSame('skipped', $run->items()->where('product_id', $other->id)->sole()->status);
        $this->assertSame(0, ProductEnergyDeclaration::count());
        $this->assertNull($p->fresh()->energy_label_url);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_existing_different_official_record_and_manual_primary_are_preserved(): void
    {
        $p = $this->product();
        $old = $p->energyDeclarations()->create(['context_code' => 'existing', 'source' => 'eprel', 'eprel_registration_number' => '123', 'eprel_product_group' => 'lightsources', 'is_primary' => true]);
        Http::fake(fn () => Http::response($this->record()));
        $service = app(EprelCatalogSyncService::class);
        $run = $service->start(1);
        $service->processNext($run);
        $this->assertSame('conflict', $run->items()->sole()->status);
        $this->assertTrue($old->fresh()->is_primary);
        $this->assertSame(1, $p->energyDeclarations()->count());
    }

    public function test_cancelled_run_never_issues_requests_or_saves_a_late_response(): void
    {
        $p = $this->product();
        $service = app(EprelCatalogSyncService::class);
        $run = $service->start(1);
        Http::fake(function () use ($run, $service) {
            $service->cancel($run);

            return Http::response($this->record());
        });
        $service->processNext($run);
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(0, $p->energyDeclarations()->count());
        $count = count(Http::recorded());
        $service->processNext($run);
        $this->assertCount($count, Http::recorded());
    }

    public function test_identifier_changed_in_flight_fails_closed(): void
    {
        $p = $this->product();
        Http::fake(function () use ($p) {
            $p->update(['payload' => ['opencart' => ['model' => 'CHANGED']]]);

            return Http::response($this->record());
        });
        $s = app(EprelCatalogSyncService::class);
        $run = $s->start(1);
        $s->processNext($run);
        $this->assertSame('conflict', $run->items()->sole()->status);
        $this->assertSame(0, $p->energyDeclarations()->count());
    }

    public function test_api_rejection_stops_and_429_pauses_without_leaking_response(): void
    {
        $this->product();
        Http::fake(['*' => Http::sequence()->push(['secret' => 'x-api-key=private-body'], 429)->push([], 401)]);
        $s = app(EprelCatalogSyncService::class);
        $run = $s->start(1);
        $s->processNext($run);
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertStringNotContainsString('private-body', $run->fresh()->error_message);
        $this->assertSame(0, ProductEnergyDeclaration::count());
        $s->resume($run);
        $s->processNext($run);
        $this->assertSame('failed', $run->fresh()->status);
    }

    public function test_request_quota_defers_the_item_without_http(): void
    {
        $this->product();
        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit('eprel-catalog:requests', 60);
        }
        $s = app(EprelCatalogSyncService::class);
        $run = $s->start(1);
        $s->processNext($run);
        $this->assertSame('pending', $run->items()->sole()->status);
        $this->assertSame('running', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_only_one_active_run_can_exist_and_disabled_eprel_cannot_start(): void
    {
        $s = app(EprelCatalogSyncService::class);
        $run = $s->start(1);
        try {
            $s->start(1);
            $this->fail('Expected active-run conflict.');
        } catch (ValidationException) {
            $this->assertSame(1, EprelCatalogSyncRun::count());
        }
        $s->cancel($run);
        app(EprelSettingsService::class)->saveAdminValues(['eprel_enabled' => false]);
        $this->expectException(ValidationException::class);
        $s->start(1);
    }

    public function test_a_recent_exact_match_is_not_requested_again(): void
    {
        $this->product();
        Http::fake(fn () => Http::response($this->record()));
        $s = app(EprelCatalogSyncService::class);
        $s->processNext($s->start(1));
        Http::fake();
        $second = $s->start(1);
        $s->processNext($second);
        $s->processNext($second);
        $this->assertSame(0, $second->fresh()->total_count);
        $this->assertSame('completed', $second->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_manual_primary_is_not_replaced_by_batch_match(): void
    {
        $p = $this->product();
        $manual = $p->energyDeclarations()->create(['context_code' => 'manual-primary', 'source' => 'manual', 'energy_class' => 'E', 'is_primary' => true]);
        Http::fake(fn () => Http::response($this->record()));
        $s = app(EprelCatalogSyncService::class);
        $run = $s->start(1);
        $s->processNext($run);
        $this->assertSame('matched', $run->items()->sole()->status);
        $this->assertTrue($manual->fresh()->is_primary);
        $this->assertFalse($p->energyDeclarations()->where('source', 'eprel')->sole()->is_primary);
    }

    public function test_database_json_object_key_order_does_not_change_strict_identifier_match(): void
    {
        $p = $this->product();
        $matcher = app(\App\Services\Integrations\Eprel\EprelCatalogProductMatcher::class);
        $criteria = array_reverse($matcher->criteria($p), true);
        Http::fake(fn () => Http::response($this->record()));
        $result = $matcher->match($p, $criteria, EprelProductIdentity::fingerprint($p));
        $this->assertSame('matched', $result['status']);
        $this->assertSame(1, $p->energyDeclarations()->count());
    }

    public function test_all_catalogue_planning_is_incremental_and_needs_no_supplier_requests(): void
    {
        for ($i = 1; $i <= 101; $i++) {
            Product::create(['code' => 'CABLE-'.$i, 'is_active' => true]);
        }
        $s = app(EprelCatalogSyncService::class);
        $run = $s->startAll();
        $s->processNext($run);
        $this->assertFalse($run->fresh()->planning_complete);
        $this->assertSame(100, $run->fresh()->total_count);
        $s->processNext($run);
        $this->assertTrue($run->fresh()->planning_complete);
        $this->assertSame(101, $run->fresh()->total_count);
        $this->assertSame(101, $run->fresh()->skipped_count);
        $this->assertSame('completed', $run->fresh()->status);
        Http::assertNothingSent();
    }

    private function product(): Product
    {
        $m = Manufacturer::create(['code' => 'BRAYTRON', 'is_active' => true]);
        $m->translations()->create(['locale' => 'hr', 'name' => 'Braytron', 'slug' => 'braytron']);
        $category = Category::create(['code' => 'herrera-oc-category-787', 'scope' => 'catalog', 'is_active' => true]);
        $p = Product::create(['code' => 'herrera-oc-product-1', 'manufacturer_id' => $m->id, 'is_active' => true, 'barcode' => '5949097723490', 'base_price' => '20.4800', 'stock_qty' => 3, 'supplier_stock_qty' => 9, 'payload' => ['opencart' => ['model' => 'BA13-01121', 'ean' => '5949097723490']]]);
        $p->categories()->attach($category);

        return $p;
    }

    private function record(): array
    {
        return ['eprelRegistrationNumber' => '711091', 'productGroup' => 'LIGHT_SOURCE', 'modelIdentifier' => 'BA13-01121', 'supplierOrTrademark' => 'Braytron', 'gtinIdentifier' => '5949097723490', 'energyClass' => 'F', 'scaleMin' => 'A', 'scaleMax' => 'G'];
    }
}
