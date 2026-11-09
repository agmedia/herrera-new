<?php

namespace App\Livewire\Admin\Integrations\Stock;

use App\Jobs\Integrations\SyncCompanySalesForClearance;
use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\ProductClearance;
use App\Services\Catalog\Clearance\ClearanceService;
use App\Services\Catalog\Clearance\CompanyOutgoingImport;
use App\Services\Catalog\Clearance\CompanySalesClearanceSync;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Silber\Bouncer\BouncerFacade as Bouncer;

class Clearance extends Component
{
    use WithFileUploads;

    public array $settings = [];

    public $report;

    public bool $confirmedCompanyScope = false;

    #[Locked]
    public ?array $preview = null;

    #[Locked]
    public ?array $candidatePreview = null;

    public string $notice = '';

    public function mount(): void
    {
        $this->authorizeManage();
        $this->settings = app(ClearanceService::class)->settings();
    }

    public function updatedReport(): void
    {
        $this->preview = null;
        $this->confirmedCompanyScope = false;
    }

    public function saveSettings(): void
    {
        $this->authorizeManage();
        $this->action(function (): string {
            try {
                app(ClearanceService::class)->saveSettings($this->settings);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(collect($exception->errors())
                    ->mapWithKeys(fn ($messages, $field) => ['settings.'.$field => $messages])->all());
            }
            $this->settings = app(ClearanceService::class)->settings();
            $this->candidatePreview = null;

            return 'Postavke rasprodaje su spremljene.';
        });
    }

    public function updatedSettings(): void
    {
        $this->candidatePreview = null;
    }

    public function refreshCompanySales(): void
    {
        $this->authorizeManage();
        $this->action(function (): string {
            if (! app(\App\Services\Integrations\Stock\StockSyncSettingsService::class)->configured('eracuni')) {
                throw new \RuntimeException('Najprije provjerite postojeći e-Računi pristup u integracijama.');
            }
            SyncCompanySalesForClearance::dispatch(auth()->id());
            $this->candidatePreview = null;

            return 'Provjera ukupne prodaje i vlastite zalihe stavljena je u pozadinsku obradu. Osvježite stranicu nakon dovršetka; cijene se ovom provjerom ne mijenjaju.';
        });
    }

    public function previewReport(): void
    {
        $this->authorizeManage();
        $this->validate(['report' => 'required|file|max:20480']);
        $this->action(function (): string {
            $this->preview = app(CompanyOutgoingImport::class)->run($this->report->getRealPath());

            return 'Izvještaj je provjeren bez spremanja. Provjerite obuhvat ukupnih izlaza prije potvrde.';
        });
    }

    public function importReport(): void
    {
        $this->authorizeManage();
        $this->validate(['report' => 'required|file|max:20480', 'confirmedCompanyScope' => 'accepted']);
        abort_unless($this->preview !== null && hash_file('sha256', $this->report->getRealPath()) === $this->preview['fingerprint'], 422);
        $this->action(function (): string {
            $result = app(CompanyOutgoingImport::class)->run($this->report->getRealPath(), true, $this->confirmedCompanyScope, auth()->id());
            $this->preview = null;
            $this->report = null;

            return 'Spremljen potvrđeni ERP izvještaj: '.$result['products'].' artikala. Pregledajte kandidate prije prve obrade.';
        });
    }

    public function previewCandidates(): void
    {
        $this->authorizeManage();
        $this->action(function (): string {
            $this->candidatePreview = app(ClearanceService::class)->run();

            return 'Pregled kandidata je dovršen bez spremanja.';
        });
    }

    public function applyCandidates(): void
    {
        $this->authorizeManage();
        abort_unless($this->candidatePreview !== null, 422);
        $this->action(function (): string {
            $result = app(ClearanceService::class)->run(true);
            $this->candidatePreview = null;

            return 'Aktivirano rasprodaja: '.$result['activated'].'. Deaktivirano nakon prodaje vlastite zalihe: '.$result['retired'].'.';
        });
    }

    public function render()
    {
        $this->authorizeManage();

        return view('livewire.admin.integrations.stock.clearance', [
            'categories' => Category::query()->where('scope', 'catalog')->with('translations')->defaultOrder()->get(),
            'lastSnapshot' => ProductClearance::query()->where('confirmed_company_scope', true)->max('snapshot_date'),
            'counts' => ProductClearance::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'salesSync' => app(CompanySalesClearanceSync::class)->state(),
        ]);
    }

    private function action(callable $action): void
    {
        try {
            $this->notice = $action();
        } catch (\RuntimeException $exception) {
            $this->notice = get_class($exception) === \RuntimeException::class ? $exception->getMessage() : 'Obradu nije moguće dovršiti. Pokušajte ponovno.';
        }
    }

    private function authorizeManage(): void
    {
        $user = auth()->user();
        abort_unless($user && (Bouncer::is($user)->an('superadmin') || $user->can('integrations.stock.manage')), 403);
    }
}
