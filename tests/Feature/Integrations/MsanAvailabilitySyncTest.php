<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\Msan\SyncMsanPricesAndStockJob;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductPriceHistory;
use App\Models\Import\CatalogSourceMapping;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSyncRun;
use App\Models\Settings\Local\TaxRate;
use App\Services\Integrations\Msan\MsanCatalogSyncCoordinator;
use App\Services\Integrations\Msan\MsanClient;
use App\Services\Integrations\Msan\MsanImportCoordinator;
use App\Services\Integrations\Msan\MsanPricesAndStockSyncService;
use App\Services\Integrations\Msan\MsanSettingsService;
use App\Services\Integrations\Msan\MsanXmlStreamReader;
use App\Services\Pricing\TaxPricingService;
use App\Services\Settings\SystemSettingsService;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class MsanAvailabilitySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_lightweight_sync_downloads_prices_and_availability_and_updates_only_msan_owned_products(): void
    {
        $this->configureSync();

        $ownedHigh = $this->sourceWithLocalProduct('MSAN-HIGH', 99, true, localPrice: 100, sourcePrice: 125);
        $erpOwned = $this->sourceWithLocalProduct('ERP-OWNED', 77, false, localPrice: 777, sourcePrice: 125);
        $ownedMissing = $this->sourceWithLocalProduct('MSAN-MISSING', 99, true, localPrice: 90, sourcePrice: 125);
        $unimported = MsanProduct::query()->create([
            'external_code' => 'NOT-IMPORTED',
            'recommended_retail_price' => 40,
            'availability_level' => 4,
            'last_seen_at' => now(),
            'is_stale' => false,
        ]);

        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_PRICES,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        $client = $this->fixtureClient(
            $this->xml([
                ['ProductCode' => 'MSAN-HIGH', 'ProductPartnerPrice' => '100,00', 'RecommendedRetailPrice' => '150,25'],
                ['ProductCode' => 'ERP-OWNED', 'ProductPartnerPrice' => '80,00', 'RecommendedRetailPrice' => '250,00'],
                ['ProductCode' => 'NOT-IMPORTED', 'ProductPartnerPrice' => '30,00', 'RecommendedRetailPrice' => '50,00'],
            ]),
            $this->xml([
                ['ProductCode' => 'MSAN-HIGH', 'ProductAvailability' => '4'],
                ['ProductCode' => 'ERP-OWNED', 'ProductAvailability' => '1'],
                ['ProductCode' => 'NOT-IMPORTED', 'ProductAvailability' => '2'],
            ]),
        );

        $this->syncService($client)->sync($run);

        $this->assertSame(['prices', 'availability'], $client->downloads);
        $this->assertSame('150.2500', $ownedHigh->fresh()->recommended_retail_price);
        $this->assertSame(4, $ownedHigh->fresh()->availability_level);
        $this->assertSame('250.0000', $erpOwned->fresh()->recommended_retail_price);
        $this->assertSame(1, $erpOwned->fresh()->availability_level);
        $this->assertNull($ownedMissing->fresh()->recommended_retail_price);
        $this->assertNull($ownedMissing->fresh()->availability_level);
        $this->assertSame('40.0000', $unimported->fresh()->recommended_retail_price);
        $this->assertSame(4, $unimported->fresh()->availability_level);

        $ownedProduct = $ownedHigh->localProduct()->firstOrFail();
        $this->assertSame('150.25', $ownedProduct->base_price);
        $this->assertSame(10, $ownedProduct->stock_qty);
        $this->assertSame('150.2500', data_get($ownedProduct->payload, 'supplier_sources.msan.recommended_retail_price'));
        $this->assertSame(4, data_get($ownedProduct->payload, 'supplier_sources.msan.availability_level'));
        $this->assertSame('777.00', $erpOwned->localProduct()->firstOrFail()->base_price);
        $this->assertSame(77, $erpOwned->localProduct()->firstOrFail()->stock_qty);
        $this->assertSame('90.00', $ownedMissing->localProduct()->firstOrFail()->base_price);
        $this->assertSame(0, $ownedMissing->localProduct()->firstOrFail()->stock_qty);

        $this->assertSame(2, ProductPriceHistory::query()
            ->where('product_id', $ownedProduct->id)
            ->where('price_type', 'base')
            ->count());
        $history = ProductPriceHistory::query()
            ->where('product_id', $ownedProduct->id)
            ->where('price_type', 'base')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(100.0, (float) $history->old_price);
        $this->assertSame(150.25, (float) $history->new_price);

        $run->refresh();
        $this->assertSame(MsanSyncRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(6, $run->total_count);
        $this->assertSame(4, $run->processed_count);
        $this->assertSame(2, data_get($run->summary, 'local_products_eligible'));
        $this->assertSame(1, data_get($run->summary, 'local_prices_updated'));
        $this->assertSame(1, data_get($run->summary, 'local_prices_missing'));
        $this->assertSame(2, data_get($run->summary, 'local_stock_updated'));
        $this->assertSame(1, data_get($run->summary, 'local_products_not_msan_owned'));
        $this->assertSame(3, data_get($run->summary, 'staging_products'));
        $this->assertSame(2, data_get($run->summary, 'price_rows_matched'));
        $this->assertSame(2, data_get($run->summary, 'availability_rows_matched'));
    }

    public function test_refresh_updates_imported_stock_when_only_unimported_feed_rows_have_prices(): void
    {
        $this->configureSync();
        $withoutPrice = $this->sourceWithLocalProduct('IMPORTED-PRICE', 9, true, 4, 100, 110);
        $withoutPriceOne = $this->sourceWithLocalProduct('IMPORTED-NO-PRICE-1', 9, true, 4, 200, 210);
        $withoutPriceTwo = $this->sourceWithLocalProduct('IMPORTED-NO-PRICE-2', 9, true, 4, 300, 310);

        $unimported = null;
        foreach (range(1, 12) as $index) {
            $source = MsanProduct::query()->create([
                'external_code' => 'NOT-IMPORTED-'.$index,
                'recommended_retail_price' => 50,
                'availability_level' => 4,
                'last_seen_at' => now(),
                'is_stale' => false,
            ]);
            $unimported ??= $source;
        }

        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_PRICES,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        $this->syncService($this->fixtureClient(
            $this->xml([
                ['ProductCode' => 'NOT-IMPORTED-1', 'RecommendedRetailPrice' => '150.00'],
            ]),
            $this->xml([
                ['ProductCode' => 'IMPORTED-PRICE', 'ProductAvailability' => '1'],
                ['ProductCode' => 'IMPORTED-NO-PRICE-1', 'ProductAvailability' => '2'],
                ['ProductCode' => 'IMPORTED-NO-PRICE-2', 'ProductAvailability' => '3'],
            ]),
        ))->sync($run);

        $this->assertSame('100.00', $withoutPrice->localProduct()->firstOrFail()->base_price);
        $this->assertSame('200.00', $withoutPriceOne->localProduct()->firstOrFail()->base_price);
        $this->assertSame('300.00', $withoutPriceTwo->localProduct()->firstOrFail()->base_price);
        $this->assertSame(1, $withoutPrice->localProduct()->firstOrFail()->stock_qty);
        $this->assertSame(3, $withoutPriceOne->localProduct()->firstOrFail()->stock_qty);
        $this->assertSame(5, $withoutPriceTwo->localProduct()->firstOrFail()->stock_qty);
        $this->assertSame('50.0000', $unimported?->fresh()->recommended_retail_price);
        $this->assertSame(MsanSyncRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(6, $run->fresh()->total_count);
        $this->assertSame(3, data_get($run->fresh()->summary, 'staging_products'));
        $this->assertSame(0, data_get($run->fresh()->summary, 'price_rows_matched'));
        $this->assertSame(3, data_get($run->fresh()->summary, 'availability_rows_matched'));
        $this->assertSame(3, data_get($run->fresh()->summary, 'local_prices_missing'));
    }

    public function test_incomplete_feed_rolls_back_all_staging_prices_availability_and_local_values(): void
    {
        $this->configureSync();
        $first = $this->sourceWithLocalProduct('ROLLBACK-1', 44, true, 3, 100, 110);
        $this->sourceWithLocalProduct('ROLLBACK-2', 55, true, 2, 200, 210);
        $this->sourceWithLocalProduct('ROLLBACK-3', 66, true, 1, 300, 310);

        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_PRICES,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        $service = $this->syncService($this->fixtureClient(
            $this->xml([
                ['ProductCode' => 'ROLLBACK-1', 'RecommendedRetailPrice' => '900.00'],
                ['ProductCode' => 'ROLLBACK-2', 'RecommendedRetailPrice' => '901.00'],
                ['ProductCode' => 'ROLLBACK-3', 'RecommendedRetailPrice' => '902.00'],
            ]),
            $this->xml([
                ['ProductCode' => 'ROLLBACK-1', 'ProductAvailability' => '0'],
            ]),
        ));

        try {
            $service->sync($run);
            $this->fail('Premali M SAN availability snapshot mora biti odbijen.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('pokriva premalo artikala', $exception->getMessage());
        }

        $this->assertSame('110.0000', $first->fresh()->recommended_retail_price);
        $this->assertSame(3, $first->fresh()->availability_level);
        $this->assertSame('100.00', $first->localProduct()->firstOrFail()->base_price);
        $this->assertSame(44, $first->localProduct()->firstOrFail()->stock_qty);
        $this->assertSame(MsanSyncRun::STATUS_RUNNING, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->error_message);
    }

    public function test_mpc_update_respects_the_shop_tax_storage_mode(): void
    {
        $this->configureSync();
        $taxRate = TaxRate::query()->create([
            'code' => 'PDV25-MSAN-SYNC',
            'name' => 'PDV 25%',
            'rate_type' => 'percent',
            'rate' => 25,
            'is_default' => true,
            'is_active' => true,
        ]);
        $source = $this->sourceWithLocalProduct('MSAN-TAX', 1, true, localPrice: 80, sourcePrice: 100);
        $source->localProduct()->update(['tax_rate_id' => $taxRate->id]);
        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', false);

        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_PRICES,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        $this->syncService($this->fixtureClient(
            $this->xml([['ProductCode' => 'MSAN-TAX', 'RecommendedRetailPrice' => '125.00']]),
            $this->xml([['ProductCode' => 'MSAN-TAX', 'ProductAvailability' => '2']]),
        ))->sync($run);

        $this->assertSame('100.00', $source->localProduct()->firstOrFail()->base_price);

        app(SystemSettingsService::class)->put('store_pricing_prices_include_tax', true);
        $grossRun = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_PRICES,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        $this->syncService($this->fixtureClient(
            $this->xml([['ProductCode' => 'MSAN-TAX', 'RecommendedRetailPrice' => '150.00']]),
            $this->xml([['ProductCode' => 'MSAN-TAX', 'ProductAvailability' => '2']]),
        ))->sync($grossRun);

        $this->assertSame('150.00', $source->localProduct()->firstOrFail()->base_price);
    }

    public function test_catalog_mapping_alone_prevents_msan_from_overwriting_an_adopted_product(): void
    {
        $this->configureSync();
        $source = $this->sourceWithLocalProduct('MSAN-ADOPTED', 12, true, 3, 80, 100);
        CatalogSourceMapping::query()->create([
            'source' => 'konto',
            'entity_type' => CatalogSourceMapping::ENTITY_PRODUCT,
            'source_id' => 'ERP-ADOPTED',
            'local_id' => $source->local_product_id,
            'lifecycle_status' => 'a',
        ]);
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_PRICES,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);

        $this->syncService($this->fixtureClient(
            $this->xml([['ProductCode' => 'MSAN-ADOPTED', 'RecommendedRetailPrice' => '150.00']]),
            $this->xml([['ProductCode' => 'MSAN-ADOPTED', 'ProductAvailability' => '1']]),
        ))->sync($run);

        $local = $source->localProduct()->firstOrFail();
        $this->assertSame('80.00', $local->base_price);
        $this->assertSame(12, $local->stock_qty);
        $this->assertSame(1, data_get($run->fresh()->summary, 'local_products_not_msan_owned'));
    }

    public function test_known_products_with_invalid_availability_values_reject_the_whole_refresh(): void
    {
        $this->configureSync();
        $first = $this->sourceWithLocalProduct('INVALID-STOCK-1', 7, true, 3, 80, 100);
        $second = $this->sourceWithLocalProduct('INVALID-STOCK-2', 8, true, 2, 90, 110);
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_PRICES,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);

        try {
            $this->syncService($this->fixtureClient(
                $this->xml([
                    ['ProductCode' => 'INVALID-STOCK-1', 'RecommendedRetailPrice' => '120.00'],
                    ['ProductCode' => 'INVALID-STOCK-2', 'RecommendedRetailPrice' => '130.00'],
                ]),
                $this->xml([
                    ['ProductCode' => 'INVALID-STOCK-1'],
                    ['ProductCode' => 'INVALID-STOCK-2', 'ProductAvailability' => 'unknown'],
                ]),
            ))->sync($run);
            $this->fail('Nevaljane vrijednosti dostupnosti moraju odbiti cijeli snapshot.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('nevaljanu vrijednost', $exception->getMessage());
        }

        $this->assertSame(7, $first->localProduct()->firstOrFail()->stock_qty);
        $this->assertSame(8, $second->localProduct()->firstOrFail()->stock_qty);
        $this->assertSame(3, $first->fresh()->availability_level);
        $this->assertSame(2, $second->fresh()->availability_level);
    }

    public function test_price_feed_without_usable_mpc_values_is_not_reported_as_successful(): void
    {
        $this->configureSync();
        $first = $this->sourceWithLocalProduct('INVALID-PRICE-1', 7, true, 3, 80, 100);
        $second = $this->sourceWithLocalProduct('INVALID-PRICE-2', 8, true, 2, 90, 110);
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_PRICES,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);

        try {
            $this->syncService($this->fixtureClient(
                $this->xml([
                    ['ProductCode' => 'INVALID-PRICE-1'],
                    ['ProductCode' => 'INVALID-PRICE-2', 'RecommendedRetailPrice' => '0'],
                ]),
                $this->xml([
                    ['ProductCode' => 'INVALID-PRICE-1', 'ProductAvailability' => '1'],
                    ['ProductCode' => 'INVALID-PRICE-2', 'ProductAvailability' => '1'],
                ]),
            ))->sync($run);
            $this->fail('Snapshot bez valjanih MPC cijena mora biti odbijen.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('valjanih MPC cijena', $exception->getMessage());
        }

        $this->assertSame('80.00', $first->localProduct()->firstOrFail()->base_price);
        $this->assertSame('90.00', $second->localProduct()->firstOrFail()->base_price);
        $this->assertSame('100.0000', $first->fresh()->recommended_retail_price);
        $this->assertSame('110.0000', $second->fresh()->recommended_retail_price);
    }

    public function test_stale_msan_owned_product_is_made_unavailable_without_overwriting_its_price(): void
    {
        Queue::fake();
        $this->configureSync();
        $stale = $this->sourceWithLocalProduct('STALE-1', 10, true, 4, 120, 130);
        $stale->update(['is_stale' => true]);
        $run = app(MsanCatalogSyncCoordinator::class)->queuePricesAndStock();
        $this->assertNotNull($run);
        Queue::assertPushed(SyncMsanPricesAndStockJob::class);

        $client = $this->fixtureClient(
            $this->xml([['ProductCode' => 'ACTIVE-1', 'RecommendedRetailPrice' => '50.00']]),
            $this->xml([['ProductCode' => 'ACTIVE-1', 'ProductAvailability' => '2']]),
        );
        $this->syncService($client)->sync($run);

        $local = $stale->localProduct()->firstOrFail();
        $this->assertSame('120.00', $local->base_price);
        $this->assertSame(0, $local->stock_qty);
        $this->assertSame([], $client->downloads);
        $this->assertSame(MsanSyncRun::STATUS_COMPLETED, $run->fresh()->status);
        $this->assertSame(1, data_get($run->fresh()->summary, 'local_stale_stock_zeroed'));
    }

    public function test_duplicate_availability_codes_do_not_satisfy_the_coverage_guard(): void
    {
        $this->configureSync();
        foreach (range(1, 3) as $index) {
            $this->sourceWithLocalProduct('DUP-'.$index, 1, true, 1, 50, 50);
        }
        $run = MsanSyncRun::query()->create([
            'kind' => MsanSyncRun::KIND_PRICES,
            'status' => MsanSyncRun::STATUS_PENDING,
        ]);
        $priceRows = array_map(static fn (int $index): array => [
            'ProductCode' => 'DUP-'.$index,
            'RecommendedRetailPrice' => '50.00',
        ], range(1, 3));
        $duplicateAvailabilityRows = array_fill(0, 10, [
            'ProductCode' => 'DUP-1',
            'ProductAvailability' => '2',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pokriva premalo artikala');
        $this->syncService($this->fixtureClient(
            $this->xml($priceRows),
            $this->xml($duplicateAvailabilityRows),
        ))->sync($run);
    }

    public function test_coordinator_queues_one_price_and_stock_job_and_excludes_other_runs(): void
    {
        Queue::fake();
        $this->configureSync();
        $this->sourceWithLocalProduct('QUEUE-1', 1, true, 1, 50, 50);

        $coordinator = app(MsanCatalogSyncCoordinator::class);
        $run = $coordinator->queuePricesAndStock();

        $this->assertNotNull($run);
        $this->assertSame(MsanSyncRun::KIND_PRICES, $run->kind);
        $this->assertNull($run->requested_by);
        Queue::assertPushed(SyncMsanPricesAndStockJob::class, fn (SyncMsanPricesAndStockJob $job): bool => $job->queue === 'integrations');
        $this->assertNotSame(
            (new SyncMsanPricesAndStockJob((int) $run->id))->uniqueId(),
            (new SyncMsanPricesAndStockJob((int) $run->id + 1))->uniqueId(),
        );

        $this->assertNull($coordinator->queuePricesAndStock(scheduled: true));
        Queue::assertPushed(SyncMsanPricesAndStockJob::class, 1);

        try {
            $coordinator->queueFullSync();
            $this->fail('Puni sync ne smije krenuti tijekom osvježavanja cijena i količina.');
        } catch (DomainException) {
            $this->assertTrue(true);
        }

        $this->expectException(DomainException::class);
        app(MsanImportCoordinator::class)->queueSelected();
    }

    public function test_scheduled_queue_is_a_quiet_noop_when_integration_or_automatic_sync_is_disabled(): void
    {
        Queue::fake();
        MsanProduct::query()->create([
            'external_code' => 'DISABLED-1',
            'last_seen_at' => now(),
            'is_stale' => false,
        ]);

        $this->assertNull(app(MsanCatalogSyncCoordinator::class)->queuePricesAndStock(scheduled: true));

        $this->configureSync();
        app(MsanSettingsService::class)->saveAdminValues(['msan_price_stock_sync_enabled' => false]);
        $this->assertNull(app(MsanCatalogSyncCoordinator::class)->queuePricesAndStock(scheduled: true));
        $this->assertSame(0, MsanSyncRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_price_and_stock_dispatcher_runs_every_minute_and_saved_cron_controls_when_it_is_due(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($scheduledEvent): bool => $scheduledEvent->description === 'msan-prices-stock-sync');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);

        $settings = app(MsanSettingsService::class);
        $zone = new DateTimeZone(MsanSettingsService::PRICE_STOCK_SYNC_TIMEZONE);
        $this->assertSame('*/15 * * * *', $settings->priceStockSyncCron());
        $this->assertTrue($settings->priceStockSyncIsDue(new DateTimeImmutable('2026-09-03 08:15:00', $zone)));
        $this->assertFalse($settings->priceStockSyncIsDue(new DateTimeImmutable('2026-09-03 08:16:00', $zone)));

        $settings->saveAdminValues(['msan_price_stock_sync_cron' => '0 */6 * * *']);
        $this->assertSame('0 */6 * * *', $settings->priceStockSyncCron());
        $this->assertTrue($settings->priceStockSyncIsDue(new DateTimeImmutable('2026-09-03 12:00:00', $zone)));
        $this->assertFalse($settings->priceStockSyncIsDue(new DateTimeImmutable('2026-09-03 12:15:00', $zone)));
        $this->assertFalse(MsanSettingsService::isValidPriceStockSyncCron('*/5 * * * *'));
        $this->assertFalse(MsanSettingsService::isValidPriceStockSyncCron(
            '0,55 0,2,4,6,8,10,12,14,16,18,20,22,23 * * *',
        ));
        $this->assertFalse(MsanSettingsService::isValidPriceStockSyncCron('0,55 0,23 * * *'));
        $this->assertTrue(MsanSettingsService::isValidPriceStockSyncCron('0,55 0,23 * * 1'));
        $this->assertFalse(MsanSettingsService::isValidPriceStockSyncCron('0 0 31 2 *'));
        $this->assertFalse(MsanSettingsService::isValidPriceStockSyncCron('nije cron'));
    }

    private function configureSync(): void
    {
        app(MsanSettingsService::class)->saveAdminValues([
            'msan_enabled' => true,
            'msan_price_stock_sync_enabled' => true,
            'msan_price_stock_sync_cron' => '*/15 * * * *',
            'msan_stock_level_0' => 0,
            'msan_stock_level_1' => 1,
            'msan_stock_level_2' => 3,
            'msan_stock_level_3' => 5,
            'msan_stock_level_4' => 10,
        ]);
    }

    private function sourceWithLocalProduct(
        string $code,
        int $stock,
        bool $msanOwned,
        int $availability = 4,
        float $localPrice = 99,
        float $sourcePrice = 125,
    ): MsanProduct {
        $payload = [
            'catalog_origin' => $msanOwned ? 'msan' : 'erp',
            'supplier_sources' => [
                'msan' => [
                    'external_code' => $code,
                    'recommended_retail_price' => number_format($sourcePrice, 4, '.', ''),
                    'availability_level' => $availability,
                ],
            ],
        ];
        $local = Product::query()->create([
            'code' => 'LOCAL-'.$code,
            'sku' => 'LOCAL-'.$code,
            'base_price' => $localPrice,
            'stock_qty' => $stock,
            'is_active' => true,
            'payload' => $payload,
        ]);

        return MsanProduct::query()->create([
            'external_code' => $code,
            'currency_code' => 'EUR',
            'recommended_retail_price' => $sourcePrice,
            'availability_level' => $availability,
            'local_product_id' => $local->id,
            'import_status' => MsanProduct::IMPORT_IMPORTED,
            'last_seen_at' => now(),
            'is_stale' => false,
        ]);
    }

    private function syncService(MsanClient $client): MsanPricesAndStockSyncService
    {
        return new MsanPricesAndStockSyncService(
            $client,
            app(MsanXmlStreamReader::class),
            app(MsanSettingsService::class),
            app(TaxPricingService::class),
        );
    }

    private function fixtureClient(string $pricesXml, string $availabilityXml): MsanClient
    {
        return new class($pricesXml, $availabilityXml) extends MsanClient
        {
            /** @var list<string> */
            public array $downloads = [];

            public function __construct(
                private readonly string $pricesXml,
                private readonly string $availabilityXml,
            ) {}

            public function downloadDataset(string $dataset, string $destinationPath): void
            {
                $this->downloads[] = $dataset;
                $contents = match ($dataset) {
                    'prices' => $this->pricesXml,
                    'availability' => $this->availabilityXml,
                    default => throw new RuntimeException('Brzi sync ne smije dohvaćati '.$dataset.'.'),
                };

                $directory = dirname($destinationPath);
                if (! is_dir($directory)) {
                    mkdir($directory, 0750, true);
                }
                file_put_contents($destinationPath, $contents);
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
