<?php

namespace App\Livewire\Admin\Catalog\Option;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionTranslation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?int $optionId = null;

    public array $form = [
        'code' => '',
        'type' => Option::TYPE_SELECT,
        'is_active' => true,
        'show_on_product_page' => true,
        'sort_order' => 0,
        'payload_text' => '',
        'locale' => 'en',
        'name' => '',
        'slug' => '',
        'description' => '',
        'translation_payload_text' => '',
    ];

    public function mount(?int $optionId = null): void
    {
        $this->form['locale'] = (string) (request()->query('locale') ?: config('app.locale', 'en'));

        if ($optionId) {
            $this->optionId = $optionId;
            $this->loadOption();
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
        $wasEditing = (bool) $this->optionId;

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
            $payload = (array) ($payload ?? []);
            $payload[Option::PAYLOAD_SHOW_ON_PRODUCT_PAGE] = (bool) $validated['form']['show_on_product_page'];

            $optionData = [
                'code' => trim((string) $validated['form']['code']),
                'type' => (string) $validated['form']['type'],
                'is_active' => (bool) $validated['form']['is_active'],
                'sort_order' => (int) $validated['form']['sort_order'],
                'payload' => $payload,
                'updated_by' => $userId,
            ];

            if ($this->optionId) {
                $option = Option::query()->findOrFail($this->optionId);
                $option->fill($optionData)->save();
            } else {
                $option = Option::query()->create($optionData + ['created_by' => $userId]);
                $this->optionId = $option->id;
            }

            $option->translations()->updateOrCreate(
                ['locale' => $validated['form']['locale']],
                [
                    'name' => $validated['form']['name'],
                    'slug' => $validated['form']['slug'],
                    'description' => $validated['form']['description'] ?: null,
                    'payload' => $translationPayload,
                ]
            );

            activity('catalog_options')
                ->performedOn($option)
                ->causedBy(auth()->user())
                ->event($wasEditing ? 'updated' : 'created')
                ->withProperties([
                    'locale' => $validated['form']['locale'],
                    'slug' => $validated['form']['slug'],
                    'type' => $validated['form']['type'],
                ])
                ->log('Option saved');
        });

        $message = $wasEditing ? __('Option updated.') : __('Option created.');

        return redirect()
            ->route('admin.options', ['locale' => $this->form['locale']])
            ->with('notify', [
                'type' => 'success',
                'message' => $message,
            ]);
    }

    public function backToList()
    {
        return redirect()->route('admin.options', ['locale' => $this->form['locale']]);
    }

    /**
     * @return array<int, string>
     */
    public function getTypeOptionsProperty(): array
    {
        return Option::availableTypes();
    }

    public function render()
    {
        return view('livewire.admin.catalog.option.form', [
            'isEdit' => (bool) $this->optionId,
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
                Rule::unique('catalog_options', 'code')->ignore($this->optionId),
            ],
            'form.type' => ['required', Rule::in(Option::availableTypes())],
            'form.is_active' => ['boolean'],
            'form.show_on_product_page' => ['boolean'],
            'form.sort_order' => ['nullable', 'integer', 'min:0'],
            'form.payload_text' => ['nullable', 'string'],

            'form.locale' => ['required', 'string', 'max:12'],
            'form.name' => ['required', 'string', 'max:255'],
            'form.slug' => [
                'required',
                'string',
                'max:191',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('catalog_option_translations', 'slug')
                    ->where(fn ($q) => $q->where('locale', $this->form['locale']))
                    ->ignore($this->optionId, 'option_id'),
            ],
            'form.description' => ['nullable', 'string'],
            'form.translation_payload_text' => ['nullable', 'string'],
        ];
    }

    private function loadOption(): void
    {
        if (!$this->optionId) {
            return;
        }

        $option = Option::query()
            ->with('translations')
            ->findOrFail($this->optionId);

        $preferredLocale = $this->form['locale'] ?: config('app.locale', 'en');
        $translation = $option->translations->firstWhere('locale', $preferredLocale)
            ?? $option->translations->firstWhere('locale', config('app.locale', 'en'))
            ?? $option->translations->first();

        $this->form['code'] = $option->code;
        $this->form['type'] = $option->type;
        $this->form['is_active'] = (bool) $option->is_active;
        $this->form['show_on_product_page'] = $option->showsOnProductPage();
        $this->form['sort_order'] = (int) $option->sort_order;
        $this->form['payload_text'] = $option->payload
            ? json_encode($option->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';

        if ($translation) {
            $this->form['locale'] = $translation->locale;
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
        if (!$this->optionId) {
            $this->clearTranslationFields();
            return;
        }

        $translation = OptionTranslation::query()
            ->where('option_id', $this->optionId)
            ->where('locale', $this->form['locale'])
            ->first();

        if (!$translation) {
            $this->clearTranslationFields();
            return;
        }

        $this->form['name'] = $translation->name;
        $this->form['slug'] = $translation->slug;
        $this->form['description'] = $translation->description ?? '';
        $this->form['translation_payload_text'] = $translation->payload
            ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
    }

    private function clearTranslationFields(): void
    {
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

        if (!is_array($decoded)) {
            $this->addError($field, __('JSON payload must decode to object/array.'));
            $this->dispatch('notify', type: 'danger', message: __('JSON payload must decode to object/array.'));
            return false;
        }

        return $decoded;
    }
}
