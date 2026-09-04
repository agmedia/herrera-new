<?php

namespace App\Livewire\Admin\Catalog\Manufacturer;

use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Manufacturer\ManufacturerTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?int $manufacturerId = null;

    public array $form = [
        'code' => '',
        'is_active' => true,
        'is_featured' => false,
        'sort_order' => 0,
        'payload_text' => '',
        'locale' => 'en',
        'name' => '',
        'slug' => '',
        'description' => '',
        'meta_title' => '',
        'meta_description' => '',
        'translation_payload_text' => '',
    ];

    public function mount(?int $manufacturerId = null): void
    {
        $this->form['locale'] = (string) (request()->query('locale') ?: config('app.locale', 'en'));

        if ($manufacturerId) {
            $this->manufacturerId = $manufacturerId;
            $this->loadManufacturer();
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
        $wasEditing = (bool) $this->manufacturerId;

        $payload = $this->decodeJsonField('form.payload_text');
        if ($payload === false) {
            return null;
        }

        $translationPayload = $this->decodeJsonField('form.translation_payload_text');
        if ($translationPayload === false) {
            return null;
        }

        $userId = auth()->id();

        DB::transaction(function () use ($validated, $payload, $translationPayload, $userId, $wasEditing): void {
            $manufacturerData = [
                'code' => trim((string) $validated['form']['code']),
                'is_active' => (bool) $validated['form']['is_active'],
                'is_featured' => (bool) $validated['form']['is_featured'],
                'sort_order' => (int) $validated['form']['sort_order'],
                'payload' => $payload,
                'updated_by' => $userId,
            ];

            if ($this->manufacturerId) {
                $manufacturer = Manufacturer::query()->findOrFail($this->manufacturerId);
                $manufacturer->fill($manufacturerData)->save();
            } else {
                $manufacturer = Manufacturer::query()->create($manufacturerData + ['created_by' => $userId]);
                $this->manufacturerId = $manufacturer->id;
            }

            $manufacturer->translations()->updateOrCreate(
                ['locale' => $validated['form']['locale']],
                [
                    'name' => $validated['form']['name'],
                    'slug' => $validated['form']['slug'],
                    'description' => $validated['form']['description'] ?: null,
                    'meta_title' => $validated['form']['meta_title'] ?: null,
                    'meta_description' => $validated['form']['meta_description'] ?: null,
                    'payload' => $translationPayload,
                ]
            );

            activity('catalog_manufacturers')
                ->performedOn($manufacturer)
                ->causedBy(auth()->user())
                ->event($wasEditing ? 'updated' : 'created')
                ->withProperties([
                    'locale' => $validated['form']['locale'],
                    'slug' => $validated['form']['slug'],
                    'is_featured' => (bool) $validated['form']['is_featured'],
                ])
                ->log('Manufacturer saved');
        });

        $message = $wasEditing ? __('Manufacturer updated.') : __('Manufacturer created.');

        return redirect()
            ->route('admin.manufacturers', ['locale' => $this->form['locale']])
            ->with('notify', [
                'type' => 'success',
                'message' => $message,
            ]);
    }

    public function backToList()
    {
        return redirect()->route('admin.manufacturers', ['locale' => $this->form['locale']]);
    }

    public function render()
    {
        return view('livewire.admin.catalog.manufacturer.form', [
            'isEdit' => (bool) $this->manufacturerId,
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
                Rule::unique('catalog_manufacturers', 'code')->ignore($this->manufacturerId),
            ],
            'form.is_active' => ['boolean'],
            'form.is_featured' => ['boolean'],
            'form.sort_order' => ['nullable', 'integer', 'min:0'],
            'form.payload_text' => ['nullable', 'string'],

            'form.locale' => ['required', 'string', 'max:12'],
            'form.name' => ['required', 'string', 'max:255'],
            'form.slug' => [
                'required',
                'string',
                'max:191',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('catalog_manufacturer_translations', 'slug')
                    ->where(fn ($q) => $q->where('locale', $this->form['locale']))
                    ->ignore($this->manufacturerId, 'manufacturer_id'),
            ],
            'form.description' => ['nullable', 'string'],
            'form.meta_title' => ['nullable', 'string', 'max:255'],
            'form.meta_description' => ['nullable', 'string'],
            'form.translation_payload_text' => ['nullable', 'string'],
        ];
    }

    private function loadManufacturer(): void
    {
        if (!$this->manufacturerId) {
            return;
        }

        $manufacturer = Manufacturer::query()
            ->with('translations')
            ->findOrFail($this->manufacturerId);

        $preferredLocale = $this->form['locale'] ?: config('app.locale', 'en');
        $translation = $manufacturer->translations->firstWhere('locale', $preferredLocale)
            ?? $manufacturer->translations->firstWhere('locale', config('app.locale', 'en'))
            ?? $manufacturer->translations->first();

        $this->form['code'] = $manufacturer->code;
        $this->form['is_active'] = (bool) $manufacturer->is_active;
        $this->form['is_featured'] = (bool) $manufacturer->is_featured;
        $this->form['sort_order'] = (int) $manufacturer->sort_order;
        $this->form['payload_text'] = $manufacturer->payload
            ? json_encode($manufacturer->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';

        if ($translation) {
            $this->form['locale'] = $translation->locale;
            $this->form['name'] = $translation->name;
            $this->form['slug'] = $translation->slug;
            $this->form['description'] = $translation->description ?? '';
            $this->form['meta_title'] = $translation->meta_title ?? '';
            $this->form['meta_description'] = $translation->meta_description ?? '';
            $this->form['translation_payload_text'] = $translation->payload
                ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : '';
        }
    }

    private function loadTranslationForLocale(): void
    {
        if (!$this->manufacturerId) {
            $this->clearTranslationFields();
            return;
        }

        $translation = ManufacturerTranslation::query()
            ->where('manufacturer_id', $this->manufacturerId)
            ->where('locale', $this->form['locale'])
            ->first();

        if (!$translation) {
            $this->clearTranslationFields();
            return;
        }

        $this->form['name'] = $translation->name;
        $this->form['slug'] = $translation->slug;
        $this->form['description'] = $translation->description ?? '';
        $this->form['meta_title'] = $translation->meta_title ?? '';
        $this->form['meta_description'] = $translation->meta_description ?? '';
        $this->form['translation_payload_text'] = $translation->payload
            ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
    }

    private function clearTranslationFields(): void
    {
        $this->form['name'] = '';
        $this->form['slug'] = '';
        $this->form['description'] = '';
        $this->form['meta_title'] = '';
        $this->form['meta_description'] = '';
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

        if (!is_array($decoded)) {
            $this->addError($field, __('JSON payload must decode to object/array.'));
            $this->dispatch('notify', type: 'danger', message: __('JSON payload must decode to object/array.'));
            return false;
        }

        return $decoded;
    }
}
