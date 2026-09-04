<?php

namespace App\Livewire\Admin\User;

use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Models\User\UserAddress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Role;

class Form extends Component
{
    public int $userId;

    /**
     * @var array<string, mixed>
     */
    public array $form = [
        'name' => '',
        'email' => '',
        'role' => '',
        'email_verified' => true,
        'password' => '',
        'password_confirmation' => '',
        'customer_groups' => [],
        'profile' => [
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
            'company' => '',
            'oib' => '',
            'birthday' => '',
            'gender' => '',
            'affiliate_name' => '',
            'bio' => '',
            'newsletter_opt_in' => false,
        ],
        'billing_address' => [
            'first_name' => '',
            'last_name' => '',
            'company' => '',
            'oib' => '',
            'vat_id' => '',
            'phone' => '',
            'address_line_1' => '',
            'address_line_2' => '',
            'postal_code' => '',
            'city' => '',
            'country_code' => 'HR',
        ],
        'shipping_address' => [
            'first_name' => '',
            'last_name' => '',
            'oib' => '',
            'vat_id' => '',
            'phone' => '',
            'address_line_1' => '',
            'address_line_2' => '',
            'postal_code' => '',
            'city' => '',
            'country_code' => 'HR',
        ],
    ];

    public function mount(int $userId): void
    {
        $this->authorizeAccess();
        $this->userId = $userId;
        $this->loadUser();
    }

    public function save()
    {
        $validated = $this->validate($this->rules());
        $payload = $validated['form'];

        DB::transaction(function () use ($payload): void {
            $user = User::query()
                ->with(['customerGroups:id,name'])
                ->findOrFail($this->userId);
            $this->ensureCanManageTargetUser($user);

            $user->name = trim((string) $payload['name']);
            $user->email = trim((string) $payload['email']);
            $user->email_verified_at = (bool) $payload['email_verified'] ? ($user->email_verified_at ?: now()) : null;

            if (! empty($payload['password'])) {
                $user->password = (string) $payload['password'];
            }

            $user->save();

            $role = Role::query()->where('name', (string) $payload['role'])->firstOrFail();
            $user->roles()->sync([$role->id]);
            Bouncer::refreshFor($user);

            $profilePayload = $this->normalizeProfilePayload((array) ($payload['profile'] ?? []));
            $billingPayload = $this->normalizeAddressPayload((array) ($payload['billing_address'] ?? []));
            $shippingPayload = $this->normalizeAddressPayload((array) ($payload['shipping_address'] ?? []));
            $shippingPayload['company'] = $billingPayload['company'];

            $user->profile()->updateOrCreate([], $profilePayload);
            $this->upsertAddress($user, UserAddress::TYPE_BILLING, $billingPayload);
            $this->upsertAddress($user, UserAddress::TYPE_SHIPPING, $shippingPayload);

            $groupIds = collect((array) ($payload['customer_groups'] ?? []))
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
            $user->customerGroups()->sync($groupIds);

            $groupNames = CustomerGroup::query()
                ->whereIn('id', $groupIds)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name')
                ->values()
                ->all();

            activity('admin_users')
                ->performedOn($user)
                ->causedBy(auth()->user())
                ->event('updated')
                ->withProperties([
                    'role' => $role->name,
                    'email_verified' => (bool) $payload['email_verified'],
                    'groups' => $groupNames,
                    'profile' => $profilePayload,
                    'billing_address' => $billingPayload,
                    'shipping_address' => $shippingPayload,
                ])
                ->log('Admin user updated');
        });

        return redirect()
            ->route('admin.users')
            ->with('notify', [
                'type' => 'success',
                'message' => __('User updated.'),
            ]);
    }

    public function backToList()
    {
        return redirect()->route('admin.users');
    }

