<?php

namespace App\Livewire\Admin\Catalog\Category;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Category\CategoryTranslation;
use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\Language;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Form extends Component
{
    public ?int $categoryId = null;

    public array $form = [
        'scope' => Category::SCOPE_CATALOG,
        'parent_id' => null,
        'code' => '',
        'is_active' => true,
        'show_in_menu' => true,
        'catalog_show_filters' => true,
        'catalog_show_products' => true,
        'shipping_labels' => [],
        'sort_order' => 0,
        'starts_at' => '',
        'ends_at' => '',
        'payload_text' => '',
        'locale' => 'en',
        'name' => '',
        'slug' => '',
        'description' => '',
        'meta_title' => '',
        'meta_description' => '',
        'translation_payload_text' => '',
    ];

    public function mount(?int $categoryId = null): void
    {
        $this->form['locale'] = $this->resolveDefaultLocale();

        $requestedScope = (string) (request()->query('scope') ?: $this->form['scope']);
        if (in_array($requestedScope, Category::availableScopes(), true)) {
            $this->form['scope'] = $requestedScope;
        }

        $requestedLocale = (string) request()->query('locale', $this->form['locale']);
        if (in_array($requestedLocale, $this->localeOptions, true)) {
            $this->form['locale'] = $requestedLocale;
        }

        if ($categoryId) {
            $this->categoryId = $categoryId;
            $this->loadCategory();
        }
    }

    public function updatedFormLocale(): void
    {
        if (! in_array($this->form['locale'], $this->localeOptions, true)) {
            $this->form['locale'] = $this->resolveDefaultLocale();
        }

        $this->loadTranslationForLocale();
    }

    public function updatedFormScope(): void
    {
        if (! in_array($this->form['scope'], Category::availableScopes(), true)) {
            $this->form['scope'] = Category::SCOPE_CATALOG;
        }

        if ($this->form['parent_id']) {
            $validParent = Category::query()
                ->whereKey((int) $this->form['parent_id'])
                ->where('scope', $this->form['scope'])
                ->exists();

            if (! $validParent) {
                $this->form['parent_id'] = null;
            }
        }

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
        $wasEditing = (bool) $this->categoryId;

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
            $scope = $validated['form']['scope'];
            $parentId = $validated['form']['parent_id'] ? (int) $validated['form']['parent_id'] : null;
            $payload = is_array($payload) ? $payload : [];

            if ($scope === Category::SCOPE_CATALOG) {
                $payload[Category::PAYLOAD_SHOW_FILTERS] = (bool) $validated['form']['catalog_show_filters'];
                $payload[Category::PAYLOAD_SHOW_PRODUCTS] = (bool) $validated['form']['catalog_show_products'];
                $shippingLabels = array_values($validated['form']['shipping_labels'] ?? []);
                if ($shippingLabels !== []) {
                    $payload[Category::PAYLOAD_SHIPPING_LABELS] = $shippingLabels;
                } else {
                    unset($payload[Category::PAYLOAD_SHIPPING_LABELS]);
                }
            } else {
                unset(
                    $payload[Category::PAYLOAD_SHOW_FILTERS],
                    $payload[Category::PAYLOAD_SHOW_PRODUCTS],
                    $payload[Category::PAYLOAD_SHIPPING_LABELS]
                );
            }

            $categoryData = [
                'scope' => $scope,
                'code' => $validated['form']['code'] ?: null,
                'is_active' => (bool) $validated['form']['is_active'],
                'show_in_menu' => (bool) $validated['form']['show_in_menu'],
                'sort_order' => (int) $validated['form']['sort_order'],
                'starts_at' => $validated['form']['starts_at'] ?: null,
                'ends_at' => $validated['form']['ends_at'] ?: null,
                'payload' => $payload === [] ? null : $payload,
                'updated_by' => $userId,
            ];

            if ($this->categoryId) {
                $category = Category::query()->findOrFail($this->categoryId);
                $scopeChanged = $category->scope !== $scope;

                if ($scopeChanged && $category->children()->exists()) {
                    throw ValidationException::withMessages([
                        'form.scope' => __('Scope change requires category without children.'),
                    ]);
                }

                $previousParentId = $category->parent_id ? (int) $category->parent_id : null;
                $category->fill($categoryData);

                if ($parentId !== $previousParentId || $scopeChanged) {
                    if ($parentId === null) {
                        $category->saveAsRoot();
                    } else {
                        $parent = Category::query()
                            ->whereKey($parentId)
                            ->where('scope', $scope)
                            ->firstOrFail();

                        if ($parent->isDescendantOf($category) || $parent->id === $category->id) {
                            throw ValidationException::withMessages([
                                'form.parent_id' => __('Invalid parent category selection.'),
                            ]);
                        }

                        $category->appendToNode($parent)->save();
                    }
                } else {
                    $category->save();
                }

                $category->translations()->update(['scope' => $scope]);
            } else {
                $category = new Category($categoryData + ['created_by' => $userId]);

                if ($parentId) {
                    $parent = Category::query()
                        ->whereKey($parentId)
                        ->where('scope', $scope)
                        ->firstOrFail();

                    $category->appendToNode($parent)->save();
                } else {
                    $category->saveAsRoot();
                }

                $this->categoryId = $category->id;
            }

            $category->translations()->updateOrCreate(
                ['locale' => $validated['form']['locale']],
                [
                    'scope' => $scope,
                    'name' => $validated['form']['name'],
                    'slug' => $validated['form']['slug'],
                    'description' => $validated['form']['description'] ?: null,
                    'meta_title' => $validated['form']['meta_title'] ?: null,
                    'meta_description' => $validated['form']['meta_description'] ?: null,
                    'payload' => $translationPayload,
                ]
            );

            activity('catalog_categories')
                ->performedOn($category)
                ->causedBy(auth()->user())
                ->event($wasEditing ? 'updated' : 'created')
                ->withProperties([
                    'scope' => $scope,
                    'locale' => $validated['form']['locale'],
                    'slug' => $validated['form']['slug'],
                ])
                ->log('Category saved');
        });

        $message = $wasEditing ? __('Category updated.') : __('Category created.');

        return redirect()
            ->route('admin.categories', [
                'scope' => $this->form['scope'],
                'locale' => $this->form['locale'],
            ])
            ->with('notify', [
                'type' => 'success',
                'message' => $message,
            ]);
    }

    public function backToList()
    {
        return redirect()->route('admin.categories', [
            'scope' => $this->form['scope'],
            'locale' => $this->form['locale'],
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function getScopeOptionsProperty(): array
    {
        return Category::availableScopes();
    }

    /**
     * @return array<int, string>
     */
    public function getLocaleOptionsProperty(): array
    {
        $locales = Language::query()
            ->orderBy('sort_order')
            ->pluck('code')
            ->map(fn ($code): string => (string) $code)
            ->all();

        return array_values(array_unique([
            (string) config('app.locale', 'en'),
            ...$locales,
        ]));
    }

    public function getParentOptionsProperty(): Collection
    {
        $query = Category::query()
            ->where('scope', $this->form['scope'])
            ->withDepth()
            ->defaultOrder()
            ->with([
                'translations' => fn ($q) => $q
                    ->where('scope', $this->form['scope'])
                    ->where('locale', $this->form['locale']),
            ]);

        if ($this->categoryId) {
            $excludeIds = Category::query()
                ->descendantsAndSelf($this->categoryId)
                ->pluck('id');

            $query->whereNotIn('id', $excludeIds);
        }

        return $query->get();
    }

    public function render()
    {
        return view('livewire.admin.catalog.category.form', [
            'isEdit' => (bool) $this->categoryId,
            'shippingLabelOptions' => Product::shippingLabelOptions(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.scope' => ['required', 'string', Rule::in(Category::availableScopes())],
            'form.parent_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where(fn ($q) => $q->where('scope', $this->form['scope'])),
            ],
            'form.code' => [
                'nullable',
                'string',
                'max:120',
                Rule::unique('categories', 'code')
                    ->where(fn ($q) => $q->where('scope', $this->form['scope']))
                    ->ignore($this->categoryId),
            ],
            'form.is_active' => ['boolean'],
            'form.show_in_menu' => ['boolean'],
            'form.catalog_show_filters' => ['boolean'],
            'form.catalog_show_products' => ['boolean'],
            'form.shipping_labels' => ['nullable', 'array'],
            'form.shipping_labels.*' => [Rule::in(array_keys(Product::shippingLabelOptions()))],
            'form.sort_order' => ['nullable', 'integer', 'min:0'],
            'form.starts_at' => ['nullable', 'date'],
            'form.ends_at' => ['nullable', 'date', 'after_or_equal:form.starts_at'],
            'form.payload_text' => ['nullable', 'string'],

            'form.locale' => ['required', Rule::in($this->localeOptions)],
            'form.name' => ['required', 'string', 'max:255'],
            'form.slug' => [
                'required',
                'string',
                'max:191',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('category_translations', 'slug')
                    ->where(fn ($q) => $q
                        ->where('scope', $this->form['scope'])
                        ->where('locale', $this->form['locale']))
                    ->ignore($this->categoryId, 'category_id'),
            ],
            'form.description' => ['nullable', 'string'],
            'form.meta_title' => ['nullable', 'string', 'max:255'],
            'form.meta_description' => ['nullable', 'string'],
            'form.translation_payload_text' => ['nullable', 'string'],
        ];
    }

    private function loadCategory(): void
    {
        if (! $this->categoryId) {
            return;
        }

        $category = Category::query()
            ->with('translations')
            ->findOrFail($this->categoryId);

        $preferredLocale = $this->form['locale'] ?: config('app.locale', 'en');
        $translation = $category->translations->firstWhere('locale', $preferredLocale)
            ?? $category->translations->firstWhere('locale', config('app.locale', 'en'))
            ?? $category->translations->first();

        $this->form['scope'] = $category->scope ?: Category::SCOPE_CATALOG;
        $this->form['parent_id'] = $category->parent_id;
        $this->form['code'] = $category->code ?? '';
        $this->form['is_active'] = (bool) $category->is_active;
        $this->form['show_in_menu'] = (bool) $category->show_in_menu;
        $this->form['sort_order'] = (int) $category->sort_order;
        $this->form['starts_at'] = $category->starts_at?->format('Y-m-d\TH:i') ?? '';
        $this->form['ends_at'] = $category->ends_at?->format('Y-m-d\TH:i') ?? '';
        $payload = is_array($category->payload) ? $category->payload : [];
        $this->form['catalog_show_filters'] = (bool) ($payload[Category::PAYLOAD_SHOW_FILTERS] ?? true);
        $this->form['catalog_show_products'] = (bool) ($payload[Category::PAYLOAD_SHOW_PRODUCTS] ?? true);
        $this->form['shipping_labels'] = is_array($payload[Category::PAYLOAD_SHIPPING_LABELS] ?? null)
            ? array_values($payload[Category::PAYLOAD_SHIPPING_LABELS])
            : [];
        unset(
            $payload[Category::PAYLOAD_SHOW_FILTERS],
            $payload[Category::PAYLOAD_SHOW_PRODUCTS],
            $payload[Category::PAYLOAD_SHIPPING_LABELS]
        );
        $this->form['payload_text'] = $payload !== []
            ? json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
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
        if (! $this->categoryId) {
            $this->clearTranslationFields();

            return;
        }

        $translation = CategoryTranslation::query()
            ->where('category_id', $this->categoryId)
            ->where('scope', $this->form['scope'])
            ->where('locale', $this->form['locale'])
            ->first();

        if (! $translation) {
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

        if (! is_array($decoded)) {
            $this->addError($field, __('JSON payload must decode to object/array.'));
            $this->dispatch('notify', type: 'danger', message: __('JSON payload must decode to object/array.'));

            return false;
        }

        return $decoded;
    }

    private function resolveDefaultLocale(): string
    {
        $default = Language::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->value('code');

        if (is_string($default) && $default !== '') {
            return $default;
        }

        return $this->localeOptions[0] ?? config('app.locale', 'en');
    }
}
