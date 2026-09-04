<?php

namespace App\Livewire\Admin\Integrations\Msan;

use App\Models\Integrations\Msan\MsanCategory;
use App\Models\Integrations\Msan\MsanCategoryMapping;
use App\Models\Integrations\Msan\MsanEndpointState;
use App\Models\Integrations\Msan\MsanProduct;
use App\Models\Integrations\Msan\MsanSpecificationDefinition;
use App\Models\Integrations\Msan\MsanSyncRun;
use App\Services\Integrations\Msan\MsanCatalogSyncCoordinator;
use App\Services\Integrations\Msan\MsanCertificateService;
use App\Services\Integrations\Msan\MsanSettingsService;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Throwable;

class Dashboard extends Component
{
    public const COUNTS_CACHE_KEY = 'integrations:msan:admin-dashboard-counts';

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function syncCatalog(MsanCatalogSyncCoordinator $coordinator): void
    {
        $this->authorizeSync();

        try {
            $coordinator->queueFullSync(auth()->id() ? (int) auth()->id() : null);
            $this->dispatch('notify', type: 'success', message: __('M SAN katalog stavljen je u red za sinkronizaciju.'));
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('notify', type: 'warning', message: __('Sinkronizaciju nije moguće pokrenuti: :message', ['message' => $exception->getMessage()]));
        }
    }

    public function testConnection(MsanCatalogSyncCoordinator $coordinator): void
    {
        $this->authorizeSync();

        try {
            $coordinator->queueConnectionTest(auth()->id() ? (int) auth()->id() : null);
            $this->dispatch('notify', type: 'success', message: __('Provjera M SAN veze stavljena je u red.'));
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('notify', type: 'error', message: __('Provjeru M SAN veze nije moguće pokrenuti.'));
        }
    }

    public function syncPricesAndStock(MsanCatalogSyncCoordinator $coordinator): void
    {
        $this->authorizeSync();

        try {
            $coordinator->queuePricesAndStock(auth()->id() ? (int) auth()->id() : null);
            $this->dispatch('notify', type: 'success', message: __('Osvježavanje M SAN cijena i količina stavljeno je u red.'));
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('notify', type: 'warning', message: __('Osvježavanje cijena i količina nije moguće pokrenuti. Provjerite postavke i trenutačna izvršavanja.'));
        }
    }

    public function syncSpecifications(MsanCatalogSyncCoordinator $coordinator): void
    {
        $this->authorizeSync();

        try {
            $coordinator->queueSpecifications(auth()->id() ? (int) auth()->id() : null);
            $this->dispatch('notify', type: 'success', message: __('Dohvat M SAN specifikacija stavljen je u red.'));
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('notify', type: 'warning', message: __('Dohvat specifikacija nije moguće pokrenuti. Provjerite postavke, odabir artikala i trenutačna izvršavanja.'));
        }
    }

    public function syncEprel(MsanCatalogSyncCoordinator $coordinator): void
    {
        $this->authorizeSync();

        try {
            $coordinator->queueEprelEnergy(auth()->id() ? (int) auth()->id() : null);
            $this->dispatch('notify', type: 'success', message: __('Osvježavanje EPREL energetskih podataka stavljeno je u red.'));
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('notify', type: 'warning', message: __('EPREL obradu nije moguće pokrenuti. Provjerite API ključ, mapiranje i trenutačna izvršavanja.'));
        }
    }

    public function render(MsanSettingsService $settings, MsanCertificateService $certificates)
    {
        $this->authorizeView();
        $certificateValid = false;
        try {
            $certificateValid = $certificates->currentMetadata() !== null
                && $certificates->caAbsolutePath() !== null;
        } catch (Throwable) {
            // The dashboard stays available and reports the integration as not ready.
        }
        $ready = $settings->enabled() && $certificateValid;
        $latestRun = MsanSyncRun::query()->latest('id')->first();
        $counts = Cache::remember(self::COUNTS_CACHE_KEY, now()->addSeconds(30), static fn (): array => [
            'products' => MsanProduct::query()->where('is_stale', false)->count(),
            'selected' => MsanProduct::query()->where('selected', true)->where('is_stale', false)->count(),
            'imported' => MsanProduct::query()->whereNotNull('local_product_id')->count(),
            'categories' => MsanCategory::query()->where('is_stale', false)->count(),
            'unmapped' => MsanCategory::query()
                ->where('is_stale', false)
                ->where(function ($query): void {
                    $query
                        ->whereDoesntHave('mapping')
                        ->orWhereHas('mapping', fn ($mapping) => $mapping
                            ->where('status', MsanCategoryMapping::STATUS_UNMAPPED)
                            ->orWhere(fn ($invalid) => $invalid
                                ->where('status', MsanCategoryMapping::STATUS_MAPPED)
                                ->whereNull('local_category_id')));
                })
                ->count(),
            'specifications' => MsanSpecificationDefinition::query()->where('is_stale', false)->count(),
            'specification_targets' => MsanProduct::query()
                ->where('is_stale', false)
                ->where(fn ($query) => $query->where('selected', true)->orWhereNotNull('local_product_id'))
                ->count(),
        ]);

        $specificationsReady = $ready
            && $settings->importSpecifications()
            && $counts['specification_targets'] > 0;
        $eprelReady = $ready
            && $settings->eprelEnabled()
            && $settings->hasEprelApiKey();

        return view('livewire.admin.integrations.msan.dashboard', [
            'ready' => $ready,
            'enabled' => $settings->enabled(),
            'specificationsEnabled' => $settings->importSpecifications(),
            'specificationsReady' => $specificationsReady,
            'eprelEnabled' => $settings->eprelEnabled(),
            'eprelReady' => $eprelReady,
            'canSync' => $this->canSync(),
            'counts' => $counts,
            'latestRun' => $latestRun,
            'pollFrequently' => $latestRun !== null && in_array(
                $latestRun->status,
                [MsanSyncRun::STATUS_PENDING, MsanSyncRun::STATUS_RUNNING],
                true,
            ),
            'endpointStates' => MsanEndpointState::query()->orderBy('endpoint')->get(),
            'endpointLabels' => [
                'categories' => __('Kategorije'),
                'catalog' => __('Katalog artikala'),
                'prices' => __('Cijene'),
                'availability' => __('Raspoloživost'),
                'specifications' => __('Tehničke specifikacije'),
                'product_categories' => __('Veze artikala i kategorija'),
                'barcodes' => __('Barkodovi'),
                'product_image' => __('Slike artikala'),
            ],
        ]);
    }

    private function authorizeView(): void
    {
        abort_unless($this->canView(), 403);
    }

    private function authorizeSync(): void
    {
        abort_unless($this->canSync(), 403);
    }

    private function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.msan.view')));
    }

    private function canSync(): bool
    {
        $user = auth()->user();

        return (bool) ($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.msan.sync.run')));
    }
}
