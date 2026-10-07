<?php

namespace Tests\Feature\Integrations;

use App\Livewire\Admin\Integrations\Spreadsheet\Dashboard;
use App\Models\Integrations\Spreadsheet\SpreadsheetImportRun;
use App\Models\User;
use App\Services\Integrations\Spreadsheet\SpreadsheetImportService;
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

class SpreadsheetImportDashboardFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_authorized_admin_sees_all_mapping_controls_and_direct_store_price_explanation(): void
    {
        $this->actingAs($this->admin())->get(route('admin.integrations.spreadsheet.index'))
            ->assertOk()->assertSee('Excel i CSV uvoz')->assertSee('Stupac oznake artikla')
            ->assertSee('ID artikla na staroj stranici')->assertSee('Osvježi vlastitu zalihu')
            ->assertSee('Osvježi zalihu dobavljača')->assertSee('Uvoz ne preračunava PDV.')
            ->assertSee(route('admin.integrations.spreadsheet.index'), false);
        Http::assertNothingSent();
    }

    public function test_guest_and_admin_without_import_permission_cannot_access_page_or_component(): void
    {
        $this->get(route('admin.integrations.spreadsheet.index'))->assertRedirect();
        $this->actingAs($this->admin(manage: false))->get(route('admin.integrations.spreadsheet.index'))->assertForbidden();
        Livewire::test(Dashboard::class)->assertForbidden();
        $this->get(route('admin.help.index'))->assertOk()->assertDontSee(route('admin.integrations.spreadsheet.index'), false);
    }

    public function test_uploaded_csv_is_previewed_with_only_whitelisted_options_and_explicit_old_new_values(): void
    {
        $admin = $this->admin();
        $run = $this->createRun();
        $run->items()->create(['row_number' => 2, 'identifier' => 'ERP-123', 'status' => 'ready', 'old_values' => ['supplier_stock_qty' => 2], 'new_values' => ['supplier_stock_qty' => 5]]);
        $this->mock(SpreadsheetImportService::class, function (MockInterface $mock) use ($admin, $run): void {
            $mock->shouldReceive('preview')->once()->with(
                Mockery::on(static fn (string $path): bool => is_file($path)),
                Mockery::on(static fn (array $options): bool => $options['identifier_type'] === 'sku'
                    && $options['first_row'] === 2 && $options['update_supplier_stock'] === true
                    && ! array_key_exists('unrelated_setting', $options)),
                $admin->id,
            )->andReturn($run);
            $mock->shouldNotReceive('apply');
        });
        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->set('upload', UploadedFile::fake()->createWithContent('stock.csv', "sku;stock\nERP-123;5\n"))
            ->set('options.identifier_type', 'sku')->set('options.unrelated_setting', 'ignore')
            ->call('previewFile')->assertHasNoErrors()->assertSet('previewRunId', $run->id)
            ->assertSee('ERP-123')->assertSee('2 → 5')->assertViewHas('canApply', true);
        Http::assertNothingSent();
    }

    public function test_apply_requires_active_review_and_passes_only_locked_run_identity(): void
    {
        $admin = $this->admin();
        $run = $this->createRun();
        $this->mock(SpreadsheetImportService::class, function (MockInterface $mock) use ($admin, $run): void {
            $mock->shouldReceive('apply')->once()->with($run->id, $admin->id)->andReturnUsing(function () use ($run) {
                $run->update(['kind' => 'import', 'status' => 'completed']);

                return $run->fresh();
            });
        });
        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->call('selectRun', $run->id)->assertViewHas('canApply', true)
            ->call('applyPreview')->assertHasNoErrors()->assertSet('previewRunId', null)
            ->assertSet('selectedRunId', $run->id)->assertDispatched('notify', type: 'success');
    }

    #[DataProvider('blockedRuns')]
    public function test_invalid_conflicting_empty_or_finished_runs_cannot_be_applied(array $attributes): void
    {
        $run = $this->createRun($attributes);
        $this->mock(SpreadsheetImportService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('apply'));
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('selectRun', $run->id)->assertViewHas('canApply', false)
            ->call('applyPreview')->assertHasErrors('preview');
    }

    public static function blockedRuns(): array
    {
        return [
            'invalid' => [['invalid_count' => 1]],
            'conflict' => [['conflict_count' => 1]],
            'empty' => [['total_count' => 0]],
            'completed' => [['status' => 'completed', 'kind' => 'import']],
        ];
    }

    public function test_mapping_change_invalidates_the_active_preview_and_requires_fresh_review(): void
    {
        $run = $this->createRun();
        $this->mock(SpreadsheetImportService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('apply'));
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('selectRun', $run->id)->assertSet('previewRunId', $run->id)
            ->set('options.identifier_column', 3)->assertSet('previewRunId', null)->assertViewHas('canApply', false)
            ->call('applyPreview')->assertHasErrors('preview');
    }

    public function test_missing_file_wrong_extension_and_invalid_mapping_are_rejected_before_service_call(): void
    {
        $this->mock(SpreadsheetImportService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('preview'));
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('previewFile')->assertHasErrors('upload')
            ->set('upload', UploadedFile::fake()->createWithContent('stock.txt', "sku;stock\nERP-123;5\n"))
            ->call('previewFile')->assertHasErrors('upload')
            ->set('upload', UploadedFile::fake()->createWithContent('stock.csv', "sku;stock\nERP-123;5\n"))
            ->set('options.identifier_column', 0)->call('previewFile')->assertHasErrors('options.identifier_column');
    }

    public function test_disabled_processing_or_no_selected_fields_cannot_create_preview(): void
    {
        $this->mock(SpreadsheetImportService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('preview'));
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->set('upload', UploadedFile::fake()->createWithContent('stock.csv', "sku;stock\nERP-123;5\n"))
            ->set('options.enabled', false)->call('previewFile')->assertHasErrors('options.enabled')
            ->set('options.enabled', true)->set('options.update_supplier_stock', false)
            ->call('previewFile')->assertHasErrors('options.update_prices');
    }

    public function test_actions_recheck_permission_after_preview_was_opened(): void
    {
        $run = $this->createRun();
        $this->mock(SpreadsheetImportService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('apply'));
        $this->actingAs($this->admin());
        $component = Livewire::test(Dashboard::class)->call('selectRun', $run->id);
        $this->actingAs($this->admin(manage: false));
        $component->call('applyPreview')->assertForbidden();
    }

    public function test_history_and_row_filters_preserve_selected_report_across_pagination(): void
    {
        $selected = $this->createRun(['kind' => 'import', 'status' => 'completed']);
        $this->createRun(['status' => 'failed']);
        for ($i = 1; $i <= 28; $i++) {
            $selected->items()->create(['row_number' => $i, 'identifier' => sprintf('ROW-%03d', $i), 'status' => $i === 28 ? 'unmatched' : 'applied']);
        }
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->set('statusFilter', 'completed')
            ->assertViewHas('runs', static fn ($runs): bool => $runs->total() === 1 && $runs->first()->is($selected))
            ->call('selectRun', $selected->id)->assertSee('ROW-001')->assertDontSee('ROW-026')
            ->call('gotoPage', 2, 'spreadsheetItemsPage')->assertSee('ROW-026')->assertSet('selectedRunId', $selected->id)
            ->set('itemFilter', 'unmatched')->assertSee('ROW-028')->assertDontSee('ROW-001')
            ->call('closeReport')->assertSet('selectedRunId', null);
    }

    public function test_service_failure_has_generic_message_without_internal_file_paths(): void
    {
        $run = $this->createRun();
        $this->mock(SpreadsheetImportService::class, fn (MockInterface $mock) => $mock->shouldReceive('apply')->once()->andThrow(new RuntimeException('/private/internal/import-path')));
        $this->actingAs($this->admin());
        Livewire::test(Dashboard::class)->call('selectRun', $run->id)->call('applyPreview')
            ->assertDispatched('notify', type: 'error', message: 'Promjene nije moguće primijeniti. Provjerite izvještaj i pripremite novi pregled.')
            ->assertDontSee('/private/internal/import-path');
    }

    private function createRun(array $attributes = []): SpreadsheetImportRun
    {
        return SpreadsheetImportRun::query()->create(array_replace([
            'kind' => 'preview', 'status' => 'preview', 'options' => SpreadsheetImportService::defaultOptions(),
            'started_at' => now()->subMinute(), 'completed_at' => now(), 'total_count' => 1,
            'updated_count' => 1, 'unchanged_count' => 0, 'invalid_count' => 0, 'unmatched_count' => 0, 'conflict_count' => 0,
        ], $attributes));
    }

    private function admin(bool $manage = true): User
    {
        $user = User::factory()->create();
        Bouncer::role()->firstOrCreate(['name' => 'spreadsheet-fixture-admin']);
        Bouncer::assign('spreadsheet-fixture-admin')->to($user);
        Bouncer::allow($user)->to('admin.access');
        if ($manage) {
            Bouncer::allow($user)->to('integrations.spreadsheet.manage');
        }

        return $user;
    }
}
