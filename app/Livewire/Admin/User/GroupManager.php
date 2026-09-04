<?php

namespace App\Livewire\Admin\User;

use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use Silber\Bouncer\BouncerFacade as Bouncer;

class GroupManager extends Component
{
    use WithPagination;

    private const PAGE_NAME = 'adminUserGroupsPage';

    public string $search = '';

    public int $returnPage = 1;

    public ?int $editingId = null;

    public bool $editPage = false;

    public bool $createPage = false;

    /**
     * @var array<string, mixed>
     */
    public array $form = [
        'code' => '',
        'name' => '',
        'description' => '',
        'is_active' => true,
        'is_default' => false,
        'sort_order' => 0,
    ];

    public function mount(
        ?int $recordId = null,
        bool $editPage = false,
        bool $createPage = false,
    ): void {
        $this->authorizeAccess();
        $this->editPage = $editPage;
        $this->createPage = $createPage;
        $this->search = trim((string) request()->query('search', ''));
        $this->returnPage = max(1, (int) request()->query(self::PAGE_NAME, 1));

        abort_if($this->editPage && $this->createPage, 404);

        if ($this->editPage) {
            abort_unless($recordId, 404);
            $this->edit($recordId);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage(pageName: self::PAGE_NAME);
    }

    public function save()
    {
        $validated = $this->validate($this->rules());
        $payload = $validated['form'];

        DB::transaction(function () use ($payload): void {
            if ((bool) $payload['is_default']) {
                CustomerGroup::query()->update(['is_default' => false]);
            }

            $group = CustomerGroup::query()->updateOrCreate(
                ['id' => $this->editingId],
                [
                    'code' => trim((string) $payload['code']),
                    'name' => trim((string) $payload['name']),
                    'description' => trim((string) ($payload['description'] ?? '')) ?: null,
                    'is_active' => (bool) $payload['is_active'],
                    'is_default' => (bool) $payload['is_default'],
                    'sort_order' => (int) $payload['sort_order'],
                ]
            );

            if (! CustomerGroup::query()->where('is_default', true)->exists()) {
                $group->update(['is_default' => true]);
            }

            activity('admin_users')
                ->performedOn($group)
                ->causedBy(auth()->user())
                ->event($this->editingId ? 'updated' : 'created')
                ->withProperties([
                    'group_id' => $group->id,
                    'code' => $group->code,
                    'name' => $group->name,
                    'is_active' => $group->is_active,
                    'is_default' => $group->is_default,
                ])
                ->log($this->editingId ? 'Customer group updated' : 'Customer group created');
        });

        $message = $this->editingId ? __('Group updated.') : __('Group created.');

        if ($this->editPage || $this->createPage) {
            return redirect()->route('admin.users.groups', $this->listRouteParameters())->with('notify', [
                'type' => 'success',
                'message' => $message,
            ]);
        }

        $this->dispatch('notify', type: 'success', message: $message);
        $this->resetForm();

        return null;
    }

    public function edit(int $groupId): void
    {
        $group = CustomerGroup::query()->findOrFail($groupId);

        $this->editingId = $group->id;
        $this->form = [
            'code' => (string) $group->code,
            'name' => (string) $group->name,
            'description' => (string) ($group->description ?? ''),
            'is_active' => (bool) $group->is_active,
            'is_default' => (bool) $group->is_default,
            'sort_order' => (int) $group->sort_order,
        ];
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function delete(int $groupId): void
    {
        $group = CustomerGroup::query()->findOrFail($groupId);
        $wasDefault = (bool) $group->is_default;

        $group->delete();

        if ($wasDefault) {
            CustomerGroup::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(1)
                ->update(['is_default' => true]);
        }

        activity('admin_users')
            ->causedBy(auth()->user())
            ->event('deleted')
            ->withProperties([
                'group_id' => $groupId,
                'code' => $group->code,
                'name' => $group->name,
            ])
            ->log('Customer group deleted');

        if ($this->editingId === $groupId) {
            $this->resetForm();
        }

        $this->dispatch('notify', type: 'success', message: __('Group deleted.'));
    }

    public function toggleActive(int $groupId): void
    {
        $group = CustomerGroup::query()->findOrFail($groupId);
        $group->update(['is_active' => ! $group->is_active]);

        $this->dispatch('notify', type: 'info', message: $group->is_active ? __('Group activated.') : __('Group deactivated.'));
    }

    public function makeDefault(int $groupId): void
    {
        DB::transaction(function () use ($groupId): void {
            CustomerGroup::query()->update(['is_default' => false]);
            CustomerGroup::query()->whereKey($groupId)->update(['is_default' => true]);
        });

        $this->dispatch('notify', type: 'success', message: __('Default group updated.'));
    }

    public function render()
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $rows = CustomerGroup::query()
            ->withCount('users')
            ->when($this->search !== '', function (Builder $query): void {
                $query->where(function (Builder $q): void {
                    $q->where('code', 'like', '%'.$this->search.'%')
                        ->orWhere('name', 'like', '%'.$this->search.'%')
                        ->orWhere('description', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($perPage, ['*'], self::PAGE_NAME);

        return view('livewire.admin.user.group-manager', [
            'rows' => $rows,
            'perPage' => $perPage,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.code' => ['required', 'string', 'max:80', Rule::unique('customer_groups', 'code')->ignore($this->editingId)],
            'form.name' => ['required', 'string', 'max:120'],
            'form.description' => ['nullable', 'string'],
            'form.is_active' => ['boolean'],
            'form.is_default' => ['boolean'],
            'form.sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ];
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'code' => '',
            'name' => '',
            'description' => '',
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    /** @return array<string, int|string> */
    private function listRouteParameters(): array
    {
        return array_filter([
            'search' => $this->search !== '' ? $this->search : null,
            self::PAGE_NAME => $this->returnPage > 1 ? $this->returnPage : null,
        ], static fn (int|string|null $value): bool => $value !== null);
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();
        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('users.groups.manage')),
            403
        );
    }
}
