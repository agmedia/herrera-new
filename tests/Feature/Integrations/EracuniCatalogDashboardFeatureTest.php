<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Admin\Integrations\Eracuni\Dashboard;
use App\Models\Integrations\Eracuni\EracuniCatalogRun;
use App\Models\User;
use App\Services\Integrations\Eracuni\EracuniCatalogService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class EracuniCatalogDashboardFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->settings();
    }

    public function test_authorized_admin_has_erp_page_and_draft_import_explanation(): void
    {
        $this->actingAs($this->admin())->get(route('admin.integrations.eracuni.index'))
            ->assertOk()->assertSee('e-Računi katalog')->assertSee('Pregledaj nove artikle')
            ->assertSee('Osvježi svojstva postojećih')->assertSee('Uvoz kreira neaktivne nacrte.')
            ->assertSee(route('admin.integrations.eracuni.index'), false);
        Http::assertNothingSent();
    }

    public function test_guest_and_admin_without_catalog_permission_cannot_open_page_or_mount_component(): void
    {
        $this->get(route('admin.integrations.eracuni.index'))->assertRedirect();
        $this->actingAs($this->admin(manage: false))->get(route('admin.integrations.eracuni.index'))->assertForbidden();
        Livewire::test(Dashboard::class)->assertForbidden();
        $this->get(route('admin.help.index'))->assertOk()->assertDontSee(route('admin.integrations.eracuni.index'), false);
    }

    public function test_preview_shows_planned_identity_and_price_validation_without_exposing_raw_supplier_payload(): void
    {
        $admin = $this->admin();
        $run = $this->createRun(['eligible_count' => 1]);
        $item = $this->createItem($run, ['source_payload' => ['password' => 'private-erp-payload'], 'plan' => [
            'name' => 'Novi ERP artikl', 'sku' => 'ERP-123', 'model' => 'ERP-123', 'barcode' => '0123456789012',
            'gross_price' => '12.3400', 'price_state' => 'review', 'attributes' => [['label' => 'ERP boja', 'value' => 'Zelena']],
            'notes' => ['Prije objave odaberite porez.'],
        ]]);
        $this->createItem($run, ['identifier' => 'EXISTING', 'name' => 'Postojeći ERP artikl', 'status' => 'existing']);
        $this->mock(EracuniCatalogService::class, function (MockInterface $mock) use ($admin, $run): void {
            $mock->shouldReceive('preview')->once()->with($admin->id)->andReturn($run);
            $mock->shouldNotReceive('import');
        });

        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->call('previewCatalog')
            ->assertSet('previewRunId', $run->id)->assertSet('selectedIds', [])
            ->assertSee('Novi ERP artikl')->assertSee('ERP-123')->assertSee('0123456789012')->assertSee('12.3400')
            ->assertSee('ERP boja')->assertSee('Zelena')
            ->assertSee('Cijenu i porez treba provjeriti.')
            ->assertDontSee('private-erp-payload')->assertDontSee('Postojeći ERP artikl')
            ->assertViewHas('candidates', static fn ($candidates): bool => $candidates->total() === 1 && $candidates->first()->is($item));
        Http::assertNothingSent();
    }

    public function test_only_explicitly_selected_new_items_from_current_preview_are_sent_for_import(): void
    {
        $admin = $this->admin();
        $preview = $this->createRun();
        $selected = $this->createItem($preview);
        $this->createItem($preview, ['identifier' => 'UNSELECTED', 'name' => 'Neodabrani artikl']);
        $import = $this->createRun(['kind' => 'import', 'created_count' => 1]);
        $this->mock(EracuniCatalogService::class, function (MockInterface $mock) use ($admin, $preview, $selected, $import): void {
            $mock->shouldReceive('import')->once()->with($preview->id, [$selected->id], $admin->id)->andReturn($import);
        });

        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->call('selectRun', $preview->id)
            ->set('selectedIds', [(string) $selected->id])->call('importSelected')
            ->assertHasNoErrors()->assertSet('selectedIds', [])->assertSet('selectedRunId', $import->id)
            ->assertDispatched('notify', type: 'success');
        Http::assertNothingSent();
    }

    public function test_foreign_existing_and_empty_selections_are_rejected_without_import(): void
    {
        $preview = $this->createRun();
        $existing = $this->createItem($preview, ['status' => 'existing']);
        $foreign = $this->createItem($this->createRun());
        $this->mock(EracuniCatalogService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('import'));
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('selectRun', $preview->id)
            ->call('importSelected')->assertHasErrors('selectedIds')
            ->set('selectedIds', [$foreign->id])->call('importSelected')->assertHasErrors('selectedIds')
            ->set('selectedIds', [$existing->id])->call('importSelected')->assertHasErrors('selectedIds');
        Http::assertNothingSent();
    }

    public function test_attributes_action_opens_its_report_and_does_not_request_new_product_import(): void
    {
        $admin = $this->admin();
        $run = $this->createRun(['kind' => 'attributes', 'updated_count' => 1]);
        $this->createItem($run, ['identifier' => 'ATTR-123', 'status' => 'updated', 'message' => 'Svojstva su osvježena.']);
        $this->mock(EracuniCatalogService::class, function (MockInterface $mock) use ($admin, $run): void {
            $mock->shouldReceive('syncAttributes')->once()->with($admin->id)->andReturn($run);
            $mock->shouldNotReceive('import');
        });
        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->call('syncAttributes')
            ->assertSet('selectedRunId', $run->id)->assertSet('previewRunId', null)
            ->assertSee('ATTR-123')->assertSee('Svojstva su osvježena.');
    }

    #[DataProvider('updateOperations')]
    public function test_price_and_name_changes_require_preview_and_apply_only_selected_reviewed_items(string $operation, array $changes, string $before, string $after): void
    {
        $admin = $this->admin();
        $preview = $this->createRun(['kind' => 'preview_'.$operation, 'eligible_count' => 2]);
        $selected = $this->createItem($preview, ['status' => 'update', 'plan' => $changes + ['attributes' => [], 'price_state' => 'mapped']]);
        $this->createItem($preview, ['identifier' => 'UNSELECTED', 'status' => 'update']);
        $result = $this->createRun(['kind' => $operation, 'updated_count' => 1]);
        $this->mock(EracuniCatalogService::class, function (MockInterface $mock) use ($admin, $preview, $operation, $selected, $result): void {
            $mock->shouldReceive('previewUpdates')->once()->with($operation, $admin->id)->andReturn($preview);
            $mock->shouldReceive('applyUpdates')->once()->with($preview->id, [$selected->id], $admin->id)->andReturn($result);
            $mock->shouldNotReceive('import');
        });
        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->call('previewUpdates', $operation)
            ->assertSet('previewRunId', $preview->id)->assertSet('selectedIds', [])
            ->assertSee($before)->assertSee($after)->assertSee('Primijeni odabrane promjene')
            ->set('selectedIds', [$selected->id])->call('applySelectedUpdates')
            ->assertHasNoErrors()->assertSet('selectedRunId', $result->id)->assertSet('selectedIds', []);
    }

    public static function updateOperations(): array
    {
        return [
            'prices' => ['prices', ['old_base_price' => '10.0000', 'base_price' => '11.2300', 'gross_price' => '14.0375'], '10.0000', '11.2300'],
            'names' => ['names', ['old_name' => 'Stari naziv', 'name' => 'Novi naziv'], 'Stari naziv', 'Novi naziv'],
        ];
    }

    public function test_draft_import_cannot_apply_update_candidates_and_price_update_cannot_import_drafts(): void
    {
        $draftPreview = $this->createRun();
        $draft = $this->createItem($draftPreview);
        $pricePreview = $this->createRun(['kind' => 'preview_prices']);
        $price = $this->createItem($pricePreview, ['status' => 'update']);
        $this->mock(EracuniCatalogService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('import');
            $mock->shouldNotReceive('applyUpdates');
        });
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('selectRun', $draftPreview->id)->set('selectedIds', [$draft->id])
            ->call('applySelectedUpdates')->assertHasErrors('selectedIds')
            ->call('selectRun', $pricePreview->id)->set('selectedIds', [$price->id])
            ->call('importSelected')->assertHasErrors('selectedIds');
    }

    public function test_erp_range_is_passed_to_preview_and_changing_it_clears_reviewed_selection(): void
    {
        $admin = $this->admin();
        $run = $this->createRun(['summary' => ['code_from' => '10001', 'code_to' => '20000']]);
        $item = $this->createItem($run);
        $this->mock(EracuniCatalogService::class, function (MockInterface $mock) use ($admin, $run): void {
            $mock->shouldReceive('preview')->once()->with($admin->id, '10001', '20000')->andReturn($run);
        });
        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->set('rangePreset', 'second')
            ->assertSet('codeFrom', '10001')->assertSet('codeTo', '20000')
            ->call('previewCatalog')->assertSet('previewRunId', $run->id)
            ->set('selectedIds', [$item->id])->set('codeTo', '15000')
            ->assertSet('rangePreset', 'custom')->assertSet('previewRunId', null)->assertSet('selectedIds', []);
    }

    public function test_capped_erp_preview_blocks_draft_import_and_update_application(): void
    {
        $draftPreview = $this->createRun(['summary' => ['limit_reached' => true]]);
        $draft = $this->createItem($draftPreview);
        $updatePreview = $this->createRun(['kind' => 'preview_prices', 'summary' => ['limit_reached' => true]]);
        $update = $this->createItem($updatePreview, ['status' => 'update']);
        $this->mock(EracuniCatalogService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('import');
            $mock->shouldNotReceive('applyUpdates');
        });
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('selectRun', $draftPreview->id)
            ->assertSee('Uvoz i primjena ovog pregleda su blokirani.')
            ->set('selectedIds', [$draft->id])->call('importSelected')->assertHasErrors('selectedIds')
            ->call('selectRun', $updatePreview->id)->set('selectedIds', [$update->id])
            ->call('applySelectedUpdates')->assertHasErrors('selectedIds');
    }

    public function test_ideus_csv_can_be_reviewed_and_imported_as_drafts_without_erp_credentials(): void
    {
        $this->settings(configured: false);
        $admin = $this->admin();
        $preview = $this->createRun(['kind' => 'preview_csv']);
        $item = $this->createItem($preview, ['plan' => ['name' => 'CSV artikl', 'model' => 'CSV-1', 'sku' => 'CSV-1', 'brand' => 'Struhm', 'raw_price' => '15.25', 'price_state' => 'review', 'attributes' => []]]);
        $import = $this->createRun(['kind' => 'import', 'created_count' => 1]);
        $this->mock(EracuniCatalogService::class, function (MockInterface $mock) use ($admin, $preview, $item, $import): void {
            $mock->shouldReceive('previewSupplierCsv')->once()->with(Mockery::on(static fn (string $path): bool => is_file($path)), $admin->id)->andReturn($preview);
            $mock->shouldReceive('import')->once()->with($preview->id, [$item->id], $admin->id)->andReturn($import);
            $mock->shouldNotReceive('preview');
        });
        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->set('supplierUpload', UploadedFile::fake()->createWithContent('ideus.csv', "Marka;Kod\nStruhm;CSV-1\n"))
            ->call('previewSupplierCsv')->assertHasNoErrors()->assertSet('previewRunId', $preview->id)
            ->assertSee('CSV artikl')->assertSee('Struhm')->assertSee('15.25')
            ->set('selectedIds', [$item->id])->call('importSelected')->assertHasNoErrors()
            ->assertSet('selectedRunId', $import->id)->assertSet('selectedIds', []);
    }

    public function test_attributes_cron_toggle_checks_permission_and_url_uses_selected_range(): void
    {
        $settings = $this->settings();
        $settings->shouldReceive('saveAttributesEnabled')->once()->with(true);
        $this->actingAs($this->admin());
        $component = Livewire::test(Dashboard::class)->set('rangePreset', 'second')
            ->assertViewHas('attributesCronUrl', static fn (string $url): bool => str_contains($url, 'code_from=10001') && str_contains($url, 'code_to=20000'))
            ->call('toggleAttributesCron', true)->assertDispatched('notify', type: 'success');
        $this->actingAs($this->admin(manage: false));
        $component->call('toggleAttributesCron', false)->assertForbidden();
    }

    public function test_attributes_cron_last_attempt_and_success_exclude_manual_runs(): void
    {
        $successful = $this->createRun(['kind' => 'attributes', 'summary' => ['trigger' => 'cron']]);
        $failed = $this->createRun(['kind' => 'attributes', 'status' => 'failed', 'summary' => ['trigger' => 'cron']]);
        $this->createRun(['kind' => 'attributes', 'summary' => ['trigger' => 'manual']]);
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)
            ->assertViewHas('lastAttributesCronAttempt', static fn ($run): bool => $run->is($failed))
            ->assertViewHas('lastAttributesCronSuccess', static fn ($run): bool => $run->is($successful));
    }

    public function test_catalog_history_cannot_expose_or_select_media_runs(): void
    {
        $catalog = $this->createRun(['kind' => 'import_csv']);
        $media = $this->createRun(['kind' => 'images']);
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->assertViewHas('runs', static fn ($runs): bool => $runs->total() === 1 && $runs->first()->is($catalog))
            ->call('selectRun', $media->id)->assertNotFound();
    }

    public function test_unconfigured_connection_and_revoked_permission_block_actions(): void
    {
        $this->settings(configured: false);
        $this->mock(EracuniCatalogService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('preview');
            $mock->shouldNotReceive('syncAttributes');
            $mock->shouldNotReceive('import');
        });
        $this->actingAs($this->admin());
        $component = Livewire::test(Dashboard::class)->call('previewCatalog')->assertDispatched('notify', type: 'warning')
            ->call('syncAttributes')->assertDispatched('notify', type: 'warning')
            ->call('importSelected')->assertDispatched('notify', type: 'warning');
        $this->actingAs($this->admin(manage: false));
        $component->call('syncAttributes')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_history_filters_and_paginated_report_select_the_expected_run(): void
    {
        $selected = $this->createRun(['kind' => 'attributes']);
        $this->createRun(['kind' => 'preview']);
        $this->createRun(['kind' => 'attributes', 'status' => 'failed']);
        for ($i = 1; $i <= 28; $i++) {
            $this->createItem($selected, ['identifier' => sprintf('REPORT-%03d', $i), 'status' => 'updated']);
        }
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->set('kindFilter', 'attributes')->set('statusFilter', 'completed')
            ->assertViewHas('runs', static fn ($runs): bool => $runs->total() === 1 && $runs->first()->is($selected))
            ->call('selectRun', $selected->id)->assertSee('REPORT-001')->assertDontSee('REPORT-026')
            ->call('gotoPage', 2, 'eracuniItemsPage')->assertSee('REPORT-026')->assertSet('selectedRunId', $selected->id)
            ->call('closeReport')->assertSet('selectedRunId', null);
    }

    public function test_service_exception_has_generic_notification_without_connection_details(): void
    {
        $this->mock(EracuniCatalogService::class, fn (MockInterface $mock) => $mock->shouldReceive('preview')->once()->andThrow(new RuntimeException('private-erp-password')));
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('previewCatalog')
            ->assertDispatched('notify', type: 'error', message: 'ERP obradu nije moguće pokrenuti. Provjerite vezu i je li obrada već u tijeku.')
            ->assertDontSee('private-erp-password');
    }

    private function settings(bool $configured = true): MockInterface
    {
        $mock = Mockery::mock(StockSyncSettingsService::class);
        $mock->shouldReceive('configured')->with('eracuni')->andReturn($configured);
        $mock->shouldReceive('attributesEnabled')->andReturn(false);
        $mock->shouldReceive('attributesCronUrl')->andReturnUsing(static fn (?string $from = null, ?string $to = null): string => 'https://herrera.test/attrs?'.http_build_query(array_filter(['token' => 'fixture-attributes-token', 'code_from' => $from, 'code_to' => $to], static fn ($value): bool => $value !== null)));
        $this->app->instance(StockSyncSettingsService::class, $mock);

        return $mock;
    }

    private function createRun(array $attributes = []): EracuniCatalogRun
    {
        return EracuniCatalogRun::query()->create(array_replace([
            'kind' => 'preview', 'status' => 'completed', 'started_at' => now()->subMinute(), 'completed_at' => now(),
            'fetched_count' => 2, 'eligible_count' => 0, 'created_count' => 0,
            'updated_count' => 0, 'unchanged_count' => 0, 'skipped_count' => 0, 'summary' => [],
        ], $attributes));
    }

    private function createItem(EracuniCatalogRun $run, array $attributes = [])
    {
        return $run->items()->create(array_replace([
            'identifier' => 'ERP-123', 'name' => 'Novi ERP artikl', 'status' => 'new', 'plan' => ['attributes' => []],
        ], $attributes));
    }

    private function admin(bool $manage = true): User
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'eracuni-fixture-admin']);
        Bouncer::assign('eracuni-fixture-admin')->to($user);
        Bouncer::allow($user)->to('admin.access');
        if ($manage) {
            Bouncer::allow($user)->to('integrations.eracuni.manage');
        }

        return $user;
    }
}
