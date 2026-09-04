<?php

namespace App\Livewire\Admin\Settings\Api;

use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Role;

class Manager extends Component
{
    use WithPagination;

    private const USERS_PAGE_NAME = 'adminApiUsersPage';
    private const TOKENS_PAGE_NAME = 'adminApiTokensPage';

    public string $search = '';

    public string $role = '';

    public string $accessFilter = 'all';

    public string $tokenSearch = '';

    public string $tokenUserFilter = '';

    public string $issueUserId = '';

    public string $tokenName = 'wholesale-client';

    public string $expiresAt = '';

    public string $preset = 'full_wholesale';

    /**
     * @var array<int, string>
     */
    public array $selectedAbilities = [];

    public string $generatedPlainToken = '';

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->selectedAbilities = $this->presetMap()[$this->preset] ?? $this->allAbilityKeys();
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: self::USERS_PAGE_NAME);
    }

    public function updatedRole(): void
    {
        $this->resetPage(pageName: self::USERS_PAGE_NAME);
    }

    public function updatedAccessFilter(): void
    {
        $this->resetPage(pageName: self::USERS_PAGE_NAME);
    }

    public function updatedPreset(string $preset): void
    {
        $presets = $this->presetMap();
        if (! array_key_exists($preset, $presets)) {
            return;
        }

        $this->selectedAbilities = $presets[$preset];
    }

    public function updatedTokenSearch(): void
    {
        $this->resetPage(pageName: self::TOKENS_PAGE_NAME);
    }

    public function updatedTokenUserFilter(): void
    {
        $this->resetPage(pageName: self::TOKENS_PAGE_NAME);
    }

    public function toggleApiAccess(int $userId): void
    {
        $this->authorizeAccess();

        $user = User::query()
            ->withCount('tokens')
            ->findOrFail($userId);

        $user->api_access_enabled = ! (bool) $user->api_access_enabled;
        $user->save();

        if (! $user->api_access_enabled) {
            $revoked = $user->tokens()->delete();
            if ((string) $user->id === $this->issueUserId) {
                $this->issueUserId = '';
            }

            $this->dispatch('notify', type: 'warning', message: __('API disabled for :email. Revoked :count token(s).', ['email' => $user->email, 'count' => $revoked]));
            return;
        }

        $this->dispatch('notify', type: 'success', message: __('API enabled for :email.', ['email' => $user->email]));
    }

    public function prepareIssueToken(int $userId): void
    {
        $this->authorizeAccess();

        $user = User::query()->findOrFail($userId);

        if (! (bool) $user->api_access_enabled) {
            $this->dispatch('notify', type: 'warning', message: __('Enable API access for this user before issuing token.'));
            return;
        }

        $this->issueUserId = (string) $user->id;
        $this->tokenUserFilter = (string) $user->id;
        $this->resetPage(pageName: self::TOKENS_PAGE_NAME);
        $this->dispatch('notify', type: 'info', message: __('Ready to issue token for :email.', ['email' => $user->email]));
    }

    public function revokeToken(int $tokenId): void
    {
        $this->authorizeAccess();

        $token = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->findOrFail($tokenId);

        $token->delete();

        $this->dispatch('notify', type: 'success', message: __('API token revoked.'));
    }

    public function revokeAllTokensForUser(int $userId): void
    {
        $this->authorizeAccess();

        $user = User::query()->findOrFail($userId);
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        $this->dispatch('notify', type: 'warning', message: __('Revoked :count token(s) for :email.', ['count' => $count, 'email' => $user->email]));
    }

    public function issueToken(): void
    {
        $this->authorizeAccess();

        $validated = $this->validate($this->rules());

        $user = User::query()->findOrFail((int) $validated['issueUserId']);

        if (! (bool) $user->api_access_enabled) {
            $this->addError('issueUserId', __('Selected user has API access disabled.'));
            return;
        }

        $allowed = collect($this->allAbilityKeys());
        $abilities = collect($validated['selectedAbilities'])
            ->map(fn ($value): string => (string) $value)
            ->filter(fn (string $ability): bool => $allowed->contains($ability))
            ->unique()
            ->values();

        if (! $abilities->contains('wholesale.read')) {
            $abilities->prepend('wholesale.read');
        }

        $expiresAt = null;
        if ($validated['expiresAt'] !== '') {
            $expiresAt = Carbon::createFromFormat('Y-m-d\TH:i', (string) $validated['expiresAt'], config('app.timezone'));
        }

        $token = $user->createToken(
            (string) $validated['tokenName'],
            $abilities->all(),
            $expiresAt
        );

        $this->generatedPlainToken = (string) $token->plainTextToken;
        $this->tokenName = 'wholesale-client';
        $this->expiresAt = '';

        $this->dispatch('notify', type: 'success', message: __('API token created. Copy the plain token now.'));
    }

    public function render()
    {
        $settings = app(SystemSettingsService::class);
        $perPage = $settings->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $users = User::query()
            ->with(['roles:id,name,title'])
            ->withCount('tokens')
            ->when($this->search !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($this->role !== '', fn (Builder $query) => $query->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', $this->role)))
            ->when($this->accessFilter === 'enabled', fn (Builder $query) => $query->where('api_access_enabled', true))
            ->when($this->accessFilter === 'disabled', fn (Builder $query) => $query->where('api_access_enabled', false))
            ->orderBy('name')
            ->paginate($perPage, ['*'], self::USERS_PAGE_NAME);

        $tokenQuery = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->with('tokenable:id,name,email')
            ->when($this->tokenUserFilter !== '', fn (Builder $query) => $query->where('tokenable_id', (int) $this->tokenUserFilter))
            ->when($this->tokenSearch !== '', function (Builder $query): void {
                $search = trim($this->tokenSearch);
                $matchingUserIds = User::query()
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->limit(200)
                    ->pluck('id')
                    ->all();

                $query->where(function (Builder $nested) use ($search, $matchingUserIds): void {
                    $nested->where('name', 'like', '%'.$search.'%');

                    if ($matchingUserIds !== []) {
                        $nested->orWhereIn('tokenable_id', $matchingUserIds);
                    }
                });
            })
            ->latest('id');

        $tokens = $tokenQuery->paginate($perPage, ['*'], self::TOKENS_PAGE_NAME);

        $roles = Role::query()
            ->when(! $this->canSeeSuperadminRole(), fn ($query) => $query->where('name', '!=', 'superadmin'))
            ->orderBy('name')
            ->get(['name', 'title']);

        $approvedUsers = User::query()
            ->where('api_access_enabled', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('livewire.admin.settings.api.manager', [
            'users' => $users,
            'roles' => $roles,
            'perPage' => $perPage,
            'abilityCatalog' => $this->abilityCatalog(),
            'presetCatalog' => $this->presetCatalog(),
            'tokens' => $tokens,
            'approvedUsers' => $approvedUsers,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'issueUserId' => ['required', 'integer', Rule::exists('users', 'id')],
            'tokenName' => ['required', 'string', 'min:3', 'max:80'],
            'expiresAt' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'selectedAbilities' => ['required', 'array', 'min:1'],
            'selectedAbilities.*' => ['required', Rule::in($this->allAbilityKeys())],
        ];
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();

        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('settings.api.manage')),
            403
        );
    }

    private function canSeeSuperadminRole(): bool
    {
        $current = auth()->user();

        return $current && Bouncer::is($current)->an('superadmin');
    }

    /**
     * @return array<string, array{title: string, description: string}>
     */
    private function abilityCatalog(): array
    {
        return [
            'wholesale.read' => [
                'title' => __('Wholesale API Access (global)'),
                'description' => __('Global gate for wholesale endpoints.'),
            ],
            'products.read' => [
                'title' => __('Products Read'),
                'description' => __('Read product list and single product payload.'),
            ],
            'products.prices.read' => [
                'title' => __('Product Prices Read'),
                'description' => __('Read SKU + price rows endpoint.'),
            ],
            'products.quantities.read' => [
                'title' => __('Product Quantities Read'),
                'description' => __('Read SKU + stock quantity rows endpoint.'),
            ],
            'manufacturers.read' => [
                'title' => __('Manufacturers Read'),
                'description' => __('Read manufacturer list and single manufacturer payload.'),
            ],
            'categories.read' => [
                'title' => __('Categories Read'),
                'description' => __('Read category list and single category payload.'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function presetCatalog(): array
    {
        return [
            'full_wholesale' => __('Full Wholesale Read'),
            'catalog_read' => __('Catalog Read'),
            'price_stock_only' => __('Prices + Quantities'),
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function presetMap(): array
    {
        return [
            'full_wholesale' => $this->allAbilityKeys(),
            'catalog_read' => [
                'wholesale.read',
                'products.read',
                'manufacturers.read',
                'categories.read',
            ],
            'price_stock_only' => [
                'wholesale.read',
                'products.read',
                'products.prices.read',
                'products.quantities.read',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allAbilityKeys(): array
    {
        return array_keys($this->abilityCatalog());
    }
}
