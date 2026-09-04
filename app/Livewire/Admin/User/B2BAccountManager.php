<?php

namespace App\Livewire\Admin\User;

use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Models\User\UserAddress;
use App\Models\User\UserProfile;
use App\Services\B2B\B2BAccountService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class B2BAccountManager extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = B2BAccount::STATUS_PENDING;

    public ?int $selectedId = null;

    public array $form = [];

    public function mount(): void
    {
        $this->ensureCanView();
        $this->resetForm();

        $accountId = (int) request()->query('account', 0);
        if ($accountId > 0) {
            $this->selectAccount($accountId);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function selectAccount(int $accountId): void
    {
        $this->ensureCanView();
        $account = B2BAccount::query()->findOrFail($accountId);
        $this->selectedId = (int) $account->getKey();
        $this->form = [
            'status' => $account->status,
            'company_name' => $account->company_name,
            'oib' => $account->oib,
            'vat_id' => $account->vat_id ?? '',
            'phone' => $account->phone ?? '',
            'address_line_1' => $account->address_line_1 ?? '',
            'address_line_2' => $account->address_line_2 ?? '',
            'postal_code' => $account->postal_code ?? '',
            'city' => $account->city ?? '',
            'country_code' => $account->country_code ?: 'HR',
            'customer_group_id' => $account->customer_group_id,
            'erp_customer_id' => $account->erp_customer_id ?? '',
            'erp_company_code' => $account->erp_company_code ?? '',
            'contract_number' => $account->contract_number ?? '',
            'contract_starts_at' => $account->contract_starts_at?->format('Y-m-d') ?? '',
            'contract_ends_at' => $account->contract_ends_at?->format('Y-m-d') ?? '',
            'payment_terms_days' => $account->payment_terms_days ?? '',
            'purchase_order_required' => (bool) $account->purchase_order_required,
            'status_reason' => $account->status_reason ?? '',
        ];
        $this->resetValidation();
    }

    public function closeEditor(): void
    {
        $this->selectedId = null;
        $this->resetForm();
        $this->resetValidation();
    }

    public function save(B2BAccountService $service): void
    {
        $this->ensureCanMutate();

        $validated = $this->validate([
            'form.status' => ['required', Rule::in(array_keys(B2BAccount::statusOptions()))],
            'form.company_name' => ['required', 'string', 'max:191'],
            'form.oib' => [
                'required',
                'regex:/^\d{11}$/',
                Rule::unique('b2b_accounts', 'oib')->ignore($this->selectedId),
            ],
            'form.vat_id' => ['nullable', 'string', 'max:60'],
            'form.phone' => ['nullable', 'string', 'max:80'],
            'form.address_line_1' => ['nullable', 'string', 'max:191'],
            'form.address_line_2' => ['nullable', 'string', 'max:191'],
            'form.postal_code' => ['nullable', 'string', 'max:32'],
            'form.city' => ['nullable', 'string', 'max:120'],
            'form.country_code' => ['required', 'string', 'size:2'],
            'form.customer_group_id' => [
                Rule::requiredIf(($this->form['status'] ?? '') === B2BAccount::STATUS_APPROVED),
                'nullable',
                'integer',
                Rule::exists('customer_groups', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'form.erp_customer_id' => ['nullable', 'string', 'max:120'],
            'form.erp_company_code' => ['nullable', 'string', 'max:80'],
            'form.contract_number' => ['nullable', 'string', 'max:120'],
            'form.contract_starts_at' => ['nullable', 'date'],
            'form.contract_ends_at' => ['nullable', 'date', 'after_or_equal:form.contract_starts_at'],
            'form.payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'form.purchase_order_required' => ['boolean'],
            'form.status_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $account = B2BAccount::query()->with('user')->findOrFail($this->selectedId);

        DB::transaction(function () use ($account, $validated, $service): void {
            $account->fill([
                'company_name' => trim((string) $validated['form']['company_name']),
                'oib' => trim((string) $validated['form']['oib']),
                'vat_id' => $this->nullableString($validated['form']['vat_id'] ?? null),
                'phone' => $this->nullableString($validated['form']['phone'] ?? null),
                'address_line_1' => $this->nullableString($validated['form']['address_line_1'] ?? null),
                'address_line_2' => $this->nullableString($validated['form']['address_line_2'] ?? null),
                'postal_code' => $this->nullableString($validated['form']['postal_code'] ?? null),
                'city' => $this->nullableString($validated['form']['city'] ?? null),
                'country_code' => strtoupper((string) $validated['form']['country_code']),
            ])->save();

            UserProfile::query()->updateOrCreate(
                ['user_id' => $account->user_id],
                [
                    'company' => $account->company_name,
                    'oib' => $account->oib,
                    'phone' => $account->phone,
                ],
            );
            UserAddress::query()->updateOrCreate(
                [
                    'user_id' => $account->user_id,
                    'type' => UserAddress::TYPE_BILLING,
                ],
                [
                    'company' => $account->company_name,
                    'oib' => $account->oib,
                    'vat_id' => $account->vat_id,
                    'phone' => $account->phone,
                    'address_line_1' => $account->address_line_1,
                    'address_line_2' => $account->address_line_2,
                    'postal_code' => $account->postal_code,
                    'city' => $account->city,
                    'country_code' => $account->country_code,
                    'is_default' => true,
                ],
            );

            $service->review($account, $validated['form'], auth()->user());
        });
        $this->selectAccount((int) $account->getKey());
        $this->dispatch('notify', type: 'success', message: __('B2B račun je spremljen.'));
    }

    public function render()
    {
        $this->ensureCanView();

        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200,
        );

        $rows = B2BAccount::query()
            ->with([
                'user:id,name,email',
                'customerGroup:id,code,name',
                'reviewer:id,name',
            ])
            ->when($this->search !== '', function ($query): void {
                $term = trim($this->search);
                $query->where(function ($query) use ($term): void {
                    $query
                        ->where('company_name', 'like', '%'.$term.'%')
                        ->orWhere('oib', 'like', '%'.$term.'%')
                        ->orWhere('erp_customer_id', 'like', '%'.$term.'%')
                        ->orWhereHas('user', fn ($userQuery) => $userQuery
                            ->where('name', 'like', '%'.$term.'%')
                            ->orWhere('email', 'like', '%'.$term.'%'));
                });
            })
            ->when($this->statusFilter !== 'all', fn ($query) => $query
                ->where('status', $this->statusFilter))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return view('livewire.admin.user.b2b-account-manager', [
            'rows' => $rows,
            'perPage' => $perPage,
            'statusOptions' => B2BAccount::statusOptions(),
            'customerGroups' => CustomerGroup::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'canMutate' => $this->canMutate(),
        ]);
    }

    private function resetForm(): void
    {
        $this->form = [
            'status' => B2BAccount::STATUS_PENDING,
            'company_name' => '',
            'oib' => '',
            'vat_id' => '',
            'phone' => '',
            'address_line_1' => '',
            'address_line_2' => '',
            'postal_code' => '',
            'city' => '',
            'country_code' => 'HR',
            'customer_group_id' => null,
            'erp_customer_id' => '',
            'erp_company_code' => '',
            'contract_number' => '',
            'contract_starts_at' => '',
            'contract_ends_at' => '',
            'payment_terms_days' => '',
            'purchase_order_required' => false,
            'status_reason' => '',
        ];
    }

    private function ensureCanView(): void
    {
        $user = auth()->user();
        abort_unless($user && ($user->isA('superadmin') || $user->isA('admin') || $user->can('users.list.view')), 403);
    }

    private function ensureCanMutate(): void
    {
        abort_unless($this->canMutate(), 403);
    }

    private function canMutate(): bool
    {
        $user = auth()->user();

        return (bool) ($user && (
            $user->isA('superadmin')
            || $user->isA('admin')
            || $user->can('users.profile.update')
        ));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
