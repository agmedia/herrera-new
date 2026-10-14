<?php

namespace App\Livewire\Admin\User;

use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\CustomerSalesSession;
use App\Models\User\CustomerVisit;
use App\Services\Admin\OrderManagerAccess;
use App\Services\User\CustomerCrmService;
use App\Services\User\CustomerManagementService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class CustomerCrmManager extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $customerId = null;

    public string $representative = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $outcome = '';

    public string $source = 'visits';

    public bool $includePreviews = false;

    public array $visit = [];

    public function mount(?int $customerId = null): void
    {
        $this->customerId = $customerId;
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
        $this->authorizeView();
        $this->resetVisit();
    }

    public function updated($property): void
    {
        if (in_array($property, ['representative', 'dateFrom', 'dateTo', 'outcome', 'source', 'includePreviews'], true)) {
            $this->resetPage(pageName: 'crmPage');
        }
    }

    public function saveVisit(CustomerCrmService $service): void
    {
        $this->authorizeView();
        abort_unless($this->customerId, 422);
        $this->validate(['visit' => ['array']]);
        try {
            $service->recordVisit(User::query()->findOrFail($this->customerId), $this->visit, auth()->user());
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($errors, $key) => ['visit.'.$key => $errors])->all());
        }
        $this->resetVisit();
        $this->dispatch('notify', type: 'success', message: __('Kontakt s kupcem je spremljen.'));
    }

    public function render()
    {
        $this->authorizeView();
        $this->validate([
            'dateFrom' => ['nullable', 'date'], 'dateTo' => ['nullable', 'date', ...($this->dateFrom !== '' ? ['after_or_equal:dateFrom'] : [])],
            'outcome' => ['nullable', 'in:order,quote,nothing,open,unknown'], 'source' => ['required', 'in:visits,sessions'],
        ]);
        $query = $this->source === 'sessions' ? CustomerSalesSession::query() : CustomerVisit::query();
        $dateColumn = $this->source === 'sessions' ? 'started_at' : 'visited_at';
        $query->when(! $this->includePreviews, fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereColumn('performed_by_id', 'sales_rep_id')->orWhereNull('performed_by_id')))
            ->whereHas('customer', fn (Builder $query) => app(OrderManagerAccess::class)->scopeCustomers($query))
            ->when(app(OrderManagerAccess::class)->isRestricted(), fn (Builder $query) => $query->where('sales_rep_id', auth()->id()))
            ->when($this->customerId, fn (Builder $query) => $query->where('customer_user_id', $this->customerId))
            ->when($this->representative !== '', fn (Builder $query) => $query->where('sales_rep_id', (int) $this->representative))
            ->when($this->dateFrom !== '', fn (Builder $query) => $query->where($dateColumn, '>=', \Carbon\Carbon::parse($this->dateFrom)->startOfDay()))
            ->when($this->dateTo !== '', fn (Builder $query) => $query->where($dateColumn, '<=', \Carbon\Carbon::parse($this->dateTo)->endOfDay()));
        $summary = (clone $query)->selectRaw('sales_rep_id, outcome, COUNT(*) as total')->groupBy('sales_rep_id', 'outcome')->get();
        $rows = $query->with(['customer.b2bAccount', 'customer.profile', 'customer.addresses', 'salesRep', 'performedBy', 'order'])
            ->when($this->outcome !== '', fn (Builder $query) => $query->where('outcome', $this->outcome))->orderByDesc($dateColumn)->orderByDesc('id')->paginate(20, ['*'], 'crmPage');
        $representatives = app(CustomerManagementService::class)->representatives()
            ->when(app(OrderManagerAccess::class)->isRestricted(), fn (Builder $query) => $query->whereKey(auth()->id()))->orderBy('name')->get();

        return view('livewire.admin.user.customer-crm-manager', [
            'rows' => $rows, 'summary' => $summary, 'representatives' => $representatives,
            'canUpdate' => app(CustomerManagementService::class)->canRecordContact(),
            'orders' => $this->customerId ? Order::query()->where('user_id', $this->customerId)->orderByDesc('id')->limit(100)->get(['id', 'order_number']) : collect(),
        ]);
    }

    private function resetVisit(): void
    {
        $this->visit = ['visited_at' => now()->format('Y-m-d\TH:i'), 'contact_type' => 'visit', 'outcome' => 'nothing', 'note' => '', 'order_id' => null, 'quote_reference' => ''];
        $this->resetValidation();
    }

    private function authorizeView(): void
    {
        $actor = auth()->user();
        abort_unless($actor && ($actor->isA('superadmin', 'admin') || $actor->can('users.list.view')), 403);
        if ($this->customerId) {
            app(OrderManagerAccess::class)->assertCustomer(User::query()->findOrFail($this->customerId), $actor);
        }
    }
}
