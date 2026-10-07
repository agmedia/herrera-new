<?php

namespace Tests\Feature\Admin;

use App\Livewire\Admin\Integrations\Eprel\CatalogSyncManager;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Integrations\Eprel\EprelCatalogSyncRun;
use App\Models\User;
use App\Services\Integrations\Eprel\EprelCatalogSyncService;
use App\Services\Integrations\Eprel\EprelSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class EprelCatalogSyncManagerFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
    }

    public function test_authorized_page_starts_with_a_small_pilot_and_never_dispatches_on_load(): void
    {
        $this->actingAs($this->admin());
        $this->get(route('admin.integrations.eprel.catalog'))->assertOk()
            ->assertSee('EPREL povezivanje kataloga')->assertSee('Pokreni probni paket')
            ->assertSee('Prođi cijeli katalog')->assertSee('wire:confirm=', false)
            ->assertSee('Najprije uključite EPREL')->assertDontSee('wire:poll.visible.5s', false);
        Livewire::test(CatalogSyncManager::class)->assertSet('limit', 10)->assertSet('categoryId', '');
        $this->assertDatabaseCount('eprel_catalog_sync_runs', 0);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_start_sends_only_the_validated_bounded_scope_and_actor_to_the_backend(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->configure();
        $category = $this->category();
        $run = $this->syncRun();
        $this->mock(EprelCatalogSyncService::class)->shouldReceive('start')->once()
            ->with(5, $category->id, $admin->id)->andReturnUsing(function () use ($run) {
                $run->update(['status' => 'pending']);

                return $run;
            });
        Livewire::test(CatalogSyncManager::class)->set('limit', 5)->set('categoryId', (string) $category->id)
            ->call('start')->assertHasNoErrors()->assertSet('selectedRunId', $run->id)
            ->assertDispatched('notify')->assertSee('wire:poll.visible.5s', false);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_full_catalog_action_uses_start_all_instead_of_the_pilot_limit(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->configure();
        $run = $this->syncRun();
        $this->mock(EprelCatalogSyncService::class)->shouldReceive('startAll')->once()
            ->with(null, $admin->id)->andReturn($run);
        Livewire::test(CatalogSyncManager::class)->set('limit', 99)->call('startAll')
            ->assertHasNoErrors()->assertSet('selectedRunId', $run->id)->assertDispatched('notify');
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    #[DataProvider('invalidScopes')]
    public function test_invalid_packet_or_category_cannot_reach_the_backend(string $field, mixed $value): void
    {
        $this->actingAs($this->admin());
        $this->configure();
        $this->mock(EprelCatalogSyncService::class)->shouldNotReceive('start');
        Livewire::test(CatalogSyncManager::class)->set($field, $value)->call('start')->assertHasErrors($field);
        $this->assertDatabaseCount('eprel_catalog_sync_runs', 0);
    }

    public static function invalidScopes(): array
    {
        return [['limit', 0], ['limit', 51], ['categoryId', '999999'], ['categoryId', 'not-an-id']];
    }

    public function test_inactive_or_non_catalog_categories_are_not_accepted(): void
    {
        $this->actingAs($this->admin());
        $this->configure();
        foreach ([['scope' => 'catalog', 'is_active' => false], ['scope' => 'blog', 'is_active' => true]] as $attributes) {
            $category = Category::query()->create($attributes + ['code' => 'INVALID-'.count($attributes).'-'.Category::query()->count()]);
            Livewire::test(CatalogSyncManager::class)->set('categoryId', (string) $category->id)
                ->call('start')->assertHasErrors('categoryId');
        }
        $this->assertDatabaseCount('eprel_catalog_sync_runs', 0);
    }

    public function test_missing_key_or_disabled_integration_never_starts_a_packet(): void
    {
        $this->actingAs($this->admin());
        $this->mock(EprelCatalogSyncService::class)->shouldNotReceive('start')->shouldNotReceive('startAll');
        Livewire::test(CatalogSyncManager::class)->call('start')->assertHasErrors('operation');
        Livewire::test(CatalogSyncManager::class)->call('startAll')->assertHasErrors('operation');
        $this->assertDatabaseCount('eprel_catalog_sync_runs', 0);
        Bus::assertNothingDispatched();
    }

    public function test_history_and_report_are_escaped_and_never_render_raw_secret_bearing_messages(): void
    {
        $admin = $this->admin();
        Bouncer::allow($admin)->to('catalog.products.update');
        $this->actingAs($admin);
        $run = $this->syncRun('paused');
        $run->update(['total_count' => 2, 'processed_count' => 1, 'matched_count' => 1, 'error_message' => 'x-api-key: secret-run-value']);
        $product = Product::query()->create(['code' => 'REPORT-CODE', 'sku' => '<script>bad-sku</script>', 'is_active' => true]);
        $product->translations()->create(['locale' => 'hr', 'name' => '<script>bad-name</script>', 'slug' => 'report-product']);
        $run->items()->create([
            'product_id' => $product->id, 'status' => 'matched', 'identity' => 'fixture', 'criteria' => [],
            'matched_registration' => '646868', 'message' => 'x-api-key: secret-item-value',
        ]);
        Livewire::test(CatalogSyncManager::class)->assertSet('selectedRunId', $run->id)
            ->assertSee('Pauzirano')->assertSee('646868')->assertSee('Obrađeno 1 od 2')
            ->assertSee('&lt;script&gt;bad-name&lt;/script&gt;', false)
            ->assertSee(route('admin.products.edit', $product->id), false)
            ->assertDontSee('<script>bad-name</script>', false)->assertDontSee('secret-run-value')->assertDontSee('secret-item-value')
            ->assertDontSee('wire:poll.visible.5s', false)->assertSee('Nastavi obradu')->assertSee('Otkaži obradu');
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_cancel_and_resume_reauthorize_and_use_the_selected_persisted_run(): void
    {
        $this->actingAs($this->admin());
        $this->configure();
        $run = $this->syncRun('paused');
        $service = $this->mock(EprelCatalogSyncService::class);
        $service->shouldReceive('resume')->once()->withArgs(fn ($target) => $target instanceof EprelCatalogSyncRun && $target->id === $run->id);
        $service->shouldReceive('cancel')->once()->withArgs(fn ($target) => $target instanceof EprelCatalogSyncRun && $target->id === $run->id);
        Livewire::test(CatalogSyncManager::class)->call('resume', $run->id)->assertHasNoErrors()->assertDispatched('notify')
            ->call('cancel', $run->id)->assertHasNoErrors();
    }

    public function test_partial_catalog_planning_never_looks_like_finished_api_processing(): void
    {
        $this->actingAs($this->admin());
        $run = $this->syncRun('running');
        $run->update([
            'planning_complete' => false, 'total_count' => 100, 'processed_count' => 99,
            'skipped_count' => 99, 'error_message' => 'x-api-key: private-planning-value',
        ]);
        $component = Livewire::test(CatalogSyncManager::class)
            ->assertSee('Priprema kataloga')->assertSee('Dosad dodano 100 artikala')
            ->assertSee('Popis artikala još raste. EPREL API pretraga počinje nakon završetka pripreme kataloga.')
            ->assertSee('Pripremljeno')->assertSee('100 pripremljeno')
            ->assertSee('aria-valuenow="0"', false)->assertSee('aria-busy="true"', false)
            ->assertDontSee('aria-valuenow="99"', false)->assertDontSee('aria-valuenow="100"', false)
            ->assertDontSee('Obrađeno 99 od 100')->assertDontSee('private-planning-value');

        $run->update(['planning_complete' => true, 'status' => 'completed', 'processed_count' => 100]);
        $component->call('$refresh')->assertSee('Završeno')->assertSee('Obrađeno 100 od 100')
            ->assertSee('aria-valuenow="100"', false)->assertDontSee('aria-busy="true"', false)
            ->assertDontSee('Priprema kataloga')->assertDontSee('Popis artikala još raste.')
            ->assertDontSee('private-planning-value');
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_completed_runs_do_not_resume_or_cancel_and_invalid_selection_is_not_found(): void
    {
        $this->actingAs($this->admin());
        $this->configure();
        $run = $this->syncRun('completed');
        $this->mock(EprelCatalogSyncService::class)->shouldNotReceive('resume')->shouldNotReceive('cancel');
        Livewire::test(CatalogSyncManager::class)->call('resume', $run->id)->assertHasErrors('operation')
            ->call('cancel', $run->id)->assertHasErrors('operation');
        Livewire::test(CatalogSyncManager::class)->call('selectRun', 999999)->assertNotFound();
    }

    public function test_unauthorized_viewer_guest_and_revoked_manager_cannot_call_actions(): void
    {
        $this->get(route('admin.integrations.eprel.catalog'))->assertRedirect();
        $viewer = $this->admin(manage: false);
        $this->actingAs($viewer)->get(route('admin.integrations.eprel.catalog'))->assertForbidden();
        Livewire::test(CatalogSyncManager::class)->assertForbidden();
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->configure();
        $component = Livewire::test(CatalogSyncManager::class);
        $this->actingAs($viewer);
        $component->call('start')->assertForbidden();
        $this->assertDatabaseCount('eprel_catalog_sync_runs', 0);
        Bus::assertNothingDispatched();
    }

    public function test_superadmin_can_open_catalog_without_explicit_manager_ability(): void
    {
        $admin = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'superadmin']);
        Bouncer::assign('superadmin')->to($admin);
        $this->actingAs($admin)->get(route('admin.integrations.eprel.catalog'))->assertOk();
    }

    #[DataProvider('protectedActions')]
    public function test_every_action_rechecks_permissions_after_the_manager_loses_access(string $action): void
    {
        $manager = $this->admin();
        $this->actingAs($manager);
        $this->configure();
        $run = $this->syncRun('paused');
        $component = Livewire::test(CatalogSyncManager::class);
        $this->actingAs($this->admin(manage: false));
        $parameters = in_array($action, ['start', 'startAll'], true) ? [] : [$run->id];
        $component->call($action, ...$parameters)->assertForbidden();
        $this->assertSame('paused', $run->fresh()->status);
        $this->assertDatabaseCount('eprel_catalog_sync_runs', 1);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public static function protectedActions(): array
    {
        return [['start'], ['startAll'], ['cancel'], ['resume'], ['selectRun']];
    }

    public function test_backend_exception_does_not_expose_secret_request_details(): void
    {
        $this->actingAs($this->admin());
        $this->configure();
        $this->mock(EprelCatalogSyncService::class)->shouldReceive('start')->once()
            ->andThrow(new \RuntimeException('x-api-key: private-backend-value'));
        Livewire::test(CatalogSyncManager::class)->call('start')->assertHasErrors('operation')
            ->assertDontSee('private-backend-value')->assertSee('Obrada nije pokrenuta.');
        $this->assertDatabaseCount('eprel_catalog_sync_runs', 0);
    }

    private function configure(): void
    {
        app(EprelSettingsService::class)->saveAdminValues(['eprel_enabled' => true, 'eprel_api_key' => 'synthetic-ui-only-key']);
    }

    private function syncRun(string $status = 'completed'): EprelCatalogSyncRun
    {
        return EprelCatalogSyncRun::query()->create(['status' => $status, 'max_products' => 10]);
    }

    private function category(): Category
    {
        $category = Category::query()->create(['scope' => 'catalog', 'code' => 'LIGHTING', 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'name' => 'Rasvjeta', 'slug' => 'rasvjeta']);

        return $category;
    }

    private function admin(bool $manage = true): User
    {
        $admin = User::factory()->create();
        Bouncer::allow($admin)->to('admin.access');
        if ($manage) {
            Bouncer::allow($admin)->to('integrations.eprel.settings.manage');
        }

        return $admin;
    }
}