    public function render()
    {
        return view('livewire.admin.user.form', [
            'roles' => $this->assignableRoles(),
            'customerGroups' => CustomerGroup::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.name' => ['required', 'string', 'max:255'],
            'form.email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->userId)],
            'form.role' => ['required', 'string', Rule::in($this->assignableRoleNames())],
            'form.email_verified' => ['boolean'],
            'form.password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'form.customer_groups' => ['array'],
            'form.customer_groups.*' => ['integer', Rule::exists('customer_groups', 'id')],
            'form.profile.first_name' => ['nullable', 'string', 'max:120'],
            'form.profile.last_name' => ['nullable', 'string', 'max:120'],
            'form.profile.phone' => ['nullable', 'string', 'max:80'],
            'form.profile.company' => ['nullable', 'string', 'max:191'],
            'form.profile.oib' => ['nullable', 'string', 'max:60'],
            'form.profile.birthday' => ['nullable', 'date'],
            'form.profile.gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'form.profile.affiliate_name' => ['nullable', 'string', 'max:191'],
            'form.profile.bio' => ['nullable', 'string'],
            'form.profile.newsletter_opt_in' => ['boolean'],
            'form.billing_address.first_name' => ['nullable', 'string', 'max:120'],
            'form.billing_address.last_name' => ['nullable', 'string', 'max:120'],
            'form.billing_address.company' => ['nullable', 'string', 'max:191'],
            'form.billing_address.oib' => ['nullable', 'string', 'max:60'],
            'form.billing_address.vat_id' => ['nullable', 'string', 'max:60'],
            'form.billing_address.phone' => ['nullable', 'string', 'max:80'],
            'form.billing_address.address_line_1' => ['nullable', 'string', 'max:191'],
            'form.billing_address.address_line_2' => ['nullable', 'string', 'max:191'],
            'form.billing_address.postal_code' => ['nullable', 'string', 'max:32'],
            'form.billing_address.city' => ['nullable', 'string', 'max:120'],
            'form.billing_address.country_code' => ['nullable', 'string', 'max:2'],
            'form.shipping_address.first_name' => ['nullable', 'string', 'max:120'],
            'form.shipping_address.last_name' => ['nullable', 'string', 'max:120'],
            'form.shipping_address.oib' => ['nullable', 'string', 'max:60'],
            'form.shipping_address.vat_id' => ['nullable', 'string', 'max:60'],
            'form.shipping_address.phone' => ['nullable', 'string', 'max:80'],
            'form.shipping_address.address_line_1' => ['nullable', 'string', 'max:191'],
            'form.shipping_address.address_line_2' => ['nullable', 'string', 'max:191'],
            'form.shipping_address.postal_code' => ['nullable', 'string', 'max:32'],
            'form.shipping_address.city' => ['nullable', 'string', 'max:120'],
            'form.shipping_address.country_code' => ['nullable', 'string', 'max:2'],
        ];
    }

    private function loadUser(): void
    {
        $user = User::query()
            ->with(['roles:id,name,title', 'profile', 'addresses', 'customerGroups:id,name'])
            ->findOrFail($this->userId);
        $this->ensureCanManageTargetUser($user);

        $roleName = $this->resolvePrimaryRoleName($user->roles);
        $billing = $user->addresses->firstWhere('type', UserAddress::TYPE_BILLING);
        $shipping = $user->addresses->firstWhere('type', UserAddress::TYPE_SHIPPING);

        $this->form['name'] = (string) $user->name;
        $this->form['email'] = (string) $user->email;
        $this->form['role'] = $roleName;
        $this->form['email_verified'] = (bool) $user->email_verified_at;
        $this->form['password'] = '';
        $this->form['password_confirmation'] = '';
        $this->form['customer_groups'] = $user->customerGroups->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $this->form['profile'] = [
            'first_name' => (string) ($user->profile?->first_name ?? ''),
            'last_name' => (string) ($user->profile?->last_name ?? ''),
            'phone' => (string) ($user->profile?->phone ?? ''),
            'company' => (string) ($user->profile?->company ?? ''),
            'oib' => (string) ($user->profile?->oib ?? ''),
            'birthday' => $user->profile?->birthday?->format('Y-m-d') ?? '',
            'gender' => (string) ($user->profile?->gender ?? ''),
            'affiliate_name' => (string) ($user->profile?->affiliate_name ?? ''),
            'bio' => (string) ($user->profile?->bio ?? ''),
            'newsletter_opt_in' => (bool) ($user->profile?->newsletter_opt_in ?? false),
        ];
        $this->form['billing_address'] = $this->addressToFormPayload($billing);
        $this->form['shipping_address'] = $this->addressToFormPayload($shipping);
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    private function resolvePrimaryRoleName(Collection $roles): string
    {
        if ($roles->contains(static fn (Role $role): bool => $role->name === 'superadmin')) {
            return 'superadmin';
        }

        return (string) ($roles->sortBy('id')->first()?->name ?: 'customer');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeProfilePayload(array $payload): array
    {
        $gender = $this->nullableString($payload['gender'] ?? null);
        if (! in_array($gender, ['male', 'female', 'other'], true)) {
            $gender = null;
        }

        return [
            'first_name' => $this->nullableString($payload['first_name'] ?? null),
            'last_name' => $this->nullableString($payload['last_name'] ?? null),
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'company' => $this->nullableString($payload['company'] ?? null),
            'oib' => $this->nullableString($payload['oib'] ?? null),
            'birthday' => $this->nullableString($payload['birthday'] ?? null),
            'gender' => $gender,
            'affiliate_name' => $this->nullableString($payload['affiliate_name'] ?? null),
            'bio' => $this->nullableString($payload['bio'] ?? null),
            'newsletter_opt_in' => (bool) ($payload['newsletter_opt_in'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeAddressPayload(array $payload): array
    {
        $country = strtoupper((string) ($payload['country_code'] ?? 'HR'));
        $country = trim($country) !== '' ? substr($country, 0, 2) : 'HR';

        return [
            'first_name' => $this->nullableString($payload['first_name'] ?? null),
            'last_name' => $this->nullableString($payload['last_name'] ?? null),
            'company' => $this->nullableString($payload['company'] ?? null),
            'oib' => $this->nullableString($payload['oib'] ?? null),
            'vat_id' => $this->nullableString($payload['vat_id'] ?? null),
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'address_line_1' => $this->nullableString($payload['address_line_1'] ?? null),
            'address_line_2' => $this->nullableString($payload['address_line_2'] ?? null),
            'postal_code' => $this->nullableString($payload['postal_code'] ?? null),
            'city' => $this->nullableString($payload['city'] ?? null),
            'state' => null,
            'country_code' => $country,
            'is_default' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertAddress(User $user, string $type, array $payload): void
    {
        $user->addresses()->updateOrCreate(
            ['type' => $type],
            array_merge($payload, ['type' => $type])
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function addressToFormPayload(?UserAddress $address): array
    {
        return [
            'first_name' => (string) ($address?->first_name ?? ''),
            'last_name' => (string) ($address?->last_name ?? ''),
            'company' => (string) ($address?->company ?? ''),
            'oib' => (string) ($address?->oib ?? ''),
            'vat_id' => (string) ($address?->vat_id ?? ''),
            'phone' => (string) ($address?->phone ?? ''),
            'address_line_1' => (string) ($address?->address_line_1 ?? ''),
            'address_line_2' => (string) ($address?->address_line_2 ?? ''),
            'postal_code' => (string) ($address?->postal_code ?? ''),
            'city' => (string) ($address?->city ?? ''),
            'country_code' => (string) ($address?->country_code ?? 'HR'),
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string !== '' ? $string : null;
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('users.profile.update')),
            403
        );
    }

    /**
     * @return Collection<int, Role>
     */
    private function assignableRoles(): Collection
    {
        return Role::query()
            ->when(! $this->canAssignSuperadmin(), fn ($query) => $query->where('name', '!=', 'superadmin'))
            ->orderBy('name')
            ->get(['name', 'title']);
    }

    /**
     * @return array<int, string>
     */
    private function assignableRoleNames(): array
    {
        return $this->assignableRoles()
            ->pluck('name')
            ->map(fn ($name): string => (string) $name)
            ->values()
            ->all();
    }

    private function canAssignSuperadmin(): bool
    {
        $current = auth()->user();

        return $current && Bouncer::is($current)->an('superadmin');
    }

    private function ensureCanManageTargetUser(User $user): void
    {
        if (! $this->canAssignSuperadmin() && $user->isA('superadmin')) {
            abort(403, 'Only superadmin can manage superadmin users.');
        }
    }
}
