<?php

namespace App\Livewire\Admin\Content\Page;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Page\InfoPage;
use App\Models\Content\Page\InfoPageTranslation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    public ?int $pageId = null;

    public string $activeTab = 'content';

    public array $form = [
        'code' => '',
        'layout' => 'default',
        'is_active' => true,
        'show_in_footer' => false,
        'published_at' => '',
        'sort_order' => 0,
        'payload_text' => '',
        'locale' => 'en',
        'title' => '',
        'slug' => '',
        'excerpt' => '',
        'body_html' => '',
        'meta_title' => '',
        'meta_description' => '',
        'translation_payload_text' => '',
        'category_ids' => [],
    ];

    public function mount(?int $pageId = null): void
    {
        $this->form['locale'] = (string) (request()->query('locale') ?: config('app.locale', 'en'));

        if ($pageId) {
            $this->pageId = $pageId;
            $this->loadPage();
        }
    }

    public function updatedFormLocale(): void
    {
        $this->loadTranslationForLocale();
    }

    public function generateSlug(): void
    {
        $title = trim((string) $this->form['title']);
        if ($title !== '') {
            $this->form['slug'] = Str::slug($title);
        }
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, ['content', 'seo'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function save()
    {
        $validated = $this->validate($this->rules());
        $wasEditing = (bool) $this->pageId;

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
            $pageData = [
                'code' => trim((string) $validated['form']['code']),
                'layout' => trim((string) $validated['form']['layout']) !== '' ? trim((string) $validated['form']['layout']) : 'default',
                'is_active' => (bool) $validated['form']['is_active'],
                'show_in_footer' => (bool) $validated['form']['show_in_footer'],
                'published_at' => $validated['form']['published_at'] ?: null,
                'sort_order' => (int) $validated['form']['sort_order'],
                'payload' => $payload,
                'updated_by' => $userId,
            ];

            if ($this->pageId) {
                $page = InfoPage::query()->findOrFail($this->pageId);
                $page->fill($pageData)->save();
            } else {
                $page = InfoPage::query()->create($pageData + ['created_by' => $userId]);
                $this->pageId = $page->id;
            }

            $page->translations()->updateOrCreate(
                ['locale' => $validated['form']['locale']],
                [
                    'title' => $validated['form']['title'],
                    'slug' => $validated['form']['slug'],
                    'excerpt' => $validated['form']['excerpt'] ?: null,
                    'body_html' => $validated['form']['body_html'] ?: null,
                    'meta_title' => $validated['form']['meta_title'] ?: null,
                    'meta_description' => $validated['form']['meta_description'] ?: null,
                    'payload' => $translationPayload,
                ]
            );

            $syncPayload = [];
            foreach (array_values($validated['form']['category_ids'] ?? []) as $index => $categoryId) {
                $syncPayload[(int) $categoryId] = [
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                ];
            }
            $page->categories()->sync($syncPayload);

            activity('content_pages')
                ->performedOn($page)
                ->causedBy(auth()->user())
                ->event($wasEditing ? 'updated' : 'created')
                ->withProperties([
                    'locale' => $validated['form']['locale'],
                    'slug' => $validated['form']['slug'],
                    'layout' => $validated['form']['layout'],
                    'category_count' => count($syncPayload),
                ])
                ->log(__('Info page saved'));
        });

        $message = $wasEditing ? __('Info page updated.') : __('Info page created.');

        return redirect()
            ->route('admin.content.pages.index', ['locale' => $this->form['locale']])
            ->with('notify', [
                'type' => 'success',
                'message' => $message,
            ]);
    }

    public function backToList()
    {
        return redirect()->route('admin.content.pages.index', ['locale' => $this->form['locale']]);
    }

    public function render()
    {
        return view('livewire.admin.content.page.form', [
            'isEdit' => (bool) $this->pageId,
        ]);
    }

    public function getCategoryOptionsProperty(): Collection
    {
        $categories = Category::query()
            ->where('scope', Category::SCOPE_PAGE)
            ->withDepth()
            ->defaultOrder()
            ->with([
                'translations' => fn ($q) => $q
                    ->where('scope', Category::SCOPE_PAGE)
                    ->where('locale', $this->form['locale']),
            ])
            ->get();

        $nameById = $categories->mapWithKeys(function (Category $category): array {
            $name = (string) ($category->translations->first()?->name ?? ($category->code ?: ('#'.$category->id)));

            return [(int) $category->id => $name];
        });
        $byId = $categories->keyBy(fn (Category $category): int => (int) $category->id);
        $labels = [];

        $build = function (int $id) use (&$build, &$labels, $byId, $nameById): string {
            if (isset($labels[$id])) {
                return $labels[$id];
            }

            $current = $byId->get($id);
            if ($current === null) {
                return '#'.$id;
            }

            $name = (string) ($nameById[$id] ?? ('#'.$id));
            $parentId = (int) ($current->parent_id ?? 0);
            $labels[$id] = ($parentId > 0 && $byId->has($parentId))
                ? $build($parentId).' > '.$name
                : $name;

            return $labels[$id];
        };

        return $categories->map(function (Category $category) use (&$build): array {
            return [
                'id' => (int) $category->id,
                'label' => $build((int) $category->id),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'form.code' => ['required', 'string', 'max:120', Rule::unique('content_info_pages', 'code')->ignore($this->pageId)],
            'form.layout' => ['nullable', 'string', 'max:80'],
            'form.is_active' => ['boolean'],
            'form.show_in_footer' => ['boolean'],
            'form.published_at' => ['nullable', 'date'],
            'form.sort_order' => ['nullable', 'integer', 'min:0'],
            'form.payload_text' => ['nullable', 'string'],

            'form.locale' => ['required', 'string', 'max:12'],
            'form.title' => ['required', 'string', 'max:255'],
            'form.slug' => [
                'required',
                'string',
                'max:191',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('content_info_page_translations', 'slug')
                    ->where(fn ($q) => $q->where('locale', $this->form['locale']))
                    ->ignore($this->pageId, 'page_id'),
            ],
            'form.excerpt' => ['nullable', 'string'],
            'form.body_html' => ['nullable', 'string'],
            'form.meta_title' => ['nullable', 'string', 'max:255'],
            'form.meta_description' => ['nullable', 'string'],
            'form.translation_payload_text' => ['nullable', 'string'],
            'form.category_ids' => ['nullable', 'array'],
            'form.category_ids.*' => [
                'integer',
                Rule::exists('categories', 'id')->where(fn ($q) => $q->where('scope', Category::SCOPE_PAGE)),
            ],
        ];
    }

    private function loadPage(): void
    {
        if (! $this->pageId) {
            return;
        }

        $page = InfoPage::query()
            ->with('translations')
            ->with(['categories' => fn ($q) => $q->orderBy('content_info_page_category.sort_order')])
            ->findOrFail($this->pageId);

        $preferredLocale = $this->form['locale'] ?: config('app.locale', 'en');
        $translation = $page->translations->firstWhere('locale', $preferredLocale)
            ?? $page->translations->firstWhere('locale', config('app.locale', 'en'))
            ?? $page->translations->first();

        $this->form['code'] = $page->code;
        $this->form['layout'] = $page->layout;
        $this->form['is_active'] = (bool) $page->is_active;
        $this->form['show_in_footer'] = (bool) $page->show_in_footer;
        $this->form['published_at'] = $page->published_at?->format('Y-m-d\TH:i') ?? '';
        $this->form['sort_order'] = (int) $page->sort_order;
        $this->form['payload_text'] = $page->payload
            ? json_encode($page->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
        $this->form['category_ids'] = $page->categories->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($translation) {
            $this->form['locale'] = $translation->locale;
            $this->form['title'] = $translation->title;
            $this->form['slug'] = $translation->slug;
            $this->form['excerpt'] = $translation->excerpt ?? '';
            $this->form['body_html'] = $translation->body_html ?? '';
            $this->form['meta_title'] = $translation->meta_title ?? '';
            $this->form['meta_description'] = $translation->meta_description ?? '';
            $this->form['translation_payload_text'] = $translation->payload
                ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                : '';
        }
    }

    private function loadTranslationForLocale(): void
    {
        if (! $this->pageId) {
            $this->clearTranslationFields();

            return;
        }

        $translation = InfoPageTranslation::query()
            ->where('page_id', $this->pageId)
            ->where('locale', $this->form['locale'])
            ->first();

        if (! $translation) {
            $this->clearTranslationFields();

            return;
        }

        $this->form['title'] = $translation->title;
        $this->form['slug'] = $translation->slug;
        $this->form['excerpt'] = $translation->excerpt ?? '';
        $this->form['body_html'] = $translation->body_html ?? '';
        $this->form['meta_title'] = $translation->meta_title ?? '';
        $this->form['meta_description'] = $translation->meta_description ?? '';
        $this->form['translation_payload_text'] = $translation->payload
            ? json_encode($translation->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : '';
    }

    private function clearTranslationFields(): void
    {
        $this->form['title'] = '';
        $this->form['slug'] = '';
        $this->form['excerpt'] = '';
        $this->form['body_html'] = '';
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
            $this->addError($field, (string) __('Invalid JSON payload.'));
            $this->dispatch('notify', type: 'danger', message: __('Invalid JSON payload.'));

            return false;
        }

        if (! is_array($decoded)) {
            $this->addError($field, (string) __('JSON payload must decode to object/array.'));
            $this->dispatch('notify', type: 'danger', message: __('JSON payload must decode to object/array.'));

            return false;
        }

        return $decoded;
    }
}
