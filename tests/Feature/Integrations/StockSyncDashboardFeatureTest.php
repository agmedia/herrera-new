<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Admin\Integrations\Stock\Dashboard;
use App\Models\Integrations\Stock\StockSyncRun;
use App\Models\User;
use App\Services\Integrations\Stock\StockSyncService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use App\Support\Integrations\Stock\StockSyncRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class StockSyncDashboardFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->settings();
    }

    public function test_authorized_admin_sees_all_eight_sources_and_navigation(): void
    {
        $this->actingAs($this->admin())->get(route('admin.integrations.stock.index'))
            ->assertOk()->assertSee('Zalihe i cronovi')
            ->assertSee('Zadnji uspješan cron')->assertSee('Povijest izvršavanja')
            ->assertSee(route('admin.integrations.stock.index'), false)
            ->assertSee('fixture-cron-token');

        Livewire::test(Dashboard::class)->assertViewHas('sources', static function (array $sources): bool {
            return array_keys($sources) === array_keys(StockSyncRegistry::all())
                && count($sources) === 8
                && $sources['brock']['cron_enabled'] === false
                && $sources['dpm']['cron_enabled'] === false;
        });
        Http::assertNothingSent();
    }

    public function test_unprivileged_admin_cannot_access_dashboard_and_does_not_get_cron_urls(): void
    {
        $this->actingAs($this->admin(manage: false))
            ->get(route('admin.integrations.stock.index'))->assertForbidden();
        Livewire::test(Dashboard::class)->assertForbidden();
        $this->get(route('admin.help.index'))->assertOk()
            ->assertDontSee(route('admin.integrations.stock.index'), false)
            ->assertDontSee('fixture-cron-token');
    }

    public function test_superadmin_can_access_and_guest_is_redirected(): void
    {
        $this->get(route('admin.integrations.stock.index'))->assertRedirect();
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'superadmin']);
        Bouncer::assign('superadmin')->to($user);
        $this->actingAs($user)->get(route('admin.integrations.stock.index'))->assertOk();
    }

    public function test_manual_run_works_when_cron_is_disabled_and_opens_the_persisted_report(): void
    {
        $admin = $this->admin();
        $run = $this->createRun(['supplier' => 'brock', 'trigger' => 'manual', 'actor_id' => $admin->id, 'updated_count' => 3]);
        $this->mock(StockSyncService::class, function (MockInterface $mock) use ($admin, $run): void {
            $mock->shouldReceive('run')->once()->with('brock', 'manual', $admin->id)->andReturn($run);
        });

        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->call('runSupplier', 'brock')
            ->assertSet('selectedRunId', $run->id)
            ->assertViewHas('selectedRun', fn ($selected): bool => $selected->is($run))
            ->assertDispatched('notify', type: 'success');
        Http::assertNothingSent();
    }

    public function test_unconfigured_source_cannot_be_run_or_enabled_but_can_be_disabled(): void
    {
        $settings = $this->settings(configured: false);
        $settings->shouldReceive('saveEnabled')->once()->with('videx', false);
        $this->mock(StockSyncService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('run'));

        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)
            ->call('runSupplier', 'videx')->assertDispatched('notify', type: 'warning')
            ->call('toggleCron', 'videx', true)->assertDispatched('notify', type: 'warning')
            ->call('toggleCron', 'videx', false)->assertDispatched('notify', type: 'success');
        Http::assertNothingSent();
    }

    public function test_actions_recheck_authority_and_unknown_supplier_is_rejected(): void
    {
        $this->mock(StockSyncService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('run'));
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('runSupplier', 'unknown')->assertNotFound();

        $component = Livewire::test(Dashboard::class);
        $this->actingAs($this->admin(manage: false));
        $component->call('runSupplier', 'videx')->assertForbidden();
    }

    public function test_cron_status_keeps_last_attempt_separate_from_last_success_and_manual_runs(): void
    {
        $success = $this->createRun(['supplier' => 'videx', 'trigger' => 'cron', 'updated_count' => 12]);
        $failed = $this->createRun(['supplier' => 'videx', 'trigger' => 'cron', 'status' => 'failed', 'error_message' => 'Izvor trenutno nije dostupan.']);
        $manual = $this->createRun(['supplier' => 'videx', 'trigger' => 'manual']);

        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->assertViewHas('sources', static fn (array $sources): bool => $sources['videx']['last_cron_success']->is($success)
            && $sources['videx']['last_cron_attempt']->is($failed)
            && $sources['videx']['latest_run']->is($manual));
    }

    public function test_stale_running_run_can_be_retried_and_recent_running_run_is_shown_as_active(): void
    {
        $completed = $this->createRun(['supplier' => 'videx', 'trigger' => 'manual']);
        $this->createRun(['supplier' => 'videx', 'status' => 'running', 'started_at' => now()->subMinutes(11), 'completed_at' => null]);
        $this->createRun(['supplier' => 'master', 'status' => 'running', 'started_at' => now()->subMinute(), 'completed_at' => null]);
        $this->mock(StockSyncService::class, fn (MockInterface $mock) => $mock->shouldReceive('run')->once()->andReturn($completed));

        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)
            ->assertViewHas('sources', static fn (array $sources): bool => ! $sources['videx']['is_running'] && $sources['videx']['is_interrupted']
                && $sources['master']['is_running'] && ! $sources['master']['is_interrupted'])
            ->assertSee('Prethodno osvježavanje nije završeno.')
            ->assertSee('Potrebno ponoviti')
            ->call('runSupplier', 'videx')->assertSet('selectedRunId', $completed->id);
    }

    public function test_history_filters_and_item_pagination_preserve_the_selected_report(): void
    {
        $selected = $this->createRun(['supplier' => 'videx', 'trigger' => 'cron']);
        $this->createRun(['supplier' => 'videx', 'trigger' => 'manual']);
        $this->createRun(['supplier' => 'brock', 'trigger' => 'cron']);
        $this->createRun(['supplier' => 'videx', 'trigger' => 'cron', 'status' => 'failed']);
        for ($i = 1; $i <= 28; $i++) {
            $selected->items()->create([
                'identifier' => sprintf('REPORT-%03d', $i),
                'status' => $i === 28 ? 'unmatched' : 'updated',
                'old_quantity' => $i === 28 ? null : 1,
                'new_quantity' => 5,
                'message' => $i === 28 ? 'Artikl nije pronađen.' : null,
            ]);
        }

        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)
            ->set('supplierFilter', 'videx')->set('triggerFilter', 'cron')->set('statusFilter', 'completed')
            ->assertViewHas('runs', static fn ($runs): bool => $runs->total() === 1 && $runs->first()->is($selected))
            ->call('selectRun', $selected->id)->assertSee('REPORT-001')->assertDontSee('REPORT-026')
            ->call('gotoPage', 2, 'stockItemsPage')->assertSee('REPORT-026')->assertSet('selectedRunId', $selected->id)
            ->set('itemStatus', 'unmatched')->assertViewHas('items', static fn ($items): bool => $items->total() === 1)
            ->assertSee('REPORT-028')->assertDontSee('REPORT-001')
            ->call('closeReport')->assertSet('selectedRunId', null);
    }

    public function test_transport_exception_is_reported_without_exposing_connection_secrets(): void
    {
        $this->mock(StockSyncService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')->once()->andThrow(new RuntimeException('supplier-password-must-stay-private'));
        });
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('runSupplier', 'videx')
            ->assertDispatched('notify', type: 'error', message: 'Osvježavanje nije moguće pokrenuti. Provjerite vezu i je li obrada već u tijeku.')
            ->assertDontSee('supplier-password-must-stay-private');
    }

    private function settings(bool $configured = true): MockInterface
    {
        $mock = Mockery::mock(StockSyncSettingsService::class);
        $mock->shouldReceive('enabled')->andReturnUsing(static fn (string $supplier): bool => ! in_array($supplier, ['brock', 'dpm'], true));
        $mock->shouldReceive('configured')->andReturn($configured);
        $mock->shouldReceive('cronUrl')->andReturnUsing(static fn (string $supplier): string => 'https://new.herrera.test/integrations/stock/'.$supplier.'?token=fixture-cron-token');
        $this->app->instance(StockSyncSettingsService::class, $mock);

        return $mock;
    }

    private function createRun(array $attributes = []): StockSyncRun
    {
        return StockSyncRun::query()->forceCreate(array_replace([
            'supplier' => 'videx', 'trigger' => 'cron', 'status' => 'completed',
            'started_at' => now()->subMinute(), 'completed_at' => now(),
            'fetched_count' => 20, 'matched_count' => 15, 'updated_count' => 0,
            'unchanged_count' => 15, 'unmatched_count' => 5, 'invalid_count' => 0,
            'summary' => [],
        ], $attributes));
    }

    private function admin(bool $manage = true): User
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'stock-fixture-admin']);
        Bouncer::assign('stock-fixture-admin')->to($user);
        Bouncer::allow($user)->to('admin.access');
        if ($manage) {
            Bouncer::allow($user)->to('integrations.stock.manage');
        }

        return $user;
    }
}
