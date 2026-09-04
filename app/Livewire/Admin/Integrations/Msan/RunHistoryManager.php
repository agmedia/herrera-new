<?php

namespace App\Livewire\Admin\Integrations\Msan;

use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Session;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;

class RunHistoryManager extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'msanRunsPage';

    #[Session(key: 'admin.msan.runs.kind')]
    public string $kind = 'all';

    #[Session(key: 'admin.msan.runs.status')]
    public string $status = 'all';

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function updatedKind(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function updatedStatus(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function clearFilters(): void
    {
        $this->kind = 'all';
        $this->status = 'all';
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function render()
    {
        $this->authorizeView();

        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200,
        );

        $runs = MsanSyncRun::query()
            ->leftJoin('users as requested_users', 'requested_users.id', '=', 'msan_sync_runs.requested_by')
            ->select([
                'msan_sync_runs.*',
                'requested_users.name as requested_by_name',
                'requested_users.email as requested_by_email',
            ])
            ->when($this->kind !== 'all' && $this->kind !== '', fn (Builder $query) => $query->where('msan_sync_runs.kind', $this->kind))
            ->when($this->status !== 'all' && $this->status !== '', fn (Builder $query) => $query->where('msan_sync_runs.status', $this->status))
            ->orderByDesc('msan_sync_runs.id')
            ->paginate($perPage, pageName: self::PAGE_NAME);

        return view('livewire.admin.integrations.msan.run-history-manager', [
            'runs' => $runs,
            'kinds' => $this->distinctValues('kind', fn (string $value): string => $this->kindLabel($value)),
            'statuses' => $this->distinctValues('status', fn (string $value): string => $this->statusLabel($value)),
            'perPage' => $perPage,
            'pollFrequently' => MsanSyncRun::query()
                ->whereIn('status', [MsanSyncRun::STATUS_PENDING, MsanSyncRun::STATUS_RUNNING])
                ->exists(),
        ]);
    }

    /** @return array<int, array{value:string,label:string}> */
    private function distinctValues(string $column, callable $labelResolver): array
    {
        return MsanSyncRun::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(static fn ($value): array => [
                'value' => (string) $value,
                'label' => $labelResolver((string) $value),
            ])
            ->all();
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            MsanSyncRun::KIND_CATALOG => __('Katalog'),
            MsanSyncRun::KIND_PRICES => __('Cijene i količine'),
            MsanSyncRun::KIND_AVAILABILITY => __('Raspoloživost'),
            MsanSyncRun::KIND_CATEGORIES => __('Kategorije'),
            MsanSyncRun::KIND_FULL => __('Puna sinkronizacija'),
            MsanSyncRun::KIND_IMPORT => __('Uvoz'),
            MsanSyncRun::KIND_SPECIFICATIONS => __('Tehničke specifikacije'),
            MsanSyncRun::KIND_EPREL => __('EPREL energetski podaci'),
            'connection_test' => __('Provjera B2B veze'),
            'ftp_connection_test' => __('Provjera FTPS veze'),
            default => $kind,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            MsanSyncRun::STATUS_PENDING => __('Na čekanju'),
            MsanSyncRun::STATUS_RUNNING => __('U tijeku'),
            MsanSyncRun::STATUS_COMPLETED => __('Završeno'),
            MsanSyncRun::STATUS_FAILED => __('Neuspješno'),
            MsanSyncRun::STATUS_CANCELLED => __('Otkazano'),
            default => $status,
        };
    }

    private function authorizeView(): void
    {
        $user = auth()->user();

        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.msan.view')),
            403,
        );
    }
}
