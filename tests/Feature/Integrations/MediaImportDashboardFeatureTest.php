<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Admin\Integrations\Media\Dashboard;
use App\Models\Integrations\Eracuni\EracuniCatalogRun;
use App\Models\User;
use App\Services\Integrations\Media\MediaImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class MediaImportDashboardFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->media();
    }

    public function test_authorized_admin_sees_both_sources_bounded_background_controls_and_preservation_explanation(): void
    {
        $this->actingAs($this->admin())->get(route('admin.integrations.media.index'))
            ->assertOk()->assertSee('Uvoz slika')->assertSee('e-Računi: glavne slike koje nedostaju')
            ->assertSee('Braytron: dodatne slike galerije')->assertSee('Postojeće ručno unesene slike se čuvaju.')
            ->assertSee('Pokreni prvi paket')->assertSee(route('admin.integrations.media.index'), false);
        Http::assertNothingSent();
    }

    public function test_guest_and_other_integration_manager_cannot_access_media_page_or_component(): void
    {
        $this->get(route('admin.integrations.media.index'))->assertRedirect();
        $user = $this->admin(manage: false);
        Bouncer::allow($user)->to('integrations.eracuni.manage');
        $this->actingAs($user)->get(route('admin.integrations.media.index'))->assertForbidden();
        Livewire::test(Dashboard::class)->assertForbidden();
        $this->get(route('admin.help.index'))->assertOk()->assertDontSee(route('admin.integrations.media.index'), false);
    }

    public function test_selected_products_are_queued_without_processing_or_network_calls(): void
    {
        $admin = $this->admin();
        $run = $this->createRun(['status' => 'queued']);
        $media = $this->media();
        $media->shouldReceive('start')->once()->with('images', [2], $admin->id)->andReturn($run);
        $media->shouldNotReceive('process');
        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->set('selectedProductIds', ['2'])->call('startSelected')
            ->assertHasNoErrors()->assertSet('selectedProductIds', [])->assertSet('selectedRunId', $run->id)
            ->assertViewHas('pollFrequently', true)->assertDispatched('notify', type: 'success');
        Http::assertNothingSent();
    }

    public function test_first_batch_passes_null_selection_for_backend_bounded_candidate_selection(): void
    {
        $admin = $this->admin();
        $run = $this->createRun(['kind' => 'braytron_images', 'status' => 'queued']);
        $media = $this->media();
        $media->shouldReceive('start')->once()->with('braytron_images', null, $admin->id)->andReturn($run);
        $media->shouldNotReceive('process');
        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->set('kind', 'braytron_images')->call('startBatch')
            ->assertHasNoErrors()->assertSet('selectedRunId', $run->id);
        Http::assertNothingSent();
    }

    public function test_unconfigured_source_and_foreign_selection_cannot_start_jobs(): void
    {
        $media = $this->media(configured: false);
        $media->shouldNotReceive('start');
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('startBatch')->assertDispatched('notify', type: 'warning');
        $media = $this->media();
        $media->shouldNotReceive('start');
        Livewire::test(Dashboard::class)->set('selectedProductIds', [999])->call('startSelected')->assertHasErrors('selectedProductIds');
    }

    public function test_changing_source_clears_selection_and_actions_recheck_permission(): void
    {
        $media = $this->media();
        $media->shouldNotReceive('start');
        $this->actingAs($this->admin());
        $component = Livewire::test(Dashboard::class)->set('selectedProductIds', [1, 2])->set('kind', 'braytron_images')
            ->assertSet('selectedProductIds', []);
        $this->actingAs($this->admin(manage: false));
        $component->call('startBatch')->assertForbidden();
    }

    public function test_media_history_cannot_expose_or_select_catalog_runs_and_paginates_reports(): void
    {
        $selected = $this->createRun();
        $catalog = $this->createRun(['kind' => 'preview']);
        $this->createRun(['kind' => 'braytron_images', 'status' => 'failed']);
        for ($i = 1; $i <= 28; $i++) {
            $selected->items()->create([
                'identifier' => sprintf('PHOTO-%03d', $i), 'name' => 'Artikl '.$i, 'status' => 'imported',
                'source_payload' => ['private_url' => 'supplier-secret-url'],
            ]);
        }
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->set('kindFilter', 'images')->set('statusFilter', 'completed')
            ->assertViewHas('runs', static fn ($runs): bool => $runs->total() === 1 && $runs->first()->is($selected))
            ->call('selectRun', $selected->id)->assertSee('PHOTO-001')->assertDontSee('PHOTO-026')
            ->assertDontSee('supplier-secret-url')->call('gotoPage', 2, 'mediaItemsPage')->assertSee('PHOTO-026');
        Livewire::test(Dashboard::class)->call('selectRun', $catalog->id)->assertNotFound();
    }

    public function test_candidate_pagination_does_not_drop_selections_from_previous_pages(): void
    {
        $this->media(candidateCount: 30);
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->assertSee('CANDIDATE-001')->assertDontSee('CANDIDATE-026')
            ->set('selectedProductIds', [1])->call('gotoPage', 2, 'mediaCandidatesPage')
            ->assertSee('CANDIDATE-026')->assertSet('selectedProductIds', [1]);
    }

    public function test_start_failure_has_safe_generic_message_without_supplier_url(): void
    {
        $media = $this->media();
        $media->shouldReceive('start')->once()->andThrow(new RuntimeException('https://secret-supplier.test/?token=private'));
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('startBatch')
            ->assertDispatched('notify', type: 'error', message: 'Obradu slika nije moguće pokrenuti. Provjerite izvor i je li obrada već u tijeku.')
            ->assertDontSee('secret-supplier');
    }

    public function test_completed_report_retries_failed_and_skipped_items_through_background_service(): void
    {
        $admin = $this->admin();
        $previous = $this->createRun(['kind' => 'braytron_images']);
        $previous->items()->create(['identifier' => 'RETRY-SKIPPED', 'name' => 'Artikl', 'status' => 'skipped']);
        $previous->items()->create(['identifier' => 'RETRY-FAILED', 'name' => 'Artikl', 'status' => 'failed']);
        $queued = $this->createRun(['kind' => 'braytron_images', 'status' => 'queued']);
        $media = $this->media();
        $media->shouldReceive('retry')->once()->with($previous->id, $admin->id)->andReturn($queued);
        $media->shouldNotReceive('process');
        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->call('selectRun', $previous->id)
            ->assertSee('Ponovi preskočene/neuspjele')->call('retryReport')
            ->assertSet('selectedRunId', $queued->id)->assertDispatched('notify', type: 'success')
            ->assertViewHas('canRetry', false);
        $this->assertSame('completed', $previous->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_retry_requires_finished_retryable_report_and_current_permission(): void
    {
        $media = $this->media();
        $media->shouldNotReceive('retry');
        $finished = $this->createRun();
        $active = $this->createRun(['status' => 'queued']);
        $active->items()->create(['identifier' => 'ACTIVE-SKIPPED', 'name' => 'Artikl', 'status' => 'skipped']);
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('selectRun', $finished->id)->call('retryReport')
            ->assertViewHas('canRetry', false)->assertDispatched('notify', type: 'warning');
        $component = Livewire::test(Dashboard::class)->call('selectRun', $active->id)->call('retryReport')
            ->assertViewHas('canRetry', false)->assertDispatched('notify', type: 'warning');
        $this->actingAs($this->admin(manage: false));
        $component->call('retryReport')->assertForbidden();
    }

    public function test_retry_failure_has_safe_generic_message_without_supplier_url(): void
    {
        $previous = $this->createRun(['status' => 'failed']);
        $previous->items()->create(['identifier' => 'FAILED', 'name' => 'Artikl', 'status' => 'failed']);
        $media = $this->media();
        $media->shouldReceive('retry')->once()->andThrow(new RuntimeException('https://secret-supplier.test/?token=private'));
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('selectRun', $previous->id)->call('retryReport')
            ->assertDispatched('notify', type: 'error', message: 'Ponovnu obradu slika nije moguće pokrenuti. Provjerite izvor i je li obrada već u tijeku.')
            ->assertSet('selectedRunId', $previous->id)->assertDontSee('secret-supplier');
    }

    private function media(bool $configured = true, int $candidateCount = 3): MockInterface
    {
        $mock = Mockery::mock(MediaImportService::class);
        $mock->shouldReceive('configured')->andReturn($configured);
        $mock->shouldReceive('candidates')->andReturn([
            'count' => $candidateCount,
            'products' => array_map(static fn (int $id): array => ['id' => $id, 'identifier' => sprintf('CANDIDATE-%03d', $id), 'name' => 'Artikl '.$id], $candidateCount > 0 ? range(1, min(100, $candidateCount)) : []),
            'limit' => 100,
        ]);
        $this->app->instance(MediaImportService::class, $mock);

        return $mock;
    }

    private function createRun(array $attributes = []): EracuniCatalogRun
    {
        return EracuniCatalogRun::query()->create(array_replace([
            'kind' => 'images', 'status' => 'completed', 'started_at' => now()->subMinute(), 'completed_at' => now(),
            'fetched_count' => 3, 'eligible_count' => 3, 'updated_count' => 2, 'unchanged_count' => 1, 'skipped_count' => 0,
            'summary' => ['images_added' => 3, 'duplicate_count' => 1, 'failed_count' => 0],
        ], $attributes));
    }

    private function admin(bool $manage = true): User
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'media-fixture-admin']);
        Bouncer::assign('media-fixture-admin')->to($user);
        Bouncer::allow($user)->to('admin.access');
        if ($manage) {
            Bouncer::allow($user)->to('integrations.media.manage');
        }

        return $user;
    }
}
