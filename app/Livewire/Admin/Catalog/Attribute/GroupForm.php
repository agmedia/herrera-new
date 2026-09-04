<?php

namespace App\Livewire\Admin\Catalog\Attribute;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Attribute\AttributeGroup;
use App\Models\Catalog\Attribute\AttributeGroupTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class GroupForm extends Component
{
    #[Locked]
    public ?int $groupId = null;

    public array $form = [
        'code' => '',
        'type' => Attribute::TYPE_SELECT,
        'sort_order' => 0,
        'locale' => 'en',
        'name' => '',
        'description' => '',
    ];

    public function mount(?int $groupId = null): void
    {
        $this->form['locale'] = (string) (request()->query('locale') ?: config('app.locale', 'en'));

        if ($groupId) {
            $this->groupId = $groupId;
            $this->loadGroup();
        }
    }

    public function updatedFormLocale(): void
    {
        $this->loadTranslationForLocale();
    }

    public function save()
    {
        $validated = $this->validate($this->rules());
        $wasEditing = (bool) $this->groupId;
        $userId = auth()->id();

        if ($this->wouldInvalidateExistingProductAssignments((string) $validated['form']['type'])) {
            $message = __('This group cannot use a single selection while an article has multiple values assigned.');

            $this->addError('form.type', $message);
            $this->dispatch('notify', type: 'warning', message: $message);

            return null;
        }

        DB::transaction(function () use ($validated, $wasEditing, $userId): void {
            $groupData = [
                'type' => (string) $validated['form']['type'],
                'sort_order' => (int) $validated['form']['sort_order'],
                'updated_by' => $userId,
            ];

            if ($this->groupId) {
                $group = AttributeGroup::query()->findOrFail($this->groupId);
                $group->fill($groupData)->save();
            } else {
                $group = AttributeGroup::query()->create($groupData + [
                    'code' => trim((string) $validated['form']['code']),
                    'created_by' => $userId,
                ]);
                $this->groupId = $group->id;
            }

            $group->translations()->updateOrCreate(
                ['locale' => $validated['form']['locale']],
                [
                    'name' => trim((string) $validated['form']['name']),
                    'description' => $validated['form']['description'] ?: null,
                    'payload' => ['manual_override' => true],
                ]
            );

            Attribute::query()->where('attribute_group_id', $group->id)->update([
                'group_code' => $group->code,
                'type' => $group->type,
            ]);

            $attributeIds = $group->attributes()->pluck('id');
            DB::table('catalog_attribute_translations')
                ->whereIn('attribute_id', $attributeIds)
                ->where('catalog_attribute_translations.locale', $validated['form']['locale'])
                ->update([
                    'group_name' => trim((string) $validated['form']['name']),
                    'updated_at' => now(),
                ]);

            activity('catalog_attributes')
                ->performedOn($group)
                ->causedBy(auth()->user())
                ->event($wasEditing ? 'group_updated' : 'group_created')
                ->withProperties([
                    'locale' => $validated['form']['locale'],
                    'code' => $group->code,
                    'name' => $validated['form']['name'],
                ])
                ->log('Attribute group saved');
        });

        return redirect()
            ->route('admin.attributes.groups.show', [
                'attributeGroup' => $this->groupId,
                'locale' => $this->form['locale'],
            ])
            ->with('notify', [
                'type' => 'success',
                'message' => $wasEditing
                    ? __('Attribute group updated.')
                    : __('Attribute group created.'),
            ]);
    }

    public function back()
    {
        if ($this->groupId) {
            return redirect()->route('admin.attributes.groups.show', [
                'attributeGroup' => $this->groupId,
                'locale' => $this->form['locale'],
            ]);
        }

        return redirect()->route('admin.attributes', ['locale' => $this->form['locale']]);
    }

    public function getTypeOptionsProperty(): array
    {
        return Attribute::availableTypes();
    }

    public function render()
    {
        return view('livewire.admin.catalog.attribute.group-form', [
            'isEdit' => (bool) $this->groupId,
        ]);
    }

    private function rules(): array
    {
        $codeRules = [
            'required',
            'string',
            'max:120',
        ];

        if (! $this->groupId) {
            $codeRules[] = 'regex:/^[a-z0-9]+(?:[a-z0-9_-]*[a-z0-9])?$/';
        }

        $codeRules[] = Rule::unique('catalog_attribute_groups', 'code')->ignore($this->groupId);

        return [
            'form.code' => $codeRules,
            'form.type' => ['required', Rule::in(Attribute::availableTypes())],
            'form.sort_order' => ['nullable', 'integer', 'min:0'],
            'form.locale' => ['required', 'string', 'max:12'],
            'form.name' => ['required', 'string', 'max:255'],
            'form.description' => ['nullable', 'string'],
        ];
    }

    private function loadGroup(): void
    {
        $group = AttributeGroup::query()->with('translations')->findOrFail($this->groupId);

        $this->form['code'] = $group->code;
        $this->form['type'] = $group->type;
        $this->form['sort_order'] = (int) $group->sort_order;

        $preferredLocale = $this->form['locale'];
        $translation = $group->translations->firstWhere('locale', $preferredLocale)
            ?? $group->translations->firstWhere('locale', config('app.locale', 'en'))
            ?? $group->translations->first();

        if ($translation) {
            $this->form['locale'] = $translation->locale;
            $this->form['name'] = $translation->name;
            $this->form['description'] = $translation->description ?? '';
        }
    }

    private function loadTranslationForLocale(): void
    {
        if (! $this->groupId) {
            $this->form['name'] = '';
            $this->form['description'] = '';

            return;
        }

        $translation = AttributeGroupTranslation::query()
            ->where('attribute_group_id', $this->groupId)
            ->where('locale', $this->form['locale'])
            ->first();

        $this->form['name'] = $translation?->name ?? '';
        $this->form['description'] = $translation?->description ?? '';
    }

    private function wouldInvalidateExistingProductAssignments(string $requestedType): bool
    {
        if (! $this->groupId || $requestedType !== Attribute::TYPE_SELECT) {
            return false;
        }

        $group = AttributeGroup::query()->findOrFail($this->groupId);

        if ($group->type !== Attribute::TYPE_MULTI) {
            return false;
        }

        $conflictingProducts = DB::table('catalog_attribute_product')
            ->join('catalog_attributes', 'catalog_attributes.id', '=', 'catalog_attribute_product.attribute_id')
            ->where('catalog_attributes.attribute_group_id', $group->id)
            ->select('catalog_attribute_product.product_id')
            ->groupBy('catalog_attribute_product.product_id')
            ->havingRaw('COUNT(DISTINCT catalog_attribute_product.attribute_id) > 1');

        return DB::query()
            ->fromSub($conflictingProducts, 'conflicting_attribute_products')
            ->exists();
    }
}
