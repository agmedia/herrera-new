<?php

namespace App\Livewire\Admin\Catalog\Attribute;

use App\Models\Catalog\Attribute\Attribute;
use App\Models\Catalog\Attribute\AttributeGroup;
use App\Models\Catalog\Attribute\AttributeTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Form extends Component
{
    #[Locked]
    public ?int $attributeId = null;

    #[Locked]
    public ?int $groupId = null;

    public array $form = [
        'code' => '',
        'group_code' => '',
        'type' => Attribute::TYPE_SELECT,
        'is_active' => true,
        'sort_order' => 0,
        'payload_text' => '',
        'locale' => 'en',
        'group_name' => '',
        'name' => '',
        'slug' => '',
        'description' => '',
        'translation_payload_text' => '',
    ];

    public function mount(?int $attributeId = null, ?int $groupId = null): void
    {
        $this->form['locale'] = (string) (request()->query('locale') ?: config('app.locale', 'en'));

        if ($attributeId) {
            $this->attributeId = $attributeId;
            $this->loadAttribute();
        } elseif ($groupId) {
            $this->groupId = $groupId;
            $this->loadGroupDefaults();
        } else {
            abort(404);
        }
    }

    public function updatedFormLocale(): void
    {
        $this->loadTranslationForLocale();
    }

    public function generateSlug(): void
    {
        $name = trim((string) $this->form['name']);
        if ($name !== '') {
            $this->form['slug'] = Str::slug($name);
        }
    }

    public function save()
    {
        $validated = $this->validate($this->rules());
        $wasEditing = (bool) $this->attributeId;

        $payload = $this->decodeJsonField('form.payload_text');
        if ($payload === false) {
            return null;
        }
        $payload = $this->preserveManagedPayload($payload);

        $translationPayload = $this->decodeJsonField('form.translation_payload_text');
        if ($translationPayload === false) {
            return null;
        }

        $userId = auth()->id();
        $group = $this->groupForSave();
        $groupName = $this->localizedGroupName($group, (string) $validated['form']['locale']);

        DB::transaction(function () use ($validated, $payload, $translationPayload, $userId, $wasEditing, $group, $groupName): void {
            $attributeData = [
                'attribute_group_id' => $group->id,
                'code' => trim((string) $validated['form']['code']),
                'group_code' => $group->code,
                'type' => $group->type,
                'is_active' => (bool) $validated['form']['is_active'],
                'sort_order' => (int) $validated['form']['sort_order'],
                'payload' => $payload,
                'updated_by' => $userId,
            ];

            if ($this->attributeId) {
                $attribute = Attribute::query()->findOrFail($this->attributeId);
                $attribute->fill($attributeData)->save();
            } else {
                $attribute = Attribute::query()->create($attributeData + ['created_by' => $userId]);
                $this->attributeId = $attribute->id;
            }

            $attribute->translations()->updateOrCreate(
                ['locale' => $validated['form']['locale']],
                [
                    'group_name' => $groupName,
                    'name' => $validated['form']['name'],
                    'slug' => $validated['form']['slug'],
                    'description' => $validated['form']['description'] ?: null,
                    'payload' => $translationPayload,
                ]
            );

            activity('catalog_attributes')
                ->performedOn($attribute)
                ->causedBy(auth()->user())
                ->event($wasEditing ? 'updated' : 'created')
                ->withProperties([
                    'locale' => $validated['form']['locale'],
                    'group_code' => $group->code,
                    'group_name' => $groupName,
                    'slug' => $validated['form']['slug'],
                ])
                ->log('Attribute saved');
        });

        $message = $wasEditing ? __('Attribute updated.') : __('Attribute created.');

        return redirect()
            ->route('admin.attributes.groups.show', [
                'attributeGroup' => $this->groupId,
                'locale' => $this->form['locale'],
            ])
            ->with('notify', [
                'type' => 'success',
                'message' => $message,
            ]);
    }

    public function backToList()
    {
        return redirect()->route('admin.attributes.groups.show', [
            'attributeGroup' => $this->groupId,
            'locale' => $this->form['locale'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function getTypeOptionsProperty(): array
    {
        return Attribute::availableTypes();
    }

    public function render()
    {
        $attribute = $this->attributeId
            ? Attribute::query()->findOrFail($this->attributeId)
            : null;
        $group = $attribute
            ? $this->storedGroup($attribute)
            : ($this->groupId
                ? AttributeGroup::query()->with('translations')->findOrFail($this->groupId)
                : null);

        if ($group) {
            $this->groupId = (int) $group->id;
        }

        return view('livewire.admin.catalog.attribute.form', [
            'isEdit' => (bool) $this->attributeId,
            'group' => $group,
            'attributeSource' => $attribute?->sourceCode() ?? '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.code' => [
                'required',
                'string',
                'max:120',
                Rule::unique('catalog_attributes', 'code')->ignore($this->attributeId),
            ],
            'form.is_active' => ['boolean'],
            'form.sort_order' => ['nullable', 'integer', 'min:0'],
            'form.payload_text' => ['nullable', 'string'],

            'form.locale' => ['required', 'string', 'max:12'],
            'form.name' => ['required', 'string', 'max:5000'],
            'form.slug' => [
                'required',
                'string',
                'max:191',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('catalog_attribute_translations', 'slug')
                    ->where(fn ($q) => $q->where('locale', $this->form['locale']))
                    ->ignore($this->attributeId, 'attribute_id'),
            ],
            'form.description' => ['nullable', 'string'],
            'form.translation_payload_text' => ['nullable', 'string'],
        ];
    }

    private function loadAttribute(): void
    {
        if (! $this->attributeId) {
            return;
        }

        $attribute = Attribute::query()
            ->with(['translations', 'group.translations'])
            ->findOrFail($this->attributeId);

        $this->groupId = $attribute->attribute_group_id;
        if (! $this->groupId) {
            $group = AttributeGroup::query()->where('code', $attribute->group_code)->firstOrFail();
            $this->groupId = $group->id;
            $attribute->forceFill(['attribute_group_id' => $group->id])->save();
        }

        $preferredLocale = $this->form['locale'] ?: config('app.locale', 'en');
        $translation = $attribute->translations->firstWhere('locale', $preferredLocale)
            ?? $attribute->translations->firstWhere('locale', config('app.locale', 'en'))
            ?? $attribute->translations->first();

        $this->form['code'] = $attribute->code;
        $this->form['group_code'] = $attribute->group_code;
        $this->form['type'] = $attribute->type;
        $this->form['is_active'] = (bool) $attribute->is_active;
        $this->form['sort_order'] = (int) $attribute->sort_order;
        $this->form['payload_text'] = $attribute->payload
            ? json_encode($attribute->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';

        if ($translation) {
            $this->form['locale'] = $translation->locale;
            $this->form['group_name'] = $translation->group_name;
            $this->form['name'] = $translation->name;
            $this->form['slug'] = $translation->slug;
            $this->form['description'] = $translation->description ?? '';
            $this->form['translation_payload_text'] = $translation->payload
                ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : '';
        }
    }

    private function loadTranslationForLocale(): void
    {
        if (! $this->attributeId) {
            $this->clearTranslationFields();

            return;
        }

        $translation = AttributeTranslation::query()
            ->where('attribute_id', $this->attributeId)
            ->where('locale', $this->form['locale'])
            ->first();

        if (! $translation) {
            $this->clearTranslationFields();

            return;
        }

        $this->form['group_name'] = $translation->group_name;
        $this->form['name'] = $translation->name;
        $this->form['slug'] = $translation->slug;
        $this->form['description'] = $translation->description ?? '';
        $this->form['translation_payload_text'] = $translation->payload
            ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
    }

    private function loadGroupDefaults(): void
    {
        $group = AttributeGroup::query()->with('translations')->findOrFail($this->groupId);
        abort_if($group->isMsanManaged(), 403, __('M SAN attribute groups are maintained automatically.'));

        $this->form['group_code'] = $group->code;
        $this->form['type'] = $group->type;
        $this->form['group_name'] = $this->localizedGroupName($group, $this->form['locale']);
        $this->form['sort_order'] = (int) (($group->attributes()->max('sort_order') ?? -10) + 10);
    }

    private function localizedGroupName(AttributeGroup $group, string $locale): string
    {
        $group->loadMissing('translations');
        $translation = $group->translations->firstWhere('locale', $locale)
            ?? $group->translations->firstWhere('locale', config('app.locale', 'en'))
            ?? $group->translations->first();

        return (string) ($translation?->name ?? str($group->code)->replace(['_', '-'], ' ')->title());
    }

    private function groupForSave(): AttributeGroup
    {
        if (! $this->attributeId) {
            $group = AttributeGroup::query()->findOrFail($this->groupId);
            abort_if($group->isMsanManaged(), 403, __('M SAN attribute groups are maintained automatically.'));

            return $group;
        }

        $attribute = Attribute::query()->findOrFail($this->attributeId);
        $group = $this->storedGroup($attribute);
        $this->groupId = (int) $group->id;

        return $group;
    }

    private function storedGroup(Attribute $attribute): AttributeGroup
    {
        $group = $attribute->attribute_group_id
            ? AttributeGroup::query()->with('translations')->find($attribute->attribute_group_id)
            : AttributeGroup::query()->with('translations')->where('code', $attribute->group_code)->first();

        abort_unless($group, 404);

        return $group;
    }

    /**
     * @param  array<mixed>|null  $payload
     * @return array<mixed>|null
     */
    private function preserveManagedPayload(?array $payload): ?array
    {
        if (! $this->attributeId) {
            return $payload;
        }

        $attribute = Attribute::query()->findOrFail($this->attributeId);
        if (! $attribute->isMsanManaged()) {
            return $payload;
        }

        $payload ??= [];
        $currentPayload = is_array($attribute->payload) ? $attribute->payload : [];

        foreach (['source', 'source_key'] as $managedKey) {
            if (array_key_exists($managedKey, $currentPayload)) {
                $payload[$managedKey] = $currentPayload[$managedKey];
            }
        }

        return $payload;
    }

    private function clearTranslationFields(): void
    {
        $this->form['group_name'] = '';
        $this->form['name'] = '';
        $this->form['slug'] = '';
        $this->form['description'] = '';
        $this->form['translation_payload_text'] = '';
    }

    /**
     * @return array<mixed>|null|false
     */
    private function decodeJsonField(string $field): array|null|false
    {
        $value = trim((string) data_get($this, $field));
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addError($field, __('Invalid JSON payload.'));
            $this->dispatch('notify', type: 'danger', message: __('Invalid JSON payload.'));

            return false;
        }

        if (! is_array($decoded)) {
            $this->addError($field, __('JSON payload must decode to object/array.'));
            $this->dispatch('notify', type: 'danger', message: __('JSON payload must decode to object/array.'));

            return false;
        }

        return $decoded;
    }
}
