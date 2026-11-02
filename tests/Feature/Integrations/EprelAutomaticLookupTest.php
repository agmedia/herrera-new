<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\Eprel\SyncProductEprelJob;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Services\Integrations\Eprel\EprelAutomaticLookupService;
use App\Services\Integrations\Eprel\EprelSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

class EprelAutomaticLookupTest extends TestCase
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

    public function test_creation_enqueues_only_after_commit_with_final_categories_and_packages(): void
    {
        DB::transaction(function (): void {
            $this->product();
            Bus::assertNotDispatched(SyncProductEprelJob::class);
        });

        Bus::assertDispatched(SyncProductEprelJob::class, fn ($job) => $job->connection === 'eprel'
            && $job->queue === 'eprel' && $job->afterCommit
            && $job->criteria['groups'] === ['lightsources']
            && $job->criteria['brands'] === ['Braytron']
            && $job->criteria['gtins'] === ['5949097723490']);
        Http::assertNothingSent();
    }

    public function test_imported_draft_waits_for_publication_and_ordinary_updates_do_not_repeat_it(): void
    {
        $product = DB::transaction(fn () => $this->product(false));
        Bus::assertNotDispatched(SyncProductEprelJob::class);

        $product->update(['is_active' => true]);
        Bus::assertDispatchedTimes(SyncProductEprelJob::class, 1);
        $product->update(['stock_qty' => 5, 'base_price' => '12.50']);
        Bus::assertDispatchedTimes(SyncProductEprelJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_rollback_never_enqueues_a_lookup(): void
    {
        try {
            DB::transaction(function (): void {
                $this->product();
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            $this->assertSame(0, Product::count());
        }

        Bus::assertNotDispatched(SyncProductEprelJob::class);
        Http::assertNothingSent();
    }

    public function test_disabled_unconfigured_unsupported_and_unidentifiable_products_are_not_queued(): void
    {
        app(EprelSettingsService::class)->saveAdminValues(['eprel_enabled' => false]);
        DB::transaction(fn () => $this->product());
        Bus::assertNotDispatched(SyncProductEprelJob::class);

        app(EprelSettingsService::class)->saveAdminValues(['eprel_enabled' => true]);
        app(\App\Services\Settings\SystemSettingsService::class)->put(EprelSettingsService::KEY_API_KEY_ENCRYPTED, '');
        $product = DB::transaction(fn () => $this->product());
        Bus::assertNotDispatched(SyncProductEprelJob::class);

        app(EprelSettingsService::class)->saveAdminValues(['eprel_api_key' => 'synthetic-test-only']);
        Product::create(['code' => 'TOOLS', 'sku' => 'TOOLS-MODEL', 'is_active' => true]);
        $product->update(['is_active' => false, 'sku' => null, 'barcode' => null, 'code' => 'herrera-oc-product-9', 'payload' => []]);
        $product->manufacturer()->dissociate();
        $product->save();
        $product->packages()->delete();
        $product->update(['is_active' => true]);
        Bus::assertNotDispatched(SyncProductEprelJob::class);
        Http::assertNothingSent();
    }

    public function test_exact_match_adds_documents_without_commercial_changes_or_recursive_jobs(): void
    {
        $product = DB::transaction(fn () => $this->product());
        $before = $product->fresh()->only(['sku', 'barcode', 'base_price', 'stock_qty', 'supplier_stock_qty', 'payload']);
        Http::fake(fn () => Http::response($this->record()));

        $this->queuedJob()->handle(app(EprelAutomaticLookupService::class));

        $this->assertSame('711091', $product->fresh()->eprel_registration_number);
        $this->assertStringContainsString('/fiches/lightsources/Fiche_711091_HR.pdf', $product->fresh()->product_information_sheet_url);
        $this->assertSame(1, $product->energyDeclarations()->count());
        $this->assertSame($before, $product->fresh()->only(array_keys($before)));
        Bus::assertDispatchedTimes(SyncProductEprelJob::class, 1);
    }

    public function test_queued_lookup_ignores_changed_identity_categories_or_manual_data(): void
    {
        $product = DB::transaction(fn () => $this->product());
        $job = $this->queuedJob();
        $product->update(['barcode' => '1234567890128']);
        $job->handle(app(EprelAutomaticLookupService::class));
        Http::assertNothingSent();

        $product->update(['barcode' => null]);
        $product->categories()->detach();
        $job->handle(app(EprelAutomaticLookupService::class));
        Http::assertNothingSent();

        $product->categories()->attach(Category::where('code', 'herrera-oc-category-787')->sole());
        $product->energyDeclarations()->create(['context_code' => 'manual', 'source' => 'manual', 'is_primary' => true, 'energy_class' => 'E']);
        $job->handle(app(EprelAutomaticLookupService::class));
        Http::assertNothingSent();
        $this->assertSame('E', $product->energyDeclarations()->sole()->energy_class);
    }

    public function test_an_admin_edit_while_the_http_request_is_in_flight_is_preserved(): void
    {
        $product = DB::transaction(fn () => $this->product());
        Http::fake(function () use ($product) {
            $product->update(['energy_efficiency_class' => 'E']);

            return Http::response($this->record());
        });

        $this->queuedJob()->handle(app(EprelAutomaticLookupService::class));

        $this->assertSame('E', $product->fresh()->energy_efficiency_class);
        $this->assertSame(0, $product->energyDeclarations()->count());
        $this->assertNull($product->fresh()->product_information_sheet_url);
    }

    public function test_disabling_eprel_after_enqueue_prevents_requests_and_late_writes(): void
    {
        $product = DB::transaction(fn () => $this->product());
        $job = $this->queuedJob();
        app(EprelSettingsService::class)->saveAdminValues(['eprel_enabled' => false]);
        $job->handle(app(EprelAutomaticLookupService::class));
        Http::assertNothingSent();

        app(EprelSettingsService::class)->saveAdminValues(['eprel_enabled' => true]);
        Http::fake(function () {
            app(EprelSettingsService::class)->saveAdminValues(['eprel_enabled' => false]);

            return Http::response($this->record());
        });
        $job->handle(app(EprelAutomaticLookupService::class));
        $this->assertSame(0, $product->energyDeclarations()->count());
    }

    public function test_a_shared_quota_defers_automatic_lookup_with_a_bounded_retry_deadline(): void
    {
        DB::transaction(fn () => $this->product());
        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit('eprel-catalog:requests', 60);
        }
        $job = $this->queuedJob()->withFakeQueueInteractions();

        $job->handle(app(EprelAutomaticLookupService::class));

        $job->assertReleased();
        $this->assertLessThanOrEqual(now()->addHours(6)->getTimestamp(), $job->retryUntil()->getTimestamp());
        $this->assertSame(3, $job->maxExceptions);
        Http::assertNothingSent();
    }

    public function test_api_authentication_rejection_fails_without_retaining_response_secrets(): void
    {
        DB::transaction(fn () => $this->product());
        Http::fake(fn () => Http::response(['private' => 'secret-response'], 403));
        $job = $this->queuedJob()->withFakeQueueInteractions();

        $job->handle(app(EprelAutomaticLookupService::class));

        $job->assertFailedWith(new RuntimeException('Automatski EPREL dohvat nije odobren. Provjerite EPREL API postavke.'));
    }

    public function test_existing_documents_and_manual_declarations_do_not_trigger_automatic_replacement(): void
    {
        DB::transaction(function (): void {
            $product = $this->product();
            $product->update(['product_information_sheet_url' => 'https://example.test/manual.pdf', 'energy_label_url' => 'https://example.test/label.pdf']);
        });
        DB::transaction(function (): void {
            $product = $this->product();
            $product->energyDeclarations()->create(['context_code' => 'manual', 'source' => 'manual', 'is_primary' => true]);
        });

        Bus::assertNotDispatched(SyncProductEprelJob::class);
        Http::assertNothingSent();
    }

    public function test_partial_documentation_is_enriched_without_replacing_existing_energy_fields(): void
    {
        $product = DB::transaction(function (): Product {
            $product = $this->product();
            $product->update(['energy_label_url' => 'https://example.test/supplier-label.pdf', 'energy_efficiency_class' => 'F']);

            return $product;
        });
        Http::fake(fn () => Http::response($this->record()));

        $this->queuedJob()->handle(app(EprelAutomaticLookupService::class));

        $this->assertSame('https://example.test/supplier-label.pdf', $product->fresh()->energy_label_url);
        $this->assertSame('F', $product->fresh()->energy_efficiency_class);
        $this->assertNotNull($product->fresh()->product_information_sheet_url);
        $this->assertSame(['automatic_lookup'], $product->energyDeclarations()->sole()->payload['origins']);
    }

    public function test_a_conflicting_existing_energy_class_requires_review_without_partial_writes(): void
    {
        $product = DB::transaction(function (): Product {
            $product = $this->product();
            $product->update(['energy_label_url' => 'https://example.test/existing-label.pdf', 'energy_efficiency_class' => 'E']);

            return $product;
        });
        $before = $product->fresh()->getAttributes();
        Http::fake(fn () => Http::response($this->record()));
        Log::spy();

        $this->queuedJob()->handle(app(EprelAutomaticLookupService::class));

        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertSame(0, $product->energyDeclarations()->count());
        Log::shouldHaveReceived('notice')->with('Automatski EPREL dohvat zahtijeva ručni pregled.', ['product_id' => $product->id, 'status' => 'conflict'])->once();
    }

    public function test_unmatched_lookups_have_safe_diagnostics_without_changing_product(): void
    {
        $product = DB::transaction(fn () => $this->product());
        Http::fake(fn () => Http::response(['hits' => []]));
        Log::spy();

        $this->queuedJob()->handle(app(EprelAutomaticLookupService::class));

        Log::shouldHaveReceived('info')->with('Automatski EPREL dohvat završen.', ['product_id' => $product->id, 'status' => 'not_found'])->once();
        $this->assertSame(0, $product->energyDeclarations()->count());
        $this->assertNull($product->fresh()->product_information_sheet_url);
    }

    private function queuedJob(): SyncProductEprelJob
    {
        return Bus::dispatched(SyncProductEprelJob::class)->sole();
    }

    private function product(bool $active = true): Product
    {
        $sequence = Product::count() + 1;
        $manufacturer = Manufacturer::firstOrCreate(['code' => 'AUTO-BRAYTRON'], ['is_active' => true]);
        $manufacturer->translations()->firstOrCreate(['locale' => 'hr'], ['name' => 'Braytron', 'slug' => 'auto-braytron']);
        $category = Category::firstOrCreate(['code' => 'herrera-oc-category-787'], ['scope' => 'catalog', 'is_active' => true]);
        $product = Product::create([
            'code' => 'herrera-oc-product-'.$sequence, 'sku' => $sequence === 1 ? 'BA13-01121' : 'BA13-01121-'.$sequence,
            'manufacturer_id' => $manufacturer->id, 'is_active' => $active,
            'base_price' => '20.4800', 'stock_qty' => 3, 'supplier_stock_qty' => 9,
            'payload' => ['supplier_catalog' => ['supplier' => 'braytron']],
        ]);
        $product->categories()->attach($category);
        $product->packages()->create(['code' => 'unit', 'name' => 'Komad', 'quantity' => 1, 'barcode' => $sequence === 1 ? '5949097723490' : null, 'is_active' => true]);

        return $product;
    }

    private function record(): array
    {
        return ['eprelRegistrationNumber' => '711091', 'productGroup' => 'LIGHT_SOURCE', 'modelIdentifier' => 'BA13-01121', 'supplierOrTrademark' => 'Braytron', 'gtinIdentifier' => '5949097723490', 'energyClass' => 'F', 'scaleMin' => 'A', 'scaleMax' => 'G'];
    }
}
