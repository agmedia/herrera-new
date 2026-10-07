<?php

namespace App\Services\B2B;

use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Models\User\UserAddress;
use App\Models\User\UserProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class B2BAccountService
{
    private const BUSINESS_FIELDS = [
        'company_name', 'oib', 'vat_id', 'phone', 'address_line_1', 'address_line_2',
        'postal_code', 'city', 'country_code',
    ];

    private const REVIEW_FIELDS = [
        'status', 'customer_group_id', 'erp_customer_id', 'erp_company_code',
        'contract_number', 'contract_starts_at', 'contract_ends_at',
        'payment_terms_days', 'purchase_order_required', 'status_reason',
    ];

    /**
     * Izričito spremanje poslovnog profila postojećeg korisnika, bez promjene prijave i uloga.
     * Izostavljena polja ostaju netaknuta; prazan unos briše samo poslano opcionalno polje.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveForUser(User $user, array $data, User $reviewer): B2BAccount
    {
        if (! $reviewer->isA('superadmin') && ! $reviewer->isA('admin')
            && ! ($reviewer->can('admin.access') && $reviewer->can('users.profile.update'))) {
            throw new AuthorizationException('Nemate ovlasti za uređivanje B2B računa.');
        }

        $data = Arr::only($data, array_merge(self::BUSINESS_FIELDS, self::REVIEW_FIELDS));
        foreach ($data as $field => $value) {
            if (is_string($value)) {
                $data[$field] = trim($value);
                if ($data[$field] === '' && ! in_array($field, ['company_name', 'oib', 'country_code', 'status'], true)) {
                    $data[$field] = null;
                }
            }
        }

        $account = DB::transaction(function () use ($user, $data, $reviewer): B2BAccount {
            // OIB nema UNIQUE indeks zbog povijesnih uvoza. Zajednički zaključani red korisnika
            // serijalizira ovaj postupak i za novi OIB koji još nema red za zaključavanje.
            User::query()->orderBy('id')->lockForUpdate()->firstOrFail(['id']);
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if ($lockedUser->isA('superadmin') && ! $reviewer->isA('superadmin')) {
                throw new AuthorizationException('Samo superadministrator može uređivati ovaj račun.');
            }

            $account = B2BAccount::query()->where('user_id', $lockedUser->getKey())->lockForUpdate()->first();
            $created = $account === null;
            $previousStatus = $account?->status;
            $previousGroupId = $account?->customer_group_id;
            $merged = $this->profileData($account, $data);

            if (($created || array_key_exists('oib', $data)) && is_string($merged['oib'])) {
                B2BAccount::query()->where('oib', $merged['oib'])->lockForUpdate()->get(['id']);
            }
            if (is_int($merged['customer_group_id']) || (is_string($merged['customer_group_id']) && ctype_digit($merged['customer_group_id']))) {
                CustomerGroup::query()->whereKey($merged['customer_group_id'])->lockForUpdate()->first();
            }
            $this->validateProfileData($merged, $data, $account);

            if ($created) {
                $account = new B2BAccount([
                    'user_id' => $lockedUser->getKey(),
                    'status' => B2BAccount::STATUS_PENDING,
                    'requested_at' => now(),
                    'requested_customer_group_id' => $merged['customer_group_id'],
                    'payload' => ['admin_creation' => [
                        'source' => 'user_editor',
                        'actor_id' => $reviewer->getKey(),
                        'created_at' => now()->toIso8601String(),
                    ]],
                ]);
            }

            $account->fill(Arr::only($merged, self::BUSINESS_FIELDS))->save();
            $account->setRelation('user', $lockedUser);
            $this->syncBusinessDetails($lockedUser, $account, $data);
            $account = $this->review(
                $account, $merged, $reviewer,
                preserveGroupMemberships: true,
                editableFields: $created ? null : array_keys($data),
            );

            activity('admin_users')
                ->performedOn($lockedUser)
                ->causedBy($reviewer)
                ->event('b2b_profile_saved')
                ->withProperties([
                    'b2b_account_id' => $account->getKey(),
                    'created' => $created,
                    'previous_status' => $previousStatus,
                    'previous_customer_group_id' => $previousGroupId,
                    'status' => $account->status,
                    'customer_group_id' => $account->customer_group_id,
                ])
                ->log('B2B business profile saved');

            return $account;
        }, attempts: 3);

        $user->unsetRelation('b2bAccount');
        $user->unsetRelation('customerGroups');
        $user->unsetRelation('profile');
        $user->unsetRelation('addresses');

        return $account;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>|null  $editableFields  Null zadržava postojeći način pregleda; popis čuva izostavljena polja.
     */
    public function review(B2BAccount $account, array $data, User $reviewer, bool $preserveGroupMemberships = false, ?array $editableFields = null): B2BAccount
    {
        return DB::transaction(function () use ($account, $data, $reviewer, $preserveGroupMemberships, $editableFields): B2BAccount {
            $previousGroupId = $account->customer_group_id
                ? (int) $account->customer_group_id
                : null;
            $status = (string) $data['status'];
            $approvedGroupId = $status === B2BAccount::STATUS_APPROVED
                ? (int) $data['customer_group_id']
                : null;

            $fields = [
                'status' => $status,
                'customer_group_id' => $approvedGroupId,
                'erp_customer_id' => $this->nullableString($data['erp_customer_id'] ?? null),
                'erp_company_code' => $this->nullableString($data['erp_company_code'] ?? null),
                'contract_number' => $this->nullableString($data['contract_number'] ?? null),
                'contract_starts_at' => $data['contract_starts_at'] ?: null,
                'contract_ends_at' => $data['contract_ends_at'] ?: null,
                'payment_terms_days' => ($data['payment_terms_days'] ?? '') !== '' && $data['payment_terms_days'] !== null
                    ? (int) $data['payment_terms_days']
                    : null,
                'purchase_order_required' => (bool) ($data['purchase_order_required'] ?? false),
                'status_reason' => $this->nullableString($data['status_reason'] ?? null),
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->getKey(),
            ];
            if ($editableFields !== null) {
                $fields = Arr::only($fields, array_merge(
                    ['status', 'customer_group_id', 'reviewed_at', 'reviewed_by'], $editableFields,
                ));
            }
            $account->fill($fields)->save();

            if (! $preserveGroupMemberships && $previousGroupId && $previousGroupId !== $approvedGroupId) {
                $account->user->customerGroups()->detach($previousGroupId);
            }

            if ($approvedGroupId) {
                $account->user->customerGroups()->syncWithoutDetaching([$approvedGroupId]);
            }

            activity('admin_users')
                ->performedOn($account->user)
                ->causedBy($reviewer)
                ->event('b2b_reviewed')
                ->withProperties([
                    'b2b_account_id' => $account->getKey(),
                    'status' => $status,
                    'customer_group_id' => $approvedGroupId,
                    'erp_customer_id' => $account->erp_customer_id,
                ])
                ->log('B2B account reviewed');

            return $account->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function profileData(?B2BAccount $account, array $data): array
    {
        $defaults = array_fill_keys(array_merge(self::BUSINESS_FIELDS, self::REVIEW_FIELDS), null);
        $defaults['status'] = B2BAccount::STATUS_PENDING;
        $defaults['purchase_order_required'] = false;
        if ($account) {
            foreach (array_keys($defaults) as $field) {
                $defaults[$field] = in_array($field, ['contract_starts_at', 'contract_ends_at'], true)
                    ? $account->{$field}?->format('Y-m-d')
                    : $account->{$field};
            }
        }
        $merged = array_replace($defaults, $data);
        if (array_key_exists('country_code', $data) && is_string($merged['country_code'])) {
            $merged['country_code'] = strtoupper($merged['country_code']);
        }

        return $merged;
    }

    private function validateProfileData(array $merged, array $data, ?B2BAccount $account): void
    {
        $rules = [
            'status' => ['required', Rule::in(array_keys(B2BAccount::statusOptions()))],
            'customer_group_id' => [
                Rule::requiredIf($merged['status'] === B2BAccount::STATUS_APPROVED),
                'nullable', 'integer',
            ],
        ];
        if ($merged['status'] === B2BAccount::STATUS_APPROVED || array_key_exists('customer_group_id', $data)) {
            $rules['customer_group_id'][] = Rule::exists('customer_groups', 'id')
                ->where(fn ($query) => $query->where('is_active', true));
        }
        $editableRules = [
            'company_name' => ['required', 'string', 'max:191'],
            'oib' => ['required', 'string', 'regex:/^[0-9]{11}$/', Rule::unique('b2b_accounts', 'oib')->ignore($account?->getKey())],
            'country_code' => ['required', 'string', 'regex:/^[A-Z]{2}$/'],
            'vat_id' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:80'],
            'address_line_1' => ['nullable', 'string', 'max:191'],
            'address_line_2' => ['nullable', 'string', 'max:191'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:120'],
            'erp_customer_id' => ['nullable', 'string', 'max:120'],
            'erp_company_code' => ['nullable', 'string', 'max:80'],
            'contract_number' => ['nullable', 'string', 'max:120'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'purchase_order_required' => ['boolean'],
            'status_reason' => ['nullable', 'string', 'max:2000'],
        ];
        foreach ($editableRules as $field => $fieldRules) {
            if (! $account || array_key_exists($field, $data)) {
                $rules[$field] = $fieldRules;
            }
        }
        if (! $account || array_key_exists('contract_starts_at', $data) || array_key_exists('contract_ends_at', $data)) {
            $rules['contract_starts_at'] = ['nullable', 'date'];
            $rules['contract_ends_at'] = ['nullable', 'date'];
            if ($merged['contract_starts_at'] !== null) {
                $rules['contract_ends_at'][] = 'after_or_equal:contract_starts_at';
            }
        }

        Validator::make($merged, $rules, [
            'oib.unique' => 'B2B račun s ovim OIB-om već postoji. Otvorite postojeći račun ili unesite drugi OIB.',
            'oib.regex' => 'OIB mora sadržavati točno 11 znamenki.',
            'customer_group_id.required' => 'Za odobrenje računa odaberite glavnu grupu kupaca.',
            'customer_group_id.exists' => 'Odabrana grupa kupaca ne postoji ili nije aktivna.',
            'contract_ends_at.after_or_equal' => 'Datum završetka ugovora ne može biti prije datuma početka.',
        ])->validate();
    }

    private function syncBusinessDetails(User $user, B2BAccount $account, array $data): void
    {
        $profileFields = [];
        foreach (['company_name' => 'company', 'oib' => 'oib', 'phone' => 'phone'] as $accountField => $profileField) {
            if (array_key_exists($accountField, $data)) {
                $profileFields[$profileField] = $account->{$accountField};
            }
        }
        if ($profileFields !== []) {
            UserProfile::query()->updateOrCreate(['user_id' => $user->getKey()], $profileFields);
        }

        $billingFields = [];
        foreach (self::BUSINESS_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $billingFields[$field === 'company_name' ? 'company' : $field] = $account->{$field};
            }
        }
        if ($billingFields !== []) {
            // Mijenja se samo primarna adresa računa, nikad adresa dostave ni druga spremljena adresa.
            $billing = UserAddress::query()->where('user_id', $user->getKey())
                ->where('type', UserAddress::TYPE_BILLING)->orderByDesc('is_default')->orderBy('id')
                ->lockForUpdate()->first();
            $billing ??= new UserAddress([
                'user_id' => $user->getKey(), 'type' => UserAddress::TYPE_BILLING, 'is_default' => true,
            ]);
            $billing->fill($billingFields)->save();
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
