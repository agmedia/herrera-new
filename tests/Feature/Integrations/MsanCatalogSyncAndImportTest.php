<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\Msan\DispatchMsanImportChunksJob;
use App\Jobs\Integrations\Msan\ImportMsanProductsChunkJob;
use App\Jobs\Integrations\Msan\SyncMsanCatalogJob;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Import\CatalogSourceMapping;
use App\Models\Integrations\Msan\MsanCategory;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use App\Models\Integrations\Msan\MsanImportRunItem;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSyncRun;
use App\Models\Settings\Local\TaxRate;
use App\Services\Integrations\Msan\MsanCatalogSyncService;
use App\Services\Integrations\Msan\MsanClient;
use App\Services\Integrations\Msan\MsanImportCoordinator;
use App\Services\Integrations\Msan\MsanProductImportService;
use App\Services\Integrations\Msan\MsanXmlStreamReader;
use App\Services\Settings\SystemSettingsService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class MsanCatalogSyncAndImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_snapshot_is_committed_with_prices_stock_categories_and_barcodes(): void
    {
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_FULL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        $service = new MsanCatalogSyncService(
            $this->fixtureClient([
                'categories' => $this->xml([['CategoryID' => 'C-1', 'CategoryName' => 'Grijanje']]),
                'catalog' => $this->xml([[
                    'ProductCode' => 'P-1',
                    'ProductName' => 'Radijator',
                    'Brand' => 'Termol test',
                    'ProductImageUrl' => 'https://b2b.msan.hr/images/p-1.jpg',
                ]]),
                'prices' => $this->xml([[
                    'ProductCode' => 'P-1',
                    'ProductPartnerPrice' => '80,00',
                    'RecommendedRetailPrice' => '125,00',
                ]]),
                'availability' => $this->xml([['ProductCode' => 'P-1', 'ProductAvailability' => '3']]),
                'product_categories' => $this->xml([['ProductCode' => 'P-1', 'CategoryID' => 'C-1']]),
                'barcodes' => $this->xml([['ProductCode' => 'P-1', 'BarcodeType' => 'EAN', 'BarcodeValue' => '3850000000001']]),
            ]),
            app(MsanXmlStreamReader::class),
        );

        $service->sync($run);

        $product = MsanProduct::query()->where('external_code', 'P-1')->firstOrFail();
        $this->assertSame('125.0000', $product->recommended_retail_price);
        $this->assertSame(3, $product->availability_level);
        $this->assertSame('3850000000001', data_get($product->barcodes, '0.value'));
        $this->assertSame(1, $product->categories()->count());
        $this->assertDatabaseHas('msan_category_mappings', [
            'msan_category_id' => $product->categories()->firstOrFail()->id,
            'status' => MsanCategoryMapping::STATUS_UNMAPPED,
        ]);
        $this->assertSame(MsanSyncRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    public function test_catalog_identifier_change_invalidates_a_fresh_eprel_match(): void
    {
        $source = MsanProduct::query()->create([
            'external_code' => 'P-EPREL-CHANGE',
            'name' => 'Stari model',
            'model' => 'OLD-MODEL',
            'part_number' => 'OLD-PART',
            'eprel_match_status' => MsanProduct::EPREL_EXACT,
            'eprel_identifier_checksum' => str_repeat('a', 64),
            'eprel_checked_at' => now(),
            'last_seen_at' => now()->subDay(),
            'is_stale' => false,
        ]);
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_FULL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        $service = new MsanCatalogSyncService(
            $this->fixtureClient([
                'categories' => $this->xml([['CategoryID' => 'C-EPREL', 'CategoryName' => 'Hladnjaci']]),
                'catalog' => $this->xml([[
                    'ProductCode' => 'P-EPREL-CHANGE',
                    'ProductName' => 'Novi model',
                    'Model' => 'NEW-MODEL',
                    'PartNo' => 'NEW-PART',
                ]]),
                'prices' => $this->xml([['ProductCode' => 'P-EPREL-CHANGE', 'RecommendedRetailPrice' => '125.00']]),
                'availability' => $this->xml([['ProductCode' => 'P-EPREL-CHANGE', 'ProductAvailability' => '3']]),
                'product_categories' => $this->xml([['ProductCode' => 'P-EPREL-CHANGE', 'CategoryID' => 'C-EPREL']]),
                'barcodes' => $this->xml([]),
            ]),
            app(MsanXmlStreamReader::class),
        );

        $service->sync($run);

        $source->refresh();
        $this->assertSame('NEW-MODEL', $source->model);
        $this->assertSame(MsanProduct::EPREL_PENDING, $source->eprel_match_status);
        $this->assertNull($source->eprel_identifier_checksum);
        $this->assertNull($source->eprel_checked_at);
    }

    public function test_catalog_brand_change_alone_invalidates_a_fresh_eprel_match(): void
    {
        $source = MsanProduct::query()->create([
            'external_code' => 'P-EPREL-BRAND-CHANGE',
            'name' => 'Isti model',
            'brand' => 'OLD BRAND',
            'model' => 'SAME-MODEL',
            'part_number' => 'SAME-PART',
            'eprel_match_status' => MsanProduct::EPREL_EXACT,
            'eprel_identifier_checksum' => str_repeat('b', 64),
            'eprel_checked_at' => now(),
            'last_seen_at' => now()->subDay(),
            'is_stale' => false,
        ]);
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_FULL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        $service = new MsanCatalogSyncService(
            $this->fixtureClient([
                'categories' => $this->xml([['CategoryID' => 'C-BRAND', 'CategoryName' => 'Hladnjaci']]),
                'catalog' => $this->xml([[
                    'ProductCode' => 'P-EPREL-BRAND-CHANGE',
                    'ProductName' => 'Isti model',
                    'Brand' => 'NEW BRAND',
                    'Model' => 'SAME-MODEL',
                    'PartNo' => 'SAME-PART',
                ]]),
                'prices' => $this->xml([['ProductCode' => 'P-EPREL-BRAND-CHANGE', 'RecommendedRetailPrice' => '125.00']]),
                'availability' => $this->xml([['ProductCode' => 'P-EPREL-BRAND-CHANGE', 'ProductAvailability' => '3']]),
                'product_categories' => $this->xml([['ProductCode' => 'P-EPREL-BRAND-CHANGE', 'CategoryID' => 'C-BRAND']]),
                'barcodes' => $this->xml([]),
            ]),
            app(MsanXmlStreamReader::class),
        );

        $service->sync($run);

        $source->refresh();
        $this->assertSame('NEW BRAND', $source->brand);
        $this->assertSame('SAME-MODEL', $source->model);
        $this->assertSame(MsanProduct::EPREL_PENDING, $source->eprel_match_status);
        $this->assertNull($source->eprel_identifier_checksum);
        $this->assertNull($source->eprel_checked_at);
    }

    public function test_failed_incomplete_snapshot_rolls_back_every_staging_change(): void
    {
        $oldCategory = MsanCategory::query()->create([
            'external_id' => 'OLD-CATEGORY',
            'name' => 'Stara kategorija',
            'last_seen_at' => now()->subDay(),
            'is_stale' => false,
        ]);
        $oldProduct = MsanProduct::query()->create([
            'external_code' => 'OLD-1',
            'name' => 'Stari artikl',
            'currency_code' => 'EUR',
            'recommended_retail_price' => 99,
            'last_seen_at' => now()->subDay(),
            'is_stale' => false,
        ]);
        $oldProduct->categories()->attach($oldCategory->id, ['last_seen_at' => now()->subDay()]);

        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_FULL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        $service = new MsanCatalogSyncService(
            $this->fixtureClient([
                'categories' => $this->xml([['CategoryID' => 'NEW-CATEGORY', 'CategoryName' => 'Nova kategorija']]),
                'catalog' => $this->xml([['ProductCode' => 'NEW-1', 'ProductName' => 'Novi artikl']]),
                'prices' => $this->xml([['ProductCode' => 'NEW-1', 'RecommendedRetailPrice' => '125.00']]),
                'availability' => $this->xml([['ProductCode' => 'NEW-1', 'ProductAvailability' => '3']]),
                'product_categories' => $this->xml([]),
                'barcodes' => $this->xml([]),
            ]),
            app(MsanXmlStreamReader::class),
        );

        try {
            $service->sync($run);
            $this->fail('Incomplete supplier snapshot must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('product_categories', $exception->getMessage());
        }

        $this->assertDatabaseMissing('msan_products', ['external_code' => 'NEW-1']);
        $this->assertDatabaseMissing('msan_categories', ['external_id' => 'NEW-CATEGORY']);
        $this->assertDatabaseHas('msan_products', ['id' => $oldProduct->id, 'is_stale' => false]);
        $this->assertDatabaseHas('msan_product_categories', [
            'msan_product_id' => $oldProduct->id,
            'msan_category_id' => $oldCategory->id,
        ]);
        $this->assertSame(MsanSyncRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->error_message);

        (new SyncMsanCatalogJob((int) $run->id))->failed(new RuntimeException('Završni neuspjeh'));
        $this->assertSame(MsanSyncRun::STATUS_FAILED, $run->fresh()->status);
    }

    public function test_partial_optional_rows_clear_values_missing_from_the_new_snapshot(): void
    {
        foreach (['P-1', 'P-2'] as $code) {
            MsanProduct::query()->create([
                'external_code' => $code,
                'name' => 'Stari '.$code,
                'currency_code' => 'EUR',
                'recommended_retail_price' => 999,
                'availability_level' => 4,
                'barcodes' => [['type' => 'EAN', 'value' => 'old-'.$code]],
                'last_seen_at' => now()->subDay(),
                'is_stale' => false,
            ]);
        }
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_FULL,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        $service = new MsanCatalogSyncService(
            $this->fixtureClient([
                'categories' => $this->xml([['CategoryID' => 'C-1', 'CategoryName' => 'Grijanje']]),
                'catalog' => $this->xml([
                    ['ProductCode' => 'P-1', 'ProductName' => 'Prvi'],
                    ['ProductCode' => 'P-2', 'ProductName' => 'Drugi'],
                ]),
                'prices' => $this->xml([['ProductCode' => 'P-1', 'RecommendedRetailPrice' => '125.00']]),
                'availability' => $this->xml([['ProductCode' => 'P-1', 'ProductAvailability' => '3']]),
                'product_categories' => $this->xml([['ProductCode' => 'P-1', 'CategoryID' => 'C-1']]),
                'barcodes' => $this->xml([['ProductCode' => 'P-1', 'BarcodeType' => 'EAN', 'BarcodeValue' => '3850000000001']]),
            ]),
            app(MsanXmlStreamReader::class),
        );

        $service->sync($run);

        $missing = MsanProduct::query()->where('external_code', 'P-2')->firstOrFail();
        $this->assertNull($missing->recommended_retail_price);
        $this->assertNull($missing->availability_level);
        $this->assertNull($missing->barcodes);
    }

    public function test_selected_product_import_is_idempotent_across_chunk_replay(): void
    {
        Queue::fake();
        $this->configureImport();
        [$source] = $this->eligibleSource('MSAN-100', 'Termol testni artikl');

        $run = app(MsanImportCoordinator::class)->queueSelected();
        Queue::assertPushed(DispatchMsanImportChunksJob::class);
        $this->assertDatabaseHas('msan_import_run_items', [
            'msan_sync_run_id' => $run->id,
            'msan_product_id' => $source->id,
            'status' => MsanImportRunItem::STATUS_PENDING,
        ]);

        $job = new ImportMsanProductsChunkJob((int) $run->id, [(int) $source->id]);
        $job->handle(app(MsanProductImportService::class));
        $job->handle(app(MsanProductImportService::class));

        $run->refresh();
        $item = MsanImportRunItem::query()->where('msan_sync_run_id', $run->id)->firstOrFail();
        $this->assertSame(MsanSyncRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->processed_count);
        $this->assertSame(1, $run->succeeded_count);
        $this->assertSame(1, $item->attempts);
        $this->assertSame(MsanImportRunItem::STATUS_SUCCEEDED, $item->status);
        $this->assertFalse($source->fresh()->selected);
        $this->assertSame(MsanProduct::IMPORT_IMPORTED, $source->fresh()->import_status);
        $this->assertSame(1, Product::query()->where('code', 'MSAN-100')->count());
        $this->assertSame(7, Product::query()->where('code', 'MSAN-100')->value('stock_qty'));
    }

    public function test_import_coordinator_only_stages_retryable_import_statuses(): void
    {
        Queue::fake();
        $this->configureImport();
        $products = collect([
            MsanProduct::IMPORT_PENDING,
            MsanProduct::IMPORT_FAILED,
            MsanProduct::IMPORT_SKIPPED,
            MsanProduct::IMPORT_IMPORTED,
            MsanProduct::IMPORT_QUEUED,
            MsanProduct::IMPORT_IMPORTING,
        ])->mapWithKeys(function (string $status): array {
            [$product] = $this->eligibleSource('MSAN-'.strtoupper($status), 'Artikl '.$status);
            $product->update(['import_status' => $status]);

            return [$status => $product];
        });

        $run = app(MsanImportCoordinator::class)->queueSelected();

        $this->assertSame(3, $run->total_count);
        foreach (MsanProduct::IMPORT_READY_STATUSES as $status) {
            $this->assertDatabaseHas('msan_import_run_items', [
                'msan_sync_run_id' => $run->id,
                'msan_product_id' => $products->get($status)->id,
            ]);
        }
        foreach ([MsanProduct::IMPORT_IMPORTED, MsanProduct::IMPORT_QUEUED, MsanProduct::IMPORT_IMPORTING] as $status) {
            $this->assertDatabaseMissing('msan_import_run_items', [
                'msan_sync_run_id' => $run->id,
                'msan_product_id' => $products->get($status)->id,
            ]);
            $this->assertSame($status, $products->get($status)->fresh()->import_status);
        }
    }

    public function test_import_coordinator_does_not_create_an_empty_run_for_nonready_products(): void
    {
        Queue::fake();
        $this->configureImport();
        foreach ([MsanProduct::IMPORT_IMPORTED, MsanProduct::IMPORT_QUEUED, MsanProduct::IMPORT_IMPORTING] as $status) {
            [$product] = $this->eligibleSource('MSAN-NOT-READY-'.strtoupper($status), 'Artikl '.$status);
            $product->update(['import_status' => $status]);
        }

        try {
            app(MsanImportCoordinator::class)->queueSelected();
            $this->fail('Artikli koji su već uvezeni ili aktivno u obradi ne smiju stvoriti novi red uvoza.');
        } catch (DomainException $exception) {
            $this->assertSame('Nema odabranih artikala spremnih za novi uvoz.', $exception->getMessage());
        }

        $this->assertSame(0, MsanSyncRun::query()->count());
        $this->assertSame(0, MsanImportRunItem::query()->count());
    }

    public function test_import_preserves_non_msan_owned_catalog_fields(): void
    {
        Queue::fake();
        $this->configureImport();
        [$source] = $this->eligibleSource('MSAN-ERP-1', 'Dobavljački naziv');
        $product = Product::query()->create([
            'code' => 'ERP-OWNED-1',
            'sku' => 'ERP-OWNED-1',
            'is_active' => true,
            'base_price' => 777,
            'stock_qty' => 42,
            'payload' => ['catalog_origin' => 'erp'],
        ]);
        $product->translations()->create([
            'locale' => 'hr',
            'name' => 'Ručno uređeni naziv',
            'slug' => 'rucno-uredeni-naziv',
        ]);
        $source->update(['local_product_id' => $product->id]);

        $result = app(MsanProductImportService::class)->import((int) $source->id);

        $product->refresh();
        $this->assertSame('updated', $result);
        $this->assertSame('ERP-OWNED-1', $product->code);
        $this->assertSame('777.00', $product->base_price);
        $this->assertSame(42, $product->stock_qty);
        $this->assertSame('Ručno uređeni naziv', $product->translation('hr')->firstOrFail()->name);
        $this->assertSame('125.0000', data_get($product->payload, 'supplier_sources.msan.recommended_retail_price'));
    }

    public function test_initial_import_stores_msan_mpc_according_to_shop_tax_mode(): void
    {
        Queue::fake();
        $this->configureImport();
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);
        [$netSource] = $this->eligibleSource('MSAN-NET-MPC', 'Neto pohrana');

        app(MsanProductImportService::class)->import((int) $netSource->id);

        $this->assertSame('100.00', $netSource->fresh()->localProduct()->firstOrFail()->base_price);

        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        [$grossSource] = $this->eligibleSource('MSAN-GROSS-MPC', 'Bruto pohrana');

        app(MsanProductImportService::class)->import((int) $grossSource->id);

        $this->assertSame('125.00', $grossSource->fresh()->localProduct()->firstOrFail()->base_price);
    }

    public function test_erp_mapping_takes_field_ownership_from_an_msan_created_product(): void
    {
        Queue::fake();
        $this->configureImport();
        [$source] = $this->eligibleSource('MSAN-ADOPTED-1', 'Dobavljački naziv');
        $product = Product::query()->create([
            'code' => 'ERP-ADOPTED-1',
            'sku' => 'ERP-ADOPTED-1',
            'is_active' => true,
            'base_price' => 777,
            'stock_qty' => 42,
            'payload' => [
                'catalog_origin' => 'msan',
                'import_sources' => ['konto' => ['source_id' => 'ERP-1']],
            ],
        ]);
        $product->translations()->create([
            'locale' => 'hr',
            'name' => 'ERP naziv',
            'slug' => 'erp-naziv',
        ]);
        CatalogSourceMapping::query()->create([
            'source' => 'konto',
            'entity_type' => CatalogSourceMapping::ENTITY_PRODUCT,
            'source_id' => 'ERP-1',
            'local_id' => $product->id,
            'lifecycle_status' => 'a',
        ]);
        $source->update(['local_product_id' => $product->id]);

        app(MsanProductImportService::class)->import((int) $source->id);

        $product->refresh();
        $this->assertSame('ERP-ADOPTED-1', $product->code);
        $this->assertSame('777.00', $product->base_price);
        $this->assertSame(42, $product->stock_qty);
        $this->assertSame('ERP naziv', $product->translation('hr')->firstOrFail()->name);
        $this->assertSame('125.0000', data_get($product->payload, 'supplier_sources.msan.recommended_retail_price'));
    }

    public function test_terminal_chunk_failure_finishes_its_items_without_stranding_other_chunks(): void
    {
        Queue::fake();
        $this->configureImport();
        [$first] = $this->eligibleSource('MSAN-FAIL-1', 'Neuspjeli artikl');
        [$second] = $this->eligibleSource('MSAN-OK-2', 'Uspjeli artikl');
        $run = app(MsanImportCoordinator::class)->queueSelected();

        (new ImportMsanProductsChunkJob((int) $run->id, [(int) $first->id]))
            ->failed(new RuntimeException('Kontrolirani pad workera'));
        (new ImportMsanProductsChunkJob((int) $run->id, [(int) $second->id]))
            ->handle(app(MsanProductImportService::class));

        $run->refresh();
        $this->assertSame(MsanSyncRun::STATUS_FAILED, $run->status);
        $this->assertSame(2, $run->processed_count);
        $this->assertSame(1, $run->failed_count);
        $this->assertSame(1, $run->succeeded_count);
        $this->assertSame(0, MsanImportRunItem::query()
            ->where('msan_sync_run_id', $run->id)
            ->whereIn('status', [MsanImportRunItem::STATUS_PENDING, MsanImportRunItem::STATUS_PROCESSING])
            ->count());
    }

    public function test_catalog_sync_and_import_are_mutually_exclusive(): void
    {
        $this->configureImport();
        $this->eligibleSource('MSAN-BLOCKED', 'Blokirani artikl');
        MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_FULL,
            'status' => MsanSyncRun::STATUS_RUNNING,
        ]);

        $this->expectException(DomainException::class);
        app(MsanImportCoordinator::class)->queueSelected();
    }

    public function test_specification_and_eprel_runs_also_block_import(): void
    {
        $this->configureImport();
        $this->eligibleSource('MSAN-BLOCKED-NEW-RUNS', 'Blokirani artikl');

        foreach ([MsanSyncRun::KIND_SPECIFICATIONS, MsanSyncRun::KIND_EPREL] as $kind) {
            $active = MsanSyncRun::query()->create([
                'kind' => $kind,
                'status' => MsanSyncRun::STATUS_RUNNING,
            ]);

            try {
                app(MsanImportCoordinator::class)->queueSelected();
                $this->fail("Aktivna {$kind} obrada mora blokirati uvoz.");
            } catch (DomainException) {
                $this->assertDatabaseMissing('msan_sync_runs', [
                    'kind' => MsanSyncRun::KIND_IMPORT,
                    'status' => MsanSyncRun::STATUS_PENDING,
                ]);
            } finally {
                $active->delete();
            }
        }
    }

    private function configureImport(): void
    {
        app(\App\Services\Integrations\Msan\MsanSettingsService::class)->saveAdminValues([
            'msan_import_images' => false,
            'msan_import_products_active' => false,
            'msan_stock_level_3' => 7,
        ]);
        TaxRate::query()->create([
            'code' => 'PDV25',
            'name' => 'PDV 25%',
            'rate_type' => 'percent',
            'rate' => 25,
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    /** @return array{MsanProduct, Category, MsanCategory} */
    private function eligibleSource(string $code, string $name): array
    {
        $localCategory = Category::query()->create([
            'scope' => Category::SCOPE_CATALOG,
            'code' => strtolower($code),
            'is_active' => true,
            'show_in_menu' => true,
            'sort_order' => 0,
        ]);
        $localCategory->translations()->create([
            'locale' => 'hr',
            'name' => 'Testna kategorija '.$code,
            'slug' => 'testna-kategorija-'.strtolower($code),
        ]);
        $msanCategory = MsanCategory::query()->create([
            'external_id' => 'CAT-'.$code,
            'name' => 'M SAN kategorija',
            'last_seen_at' => now(),
            'is_stale' => false,
        ]);
        MsanCategoryMapping::query()->create([
            'msan_category_id' => $msanCategory->id,
            'local_category_id' => $localCategory->id,
            'status' => MsanCategoryMapping::STATUS_MAPPED,
        ]);
        $source = MsanProduct::query()->create([
            'external_code' => $code,
            'name' => $name,
            'brand' => 'Test Brand',
            'currency_code' => 'EUR',
            'recommended_retail_price' => 125,
            'availability_level' => 3,
            'selected' => true,
            'is_stale' => false,
            'match_status' => MsanProduct::MATCH_UNMATCHED,
            'import_status' => MsanProduct::IMPORT_PENDING,
            'last_seen_at' => now(),
        ]);
        $source->categories()->attach($msanCategory->id, ['last_seen_at' => now()]);

        return [$source, $localCategory, $msanCategory];
    }

    /** @param array<string, string> $datasets */
    private function fixtureClient(array $datasets): MsanClient
    {
        return new class($datasets) extends MsanClient
        {
            /** @param array<string, string> $datasets */
            public function __construct(private readonly array $datasets) {}

            public function downloadDataset(string $dataset, string $destinationPath): void
            {
                if (! array_key_exists($dataset, $this->datasets)) {
                    throw new RuntimeException('Nedostaje testni dataset '.$dataset);
                }

                $directory = dirname($destinationPath);
                if (! is_dir($directory)) {
                    mkdir($directory, 0750, true);
                }
                file_put_contents($destinationPath, $this->datasets[$dataset]);
            }
        };
    }

    /** @param list<array<string, scalar|null>> $rows */
    private function xml(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><NewDataSet>';
        foreach ($rows as $row) {
            $xml .= '<Table>';
            foreach ($row as $field => $value) {
                $xml .= '<'.$field.'>'.htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</'.$field.'>';
            }
            $xml .= '</Table>';
        }

        return $xml.'</NewDataSet>';
    }
}
