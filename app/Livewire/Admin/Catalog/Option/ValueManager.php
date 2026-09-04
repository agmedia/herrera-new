<?php

namespace App\Livewire\Admin\Catalog\Option;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Option\OptionValueTranslation;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class ValueManager extends Component
{
    use WithFileUploads;
    use WithPagination;

    public int $optionId;

    public string $search = '';

    public int $returnPage = 1;

    public string $locale = 'en';

    public ?int $editingId = null;

    public bool $editPage = false;

    public bool $createPage = false;

    public ?TemporaryUploadedFile $swatchImageUpload = null;

    public ?string $currentSwatchImagePath = null;

    public bool $removeSwatchImage = false;

    public array $form = [
        'code' => '',
        'is_active' => true,
        'sort_order' => 0,
        'payload_text' => '',
        'name' => '',
        'slug' => '',
        'translation_payload_text' => '',
    ];

    public function mount(
        int $optionId,
        ?int $recordId = null,
        bool $editPage = false,
        bool $createPage = false,
    ): void {
        $this->optionId = $optionId;
        $this->editPage = $editPage;
        $this->createPage = $createPage;
        $this->locale = (string) (request()->query('locale') ?: config('app.locale', 'en'));
        $this->search = trim((string) request()->query('search', ''));
        $this->returnPage = max(1, (int) request()->query('page', 1));

        Option::query()->findOrFail($this->optionId);

        abort_if($this->editPage && $this->createPage, 404);

        if ($this->editPage) {
            abort_unless($recordId, 404);
            $this->edit($recordId);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLocale(): void
    {
        $this->resetPage();

        if ($this->editingId) {
            $this->loadTranslationForLocale();
        }
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

        $payload = $this->decodeJsonField('form.payload_text');
        if ($payload === false) {
            return;
        }

        $translationPayload = $this->decodeJsonField('form.translation_payload_text');
        if ($translationPayload === false) {
            return;
        }

        $existingValue = $this->editingId
            ? OptionValue::query()
                ->where('option_id', $this->optionId)
                ->findOrFail($this->editingId)
            : null;
        $oldSwatchImagePath = $this->swatchImagePathFromPayload($existingValue?->payload);
        $newSwatchImagePath = $this->swatchImageUpload instanceof TemporaryUploadedFile
            ? $this->swatchImageUpload->store('catalog/option-values/swatch', 'public')
            : null;
        $payload = $this->mergeSwatchImagePayload($payload, $newSwatchImagePath);

        $userId = auth()->id();
        $wasEditing = (bool) $this->editingId;

        DB::transaction(function () use ($validated, $payload, $translationPayload, $userId, $wasEditing): void {
            $valueData = [
                'option_id' => $this->optionId,
                'code' => trim((string) $validated['form']['code']),
                'is_active' => (bool) $validated['form']['is_active'],
                'sort_order' => (int) $validated['form']['sort_order'],
                'payload' => $payload,
                'updated_by' => $userId,
            ];

            if ($this->editingId) {
                $value = OptionValue::query()
                    ->where('option_id', $this->optionId)
                    ->findOrFail($this->editingId);

                $value->fill($valueData)->save();
            } else {
                $value = OptionValue::query()->create($valueData + ['created_by' => $userId]);
                $this->editingId = $value->id;
            }

            $value->translations()->updateOrCreate(
                ['locale' => $this->locale],
                [
                    'name' => $validated['form']['name'],
                    'slug' => $validated['form']['slug'],
                    'payload' => $translationPayload,
                ]
            );

            activity('catalog_options')
                ->performedOn($value)
                ->causedBy(auth()->user())
                ->event($wasEditing ? 'value_updated' : 'value_created')
                ->withProperties([
                    'option_id' => $this->optionId,
                    'locale' => $this->locale,
                    'slug' => $validated['form']['slug'],
                ])
                ->log('Option value saved');
        });

        $savedSwatchImagePath = $this->swatchImagePathFromPayload($payload);
        if ($this->canDeleteStoredSwatchPath($oldSwatchImagePath) && $oldSwatchImagePath !== $savedSwatchImagePath) {
            Storage::disk('public')->delete($oldSwatchImagePath);
        }

        $message = $wasEditing ? __('Value updated.') : __('Value created.');

        if ($this->editPage || $this->createPage) {
            return redirect()->route('admin.options.values', $this->listRouteParameters())->with('notify', [
                'type' => 'success',
                'message' => $message,
            ]);
        }

        $this->dispatch('notify', type: 'success', message: $message);
        $this->resetForm();

        return null;
    }

    public function edit(int $id): void
    {
        $value = OptionValue::query()
            ->where('option_id', $this->optionId)
            ->with('translations')
            ->findOrFail($id);

        $translation = $value->translations->firstWhere('locale', $this->locale)
            ?? $value->translations->firstWhere('locale', config('app.locale', 'en'))
            ?? $value->translations->first();

        $this->editingId = $value->id;
        $this->form['code'] = $value->code;
        $this->form['is_active'] = (bool) $value->is_active;
        $this->form['sort_order'] = (int) $value->sort_order;
        $this->form['payload_text'] = $value->payload
            ? json_encode($value->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
        $this->currentSwatchImagePath = $this->swatchImagePathFromPayload($value->payload);
        $this->swatchImageUpload = null;
        $this->removeSwatchImage = false;

        if ($translation) {
            $this->form['name'] = $translation->name;
            $this->form['slug'] = $translation->slug;
            $this->form['translation_payload_text'] = $translation->payload
                ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : '';
        } else {
            $this->clearTranslationFields();
        }
    }

    /** @return array<string, int|string> */
    private function listRouteParameters(): array
    {
        return array_filter([
            'option' => $this->optionId,
            'locale' => $this->locale,
            'search' => $this->search !== '' ? $this->search : null,
            'page' => $this->returnPage > 1 ? $this->returnPage : null,
        ], static fn (int|string|null $value): bool => $value !== null);
    }

    public function delete(int $id): void
    {
        $value = OptionValue::query()
            ->where('option_id', $this->optionId)
            ->findOrFail($id);

        activity('catalog_options')
            ->performedOn($value)
            ->causedBy(auth()->user())
            ->event('value_deleted')
            ->withProperties(['option_id' => $this->optionId])
            ->log('Option value deleted');

        $value->delete();

        if ($this->editingId === $id) {
            $this->resetForm();
        }

        $this->dispatch('notify', type: 'success', message: __('Value deleted.'));
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    public function updatedSwatchImageUpload(): void
    {
        $this->removeSwatchImage = false;
        $this->validateOnly('swatchImageUpload', [
            'swatchImageUpload' => ['nullable', 'image', 'max:5120'],
        ]);
    }

    public function clearSwatchImage(): void
    {
        $this->swatchImageUpload = null;
        $this->currentSwatchImagePath = null;
        $this->removeSwatchImage = true;
    }

    public function getSwatchPreviewUrlProperty(): ?string
    {
        if ($this->swatchImageUpload instanceof TemporaryUploadedFile) {
            return $this->swatchImageUpload->temporaryUrl();
        }

        return $this->swatchPathToUrl($this->currentSwatchImagePath);
    }

    public function render()
    {
        $perPage = app(SystemSettingsService::class)->getInt(
            'admin_items_per_page',
            (int) config('admin_ui.pagination.admin_items_per_page', 20),
            5,
            200
        );

        $option = Option::query()
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
            ])
            ->findOrFail($this->optionId);

        $rows = OptionValue::query()
            ->where('option_id', $this->optionId)
            ->with([
                'translations' => fn ($q) => $q->where('locale', $this->locale),
            ])
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($q): void {
                    $q->where('code', 'like', '%'.$this->search.'%')
                        ->orWhereHas('translations', function ($tq): void {
                            $tq->where('name', 'like', '%'.$this->search.'%')
                                ->orWhere('slug', 'like', '%'.$this->search.'%');
                        });
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage);

        return view('livewire.admin.catalog.option.values', [
            'option' => $option,
            'rows' => $rows,
            'perPage' => $perPage,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'form.code' => [
                'required',
                'string',
                'max:120',
                Rule::unique('catalog_option_values', 'code')
                    ->where(fn ($q) => $q->where('option_id', $this->optionId))
                    ->ignore($this->editingId),
            ],
            'form.is_active' => ['boolean'],
            'form.sort_order' => ['nullable', 'integer', 'min:0'],
            'form.payload_text' => ['nullable', 'string'],
            'swatchImageUpload' => ['nullable', 'image', 'max:5120'],
            'form.name' => ['required', 'string', 'max:255'],
            'form.slug' => [
                'required',
                'string',
                'max:191',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('catalog_option_value_translations', 'slug')
                    ->where(fn ($q) => $q->where('locale', $this->locale))
                    ->ignore($this->editingId, 'option_value_id'),
            ],
            'form.translation_payload_text' => ['nullable', 'string'],
        ];
    }

    private function loadTranslationForLocale(): void
    {
        if (! $this->editingId) {
            $this->clearTranslationFields();

            return;
        }

        $translation = OptionValueTranslation::query()
            ->where('option_value_id', $this->editingId)
            ->where('locale', $this->locale)
            ->first();

        if (! $translation) {
            $this->clearTranslationFields();

            return;
        }

        $this->form['name'] = $translation->name;
        $this->form['slug'] = $translation->slug;
        $this->form['translation_payload_text'] = $translation->payload
            ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->swatchImageUpload = null;
        $this->currentSwatchImagePath = null;
        $this->removeSwatchImage = false;
        $this->form = [
            'code' => '',
            'is_active' => true,
            'sort_order' => 0,
            'payload_text' => '',
            'name' => '',
            'slug' => '',
            'translation_payload_text' => '',
        ];
    }

    private function clearTranslationFields(): void
    {
        $this->form['name'] = '';
        $this->form['slug'] = '';
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

    /**
     * @param  array<mixed>|null  $payload
     * @return array<mixed>|null
     */
    private function mergeSwatchImagePayload(?array $payload, ?string $newSwatchImagePath): ?array
    {
        $payload = is_array($payload) ? $payload : [];

        if ($this->removeSwatchImage) {
            unset($payload['swatch_image_path']);
        }

        if (is_string($newSwatchImagePath) && $newSwatchImagePath !== '') {
            $payload['swatch_image_path'] = $newSwatchImagePath;
        }

        return $payload === [] ? null : $payload;
    }

    /**
     * @param  array<mixed>|null  $payload
     */
    private function swatchImagePathFromPayload(?array $payload): string
    {
        $path = trim((string) data_get($payload, 'swatch_image_path', ''));

        return $path;
    }

    private function swatchPathToUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '//', '/'])) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function canDeleteStoredSwatchPath(?string $path): bool
    {
        $path = trim((string) $path);

        if ($path === '') {
            return false;
        }

        return ! Str::startsWith($path, ['http://', 'https://', '//', '/']);
    }
}
