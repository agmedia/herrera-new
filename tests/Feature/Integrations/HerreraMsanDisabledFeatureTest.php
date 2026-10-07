<?php

namespace Tests\Feature\Integrations;

use App\Jobs\Integrations\Msan\DispatchMsanImportChunksJob;
use App\Jobs\Integrations\Msan\ImportMsanProductImageJob;
use App\Jobs\Integrations\Msan\ImportMsanProductsChunkJob;
use App\Jobs\Integrations\Msan\RepublishMsanSpecificationDefinitionJob;
use App\Jobs\Integrations\Msan\SyncEprelEnergyJob;
use App\Jobs\Integrations\Msan\SyncMsanAvailabilityJob;
use App\Jobs\Integrations\Msan\SyncMsanCatalogJob;
use App\Jobs\Integrations\Msan\SyncMsanPricesAndStockJob;
use App\Jobs\Integrations\Msan\SyncMsanSpecificationsJob;
use App\Jobs\Integrations\Msan\TestMsanConnectionJob;
use App\Jobs\Integrations\Msan\TestMsanFtpConnectionJob;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Integrations\Msan\MsanCatalogSyncCoordinator;
use App\Services\Integrations\Msan\MsanImportCoordinator;
use App\Services\Integrations\Msan\MsanSettingsService;
use App\Services\Settings\SystemSettingsService;
use App\Support\Integrations\MsanModule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class HerreraMsanDisabledFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_stored_supplier_toggles_cannot_enable_the_uninstalled_module_or_scheduler(): void
    {
        $settings = app(SystemSettingsService::class);
        $settings->putMany(['msan_enabled' => true, 'msan_price_stock_sync_enabled' => true]);
        $before = $settings->all();
        $this->assertFalse(MsanModule::available());
        $this->assertFalse(app(MsanSettingsService::class)->enabled());
        $this->assertFalse(app(MsanSettingsService::class)->priceStockSyncEnabled());
        $this->assertFalse(app(MsanSettingsService::class)->priceStockSyncIsDue());
        $this->assertNull(app(MsanCatalogSyncCoordinator::class)->queuePricesAndStock(scheduled: true));
        $this->assertFalse(collect(app(Schedule::class)->events())->contains(
            fn ($event): bool => str_contains((string) $event->description, 'msan'),
        ));
        $this->assertSame($before, $settings->all());
        $this->assertDatabaseCount('msan_sync_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_all_supplier_dispatch_entry_points_are_rejected_before_creating_history_or_jobs(): void
    {
        $coordinator = app(MsanCatalogSyncCoordinator::class);
        foreach (['queueFullSync', 'queueConnectionTest', 'queuePricesAndStock', 'queueAvailability', 'queueEprelEnergy', 'queueSpecifications', 'queueFtpConnectionTest'] as $method) {
            $this->assertRejected(fn () => $coordinator->{$method}());
        }
        $this->assertRejected(fn () => app(MsanImportCoordinator::class)->queueSelected());
        $this->assertDatabaseCount('msan_sync_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('supplierJobs')]
    public function test_preexisting_supplier_jobs_are_rejected_before_touching_data_or_network(string $jobClass, array $constructorArguments): void
    {
        $source = MsanProduct::query()->create(['external_code' => 'PRESERVED', 'name' => 'Sačuvani dobavljački zapis']);
        $run = MsanSyncRun::query()->create(['kind' => MsanSyncRun::KIND_FULL, 'status' => MsanSyncRun::STATUS_PENDING]);
        $beforeSource = $source->refresh()->getAttributes();
        $beforeRun = $run->refresh()->getAttributes();
        $job = new $jobClass(...$constructorArguments);
        $arguments = [];
        foreach ((new ReflectionMethod($job, 'handle'))->getParameters() as $parameter) {
            $arguments[] = Mockery::mock($parameter->getType()->getName());
        }
        $this->assertRejected(fn () => $job->handle(...$arguments));
        if (method_exists($job, 'failed')) {
            // A queue worker may invoke failed() after rejecting an old payload.
            $job->failed(new RuntimeException('Retired supplier job.'));
        }
        $this->assertSame($beforeSource, $source->fresh()->getAttributes());
        $this->assertSame($beforeRun, $run->fresh()->getAttributes());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function supplierJobs(): array
    {
        return [
            [SyncMsanCatalogJob::class, [1]],
            [SyncMsanAvailabilityJob::class, [1]],
            [SyncMsanPricesAndStockJob::class, [1]],
            [SyncMsanSpecificationsJob::class, [1]],
            [SyncEprelEnergyJob::class, [1]],
            [TestMsanConnectionJob::class, [1]],
            [TestMsanFtpConnectionJob::class, [1]],
            [DispatchMsanImportChunksJob::class, [1]],
            [ImportMsanProductsChunkJob::class, [1, [1]]],
            [ImportMsanProductImageJob::class, [1]],
            [RepublishMsanSpecificationDefinitionJob::class, [1]],
        ];
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Uninstalled supplier module must reject the operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('M SAN dobavljački modul nije dostupan u ovoj trgovini.', $exception->getMessage());
        }
    }
}
