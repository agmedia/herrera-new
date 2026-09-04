<?php

namespace App\Livewire\Admin\User;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Silber\Bouncer\Database\Ability;
use Silber\Bouncer\Database\Role;

class AccessManager extends Component
{
    public string $search = '';

    /**
     * @var array<string, mixed>
     */
    public array $form = [
        'name' => '',
        'title' => '',
        'group' => 'misc',
    ];

    /**
     * @var array<string, bool>
     */
    public array $collapsedGroups = [];

    public function mount(): void
    {
        $this->authorizeAccess();
    }

    public function updatedSearch(): void
    {
        // Re-render only.
    }

    public function createAbility(): void
    {
        $this->authorizeAccess();

        $normalizedName = $this->normalizeAbilityName((string) ($this->form['name'] ?? ''));
        $group = $this->normalizeGroupKey((string) ($this->form['group'] ?? 'misc'));

        $this->form['name'] = $normalizedName;
        $this->form['group'] = $group;

        $validated = $this->validate([
            'form.name' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/',
                Rule::unique('abilities', 'name')->where(fn ($q) => $q
                    ->whereNull('entity_id')
                    ->whereNull('entity_type')
                ),
            ],
            'form.title' => ['nullable', 'string', 'max:160'],
            'form.group' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9_.]+$/'],
        ]);

        $title = trim((string) ($validated['form']['title'] ?? ''));
        if ($title === '') {
            $title = $this->humanizeAbilityName((string) $validated['form']['name']);
        }

        $ability = Ability::query()->create([
            'name' => (string) $validated['form']['name'],
            'title' => $title,
        ]);
        $ability->forceFill([
            'options' => ['group' => (string) $validated['form']['group']],
        ])->save();

        activity('admin_users')
            ->performedOn($ability)
            ->causedBy(auth()->user())
            ->event('ability_created')
            ->withProperties([
                'name' => $ability->name,
                'title' => $ability->title,
                'group' => (string) $validated['form']['group'],
            ])
            ->log('Ability created');

        $this->form = [
            'name' => '',
            'title' => '',
            'group' => $group,
        ];

        $this->dispatch('notify', type: 'success', message: __('Ability added.'));
    }

    public function togglePermission(int $abilityId, int $roleId): void
    {
        $this->authorizeAccess();

        $ability = Ability::query()
            ->whereNull('entity_id')
            ->whereNull('entity_type')
            ->findOrFail($abilityId);

        $role = Role::query()->findOrFail($roleId);
        abort_if($role->name === 'superadmin', 422, 'Superadmin permissions are managed via wildcard access.');

        $roleMorph = $role->getMorphClass();

        $isAllowed = DB::table('permissions')
            ->where('ability_id', $ability->id)
            ->where('entity_id', $role->id)
            ->where('entity_type', $roleMorph)
            ->where('forbidden', false)
            ->exists();

        if ($isAllowed) {
            Bouncer::disallow($role)->to($ability);
            Bouncer::unforbid($role)->to($ability);
            $event = 'ability_revoked';
        } else {
            Bouncer::unforbid($role)->to($ability);
            Bouncer::allow($role)->to($ability);
            $event = 'ability_granted';
        }

        activity('admin_users')
            ->performedOn($role)
            ->causedBy(auth()->user())
            ->event($event)
            ->withProperties([
                'role' => $role->name,
                'ability' => $ability->name,
                'allowed' => ! $isAllowed,
            ])
            ->log('Role ability updated');
    }

    public function toggleGroup(string $group): void
    {
        $group = $this->normalizeGroupKey($group);
        $this->collapsedGroups[$group] = !($this->collapsedGroups[$group] ?? false);
    }

    public function render()
    {
        $loyaltyEnabled = (bool) app(SystemSettingsService::class)->get(
            'user_loyalty_enabled',
            (bool) config('user_features.flags.user_loyalty_enabled', false)
        );

        $roles = Role::query()
            ->orderBy('id')
            ->get(['id', 'name', 'title']);

        $matrixRoles = $roles
            ->reject(static fn (Role $role): bool => $role->name === 'superadmin')
            ->values();

        $abilities = Ability::query()
            ->whereNull('entity_id')
            ->whereNull('entity_type')
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($q): void {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('title', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'title', 'options']);

        if (! $loyaltyEnabled) {
            $abilities = $abilities
                ->reject(fn (Ability $ability): bool => str_starts_with((string) $ability->name, 'users.loyalty.')
                    || $this->resolveAbilityGroupKey($ability) === 'users.loyalty')
                ->values();
        }

        $permissionMap = $this->buildPermissionMap($matrixRoles->pluck('id')->all(), $abilities->pluck('id')->all());
        $abilityGroups = $this->groupAbilities($abilities->all());
        $groupOptions = $this->buildGroupOptions($abilityGroups, $loyaltyEnabled);

        return view('livewire.admin.user.access-manager', [
            'roles' => $matrixRoles,
            'abilityGroups' => $abilityGroups,
            'permissionMap' => $permissionMap,
            'groupOptions' => $groupOptions,
        ]);
    }

    /**
     * @param  array<int, int>  $roleIds
     * @param  array<int, int>  $abilityIds
     * @return array<int, array<int, bool>>
     */
    private function buildPermissionMap(array $roleIds, array $abilityIds): array
    {
        if ($roleIds === [] || $abilityIds === []) {
            return [];
        }

        $roleMorph = (new Role())->getMorphClass();

        $rows = DB::table('permissions')
            ->select(['entity_id', 'ability_id', 'forbidden'])
            ->where('entity_type', $roleMorph)
            ->whereIn('entity_id', $roleIds)
            ->whereIn('ability_id', $abilityIds)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $abilityId = (int) $row->ability_id;
            $roleId = (int) $row->entity_id;
            $map[$abilityId][$roleId] = !(bool) $row->forbidden;
        }

        return $map;
    }

    /**
     * @param  array<int, Ability>  $abilities
     * @return array<string, array{key: string, label: string, abilities: array<int, Ability>}>
     */
    private function groupAbilities(array $abilities): array
    {
        $groups = [];

        foreach ($abilities as $ability) {
            $groupKey = $this->resolveAbilityGroupKey($ability);
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'key' => $groupKey,
                    'label' => $this->groupLabel($groupKey),
                    'abilities' => [],
                ];
            }

            $groups[$groupKey]['abilities'][] = $ability;
        }

        uasort($groups, fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']));

        return $groups;
    }

    /**
     * @param  array<string, array{key: string, label: string, abilities: array<int, Ability>}>  $abilityGroups
     * @return array<string, string>
     */
    private function buildGroupOptions(array $abilityGroups, bool $loyaltyEnabled): array
    {
        $options = $this->defaultGroupLabels();
        if (! $loyaltyEnabled) {
            unset($options['users.loyalty']);
        }

        foreach ($abilityGroups as $groupKey => $groupData) {
            $options[$groupKey] = (string) $groupData['label'];
        }

        asort($options);

        return $options;
    }

    private function resolveAbilityGroupKey(Ability $ability): string
    {
        $optionsGroup = data_get($this->decodeAbilityOptions($ability), 'group');
        if (is_string($optionsGroup) && trim($optionsGroup) !== '') {
            return $this->normalizeGroupKey($optionsGroup);
        }

        $name = (string) $ability->name;
        if (str_contains($name, '.')) {
            return $this->normalizeGroupKey(Str::before($name, '.'));
        }

        return 'misc';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAbilityOptions(Ability $ability): array
    {
        $raw = $ability->getAttribute('options');
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function normalizeGroupKey(string $group): string
    {
        $group = strtolower(trim($group));
        $group = str_replace(['-', ' '], '_', $group);
        $group = preg_replace('/[^a-z0-9_.]+/', '', $group) ?: 'misc';
        $group = preg_replace('/\.{2,}/', '.', $group) ?: $group;

        return trim($group, '._') !== '' ? trim($group, '._') : 'misc';
    }

    private function normalizeAbilityName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = str_replace(' ', '-', $name);
        $name = preg_replace('/[^a-z0-9._-]+/', '-', $name) ?: '';
        $name = trim($name, '.-_');

        return preg_replace('/([._-]){2,}/', '$1', $name) ?: '';
    }

    private function groupLabel(string $groupKey): string
    {
        $labels = $this->defaultGroupLabels();
        if (isset($labels[$groupKey])) {
            return $labels[$groupKey];
        }

        return (string) Str::of($groupKey)->replace('.', ' / ')->replace('_', ' ')->title();
    }

    /**
     * @return array<string, string>
     */
    private function defaultGroupLabels(): array
    {
        return [
            'users.core' => __('Users / Core'),
            'users.groups' => __('Users / Groups'),
            'users.activity' => __('Users / Activity'),
            'users.newsletter' => __('Users / Newsletter'),
            'users.loyalty' => __('Users / Loyalty'),
            'users.access' => __('Users / Access'),
            'catalog.categories' => __('Catalog / Categories'),
            'catalog.products' => __('Catalog / Products'),
            'catalog.attributes' => __('Catalog / Attributes'),
            'catalog.options' => __('Catalog / Options'),
            'catalog.manufacturers' => __('Catalog / Manufacturers'),
            'catalog.actions' => __('Catalog / Actions'),
            'content.blog' => __('Content / Blog'),
            'content.pages' => __('Content / Pages'),
            'content.faqs' => __('Content / FAQs'),
            'content.comments' => __('Content / Comments'),
            'content.blocks' => __('Content / Blocks'),
            'content.slots' => __('Content / Slots'),
            'content.media' => __('Content / Media'),
            'sales.orders' => __('Sales / Orders'),
            'sales' => __('Sales'),
            'settings.local' => __('Settings / Local'),
            'settings.system' => __('Settings / System'),
            'settings.api' => __('Settings / API'),
            'settings.user' => __('Settings / User'),
            'system.ai' => __('System / AI'),
            'dashboard' => __('Dashboard'),
            'misc' => __('Other'),
        ];
    }

    private function humanizeAbilityName(string $abilityName): string
    {
        return (string) Str::of($abilityName)
            ->replace(['.', '_', '-'], ' ')
            ->title();
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('users.access.manage')),
            403
        );
    }
}
