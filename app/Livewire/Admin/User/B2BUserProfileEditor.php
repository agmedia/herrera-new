<?php

namespace App\Livewire\Admin\User;

use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Models\User\UserAddress;
use App\Services\Admin\OrderManagerAccess;
use App\Services\B2B\B2BAccountService;
use App\Services\Pricing\B2BAccessService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

class B2BUserProfileEditor extends Component
{
    #[Locked]
    public int $userId;

    #[Locked]
    public ?int $accountId = null;

    public array $b2b = [];

    public ?string $savedMessage = null;

    public function mount(int $userId): void
    {
        $this->userId = $userId;
        $this->authorizeView();
        $this->loadProfile();
    }

    public function save(B2BAccountService $service): void
    {
        $this->authorizeView();
        abort_unless($this->canUpdate(), 403);
        $this->savedMessage = null;
        if (! method_exists($service, 'saveForUser')) {
            $this->addError('b2b.status', __('Spremanje B2B profila se još priprema. Pokušajte nakon osvježavanja.'));

            return;
        }
        $accountId = B2BAccount::query()->where('user_id', $this->userId)->value('id');
        $validated = $this->validate([
            'b2b.status' => ['required', Rule::in(array_keys(B2BAccount::statusOptions()))],
            'b2b.company_name' => ['required', 'string', 'max:191'],
            'b2b.oib' => ['required', 'regex:/^\d{11}$/', Rule::unique('b2b_accounts', 'oib')->ignore($accountId)],
            'b2b.vat_id' => ['nullable', 'string', 'max:60'],
            'b2b.phone' => ['nullable', 'string', 'max:80'],
            'b2b.address_line_1' => ['nullable', 'string', 'max:191'],
            'b2b.address_line_2' => ['nullable', 'string', 'max:191'],
            'b2b.postal_code' => ['nullable', 'string', 'max:32'],
            'b2b.city' => ['nullable', 'string', 'max:120'],
            'b2b.country_code' => ['required', 'string', 'size:2'],
            'b2b.customer_group_id' => [
                Rule::requiredIf(($this->b2b['status'] ?? '') === B2BAccount::STATUS_APPROVED),
                'nullable', 'integer',
                Rule::exists('customer_groups', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'b2b.erp_customer_id' => ['nullable', 'string', 'max:120'],
            'b2b.erp_company_code' => ['nullable', 'string', 'max:80'],
            'b2b.contract_number' => ['nullable', 'string', 'max:120'],
            'b2b.contract_starts_at' => ['nullable', 'date'],
            'b2b.contract_ends_at' => ['nullable', 'date', ...(! empty($this->b2b['contract_starts_at']) ? ['after_or_equal:b2b.contract_starts_at'] : [])],
            'b2b.payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'b2b.purchase_order_required' => ['boolean'],
            'b2b.status_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $creating = $accountId === null;
        try {
            $service->saveForUser(User::query()->findOrFail($this->userId), $validated['b2b'], auth()->user());
        } catch (ValidationException $exception) {
            $messages = [];
            foreach ($exception->errors() as $field => $errors) {
                $messages[str_starts_with($field, 'b2b.') ? $field : 'b2b.'.$field] = $errors;
            }
            throw ValidationException::withMessages($messages);
        }
        $this->loadProfile();
        $this->savedMessage = $creating ? __('B2B profil je kreiran.') : __('B2B profil je spremljen.');
        $this->dispatch('b2b-user-profile-saved', userId: $this->userId);
        $this->dispatch('notify', type: 'success', message: $this->savedMessage);
    }

    public function render()
    {
        $this->authorizeView();
        $target = User::query()->with('b2bAccount.customerGroup')->findOrFail($this->userId);

        return view('livewire.admin.user.b2b-user-profile-editor', [
            'canUpdate' => $this->canUpdate(),
            'saveAvailable' => method_exists(B2BAccountService::class, 'saveForUser'),
            'account' => $target->b2bAccount,
            'accessAvailable' => app(B2BAccessService::class)->approvedAccount($target) !== null,
            'customerGroups' => CustomerGroup::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'code']),
            'statusOptions' => B2BAccount::statusOptions(),
        ]);
    }

    private function loadProfile(): void
    {
        $user = User::query()->with(['profile', 'addresses', 'customerGroups', 'b2bAccount'])->findOrFail($this->userId);
        $account = $user->b2bAccount;
        $billing = $user->addresses->where('type', UserAddress::TYPE_BILLING)->sortByDesc('is_default')->first();
        $activeGroups = $user->customerGroups->where('is_active', true);
        $this->accountId = $account?->id;
        $this->b2b = [
            'status' => $account?->status ?? B2BAccount::STATUS_PENDING,
            'company_name' => $account?->company_name ?? ($user->profile?->company ?: $billing?->company ?? ''),
            'oib' => $account?->oib ?? ($user->profile?->oib ?: $billing?->oib ?? ''),
            'vat_id' => $account?->vat_id ?? $billing?->vat_id ?? '',
            'phone' => $account?->phone ?? ($user->profile?->phone ?: $billing?->phone ?? ''),
            'address_line_1' => $account?->address_line_1 ?? $billing?->address_line_1 ?? '',
            'address_line_2' => $account?->address_line_2 ?? $billing?->address_line_2 ?? '',
            'postal_code' => $account?->postal_code ?? $billing?->postal_code ?? '',
            'city' => $account?->city ?? $billing?->city ?? '',
            'country_code' => $account?->country_code ?? $billing?->country_code ?? 'HR',
            'customer_group_id' => $account ? $account->customer_group_id : ($activeGroups->count() === 1 ? $activeGroups->first()->id : null),
            'erp_customer_id' => $account?->erp_customer_id ?? '',
            'erp_company_code' => $account?->erp_company_code ?? '',
            'contract_number' => $account?->contract_number ?? '',
            'contract_starts_at' => $account?->contract_starts_at?->format('Y-m-d') ?? '',
            'contract_ends_at' => $account?->contract_ends_at?->format('Y-m-d') ?? '',
            'payment_terms_days' => $account?->payment_terms_days ?? '',
            'purchase_order_required' => (bool) $account?->purchase_order_required,
            'status_reason' => $account?->status_reason ?? '',
        ];
    }

    private function authorizeView(): void
    {
        $current = auth()->user();
        abort_unless($current && ($current->isA('superadmin') || $current->isA('admin') || $current->can('users.list.view') || $current->can('users.profile.update')), 403);
        $target = User::query()->findOrFail($this->userId);
        app(OrderManagerAccess::class)->assertCustomer($target, $current);
        abort_if(! $current->isA('superadmin') && $target->isA('superadmin'), 403);
    }

    private function canUpdate(): bool
    {
        $current = auth()->user();

        return (bool) ($current && ($current->isA('superadmin') || $current->isA('admin') || ($current->can('admin.access') && $current->can('users.profile.update'))));
    }
}
