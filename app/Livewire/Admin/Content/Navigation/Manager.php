<?php

namespace App\Livewire\Admin\Content\Navigation;

use App\Models\Catalog\Category\Category;
use App\Models\Content\Page\InfoPage;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class Manager extends Component
{
    use WithFileUploads;

    /** @var array<string, string> */
    private const PROMO_LOCALIZED_FIELDS = [
        'desktop_promo_title' => 'desktop_promo_title_translations',
        'desktop_promo_subtitle' => 'desktop_promo_subtitle_translations',
        'desktop_promo_cta_label' => 'desktop_promo_cta_label_translations',
        'desktop_promo_cta_url' => 'desktop_promo_cta_url_translations',
    ];

    /**
     * @var array{
     *     items: array<int, array<string, mixed>>,
     *     appearance: array{container_width:int,header_content_width:int,item_height:int,font_size:int,logo_height:int,background_color:string,text_color:string,highlight_color:string},
     *     top_bar: array<string, mixed>
     * }
     */
    public array $form = [
        'items' => [],
        'appearance' => [
            'container_width' => 1860,
            'header_content_width' => 1400,
            'item_height' => 62,
            'font_size' => 17,
            'logo_height' => 70,
            'background_color' => '#e65100',
            'text_color' => '#ffffff',
            'highlight_color' => '#ffffff',
        ],
        'top_bar' => [
            'is_enabled' => true,
            'height' => 34,
            'font_size' => 13,
            'background_color' => '#eeeeee',
            'text_color' => '#334155',
            'border_color' => '#4c1d95',
            'links' => [],
            'socials' => [],
        ],
    ];

    public string $locale = 'en';

    public string $previousLocale = 'en';

    /** @var array<int, TemporaryUploadedFile|null> */
    public array $desktopPromoUploads = [];

    public function mount(): void
    {
        $this->locale = (string) (request()->query('locale') ?: config('app.locale', 'en'));
        $this->previousLocale = $this->locale;

        $navigation = app(NavigationMenuService::class);
        $items = $navigation->configuredItems();
        $this->form['items'] = $items;
        $this->form['appearance'] = $navigation->appearance();
        $this->form['top_bar'] = $navigation->topBar();
        $this->syncInputsFromLocaleTranslations($this->locale);
    }

    public function updatedLocale(): void
    {
        $this->syncLocaleTranslationsFromInputs($this->previousLocale);
        $this->syncInputsFromLocaleTranslations($this->locale);
        $this->previousLocale = $this->locale;
    }

    public function addCategoryItem(): void
    {
        $this->form['items'][] = $this->makeDefaultItem('category');
    }

    public function addCatalogItem(): void
    {
        $item = $this->makeDefaultItem('catalog');
        $item['label'] = (string) __('admin.content.navigation.defaults.catalog');
        $item['label_translations'] = [$this->locale => (string) __('admin.content.navigation.defaults.catalog')];
        $item['url'] = '/categories';
        $item['url_translations'] = [$this->locale => '/categories'];
        $this->form['items'][] = $item;
    }

    public function addPageItem(): void
    {
        $this->form['items'][] = $this->makeDefaultItem('page');
    }

    public function addBlogItem(): void
    {
        $item = $this->makeDefaultItem('blog');
        $item['label'] = 'Blog';
        $item['label_translations'] = [$this->locale => 'Blog'];
        $this->form['items'][] = $item;
    }

    public function addContactItem(): void
    {
        $item = $this->makeDefaultItem('contact');
        $item['label'] = (string) __('admin.content.navigation.defaults.contact');
        $item['label_translations'] = [$this->locale => (string) __('admin.content.navigation.defaults.contact')];
        $this->form['items'][] = $item;
    }

    public function addFaqItem(): void
    {
        $item = $this->makeDefaultItem('faq');
        $item['label'] = 'FAQ';
        $item['label_translations'] = [$this->locale => 'FAQ'];
        $this->form['items'][] = $item;
    }

    public function addCustomItem(): void
    {
        $item = $this->makeDefaultItem('custom');
        $item['label'] = (string) __('admin.content.navigation.defaults.custom_label');
        $item['url'] = '/';
        $item['label_translations'] = [$this->locale => (string) __('admin.content.navigation.defaults.custom_label')];
        $item['url_translations'] = [$this->locale => '/'];
        $this->form['items'][] = $item;
    }

    public function removeItem(int $index): void
    {
        if (! isset($this->form['items'][$index])) {
            return;
        }

        unset($this->form['items'][$index]);
        $this->form['items'] = array_values($this->form['items']);
    }

    public function moveUp(int $index): void
    {
        if ($index <= 0 || ! isset($this->form['items'][$index])) {
            return;
        }

        [$this->form['items'][$index - 1], $this->form['items'][$index]] = [$this->form['items'][$index], $this->form['items'][$index - 1]];
    }

    public function moveDown(int $index): void
    {
        $lastIndex = count($this->form['items']) - 1;

        if ($index < 0 || $index >= $lastIndex || ! isset($this->form['items'][$index])) {
            return;
        }

        [$this->form['items'][$index + 1], $this->form['items'][$index]] = [$this->form['items'][$index], $this->form['items'][$index + 1]];
    }

    public function addTopBarLink(): void
    {
        $this->form['top_bar']['links'][] = [
            'label' => (string) __('admin.content.navigation.top_bar_default_link'),
            'url' => '/',
            'open_in_new_tab' => false,
            'is_active' => true,
            'sort_order' => count($this->form['top_bar']['links']) * 10,
        ];
    }

    public function removeTopBarLink(int $index): void
    {
        if (! isset($this->form['top_bar']['links'][$index])) {
            return;
        }

        unset($this->form['top_bar']['links'][$index]);
        $this->form['top_bar']['links'] = array_values($this->form['top_bar']['links']);
    }

    public function addSocialLink(): void
    {
        $this->form['top_bar']['socials'][] = [
            'network' => 'facebook',
            'url' => '',
            'is_active' => true,
            'sort_order' => count($this->form['top_bar']['socials']) * 10,
        ];
    }

    public function removeSocialLink(int $index): void
    {
        if (! isset($this->form['top_bar']['socials'][$index])) {
            return;
        }

        unset($this->form['top_bar']['socials'][$index]);
        $this->form['top_bar']['socials'] = array_values($this->form['top_bar']['socials']);
    }

    public function save(): void
    {
        $this->syncLocaleTranslationsFromInputs($this->locale);

        $validated = $this->validate([
            'form.items' => ['array'],
            'form.items.*.type' => ['required', 'in:catalog,category,page,blog,contact,faq,custom'],
            'form.items.*.label' => ['nullable', 'string', 'max:120'],
            'form.items.*.category_id' => ['nullable', 'integer', 'min:0'],
            'form.items.*.page_id' => ['nullable', 'integer', 'min:0'],
            'form.items.*.url' => ['nullable', 'string', 'max:2048'],
            'form.items.*.label_translations' => ['nullable', 'array'],
            'form.items.*.label_translations.*' => ['nullable', 'string', 'max:120'],
            'form.items.*.url_translations' => ['nullable', 'array'],
            'form.items.*.url_translations.*' => ['nullable', 'string', 'max:2048'],
            'form.items.*.is_active' => ['required', 'boolean'],
            'form.items.*.show_dropdown' => ['required', 'boolean'],
            'form.items.*.is_highlighted' => ['required', 'boolean'],
            'form.items.*.open_in_new_tab' => ['required', 'boolean'],
            'form.items.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'form.items.*.desktop_promo_image_path' => ['nullable', 'string', 'max:2048'],
            'form.items.*.desktop_promo_title' => ['nullable', 'string', 'max:120'],
            'form.items.*.desktop_promo_subtitle' => ['nullable', 'string', 'max:255'],
            'form.items.*.desktop_promo_cta_label' => ['nullable', 'string', 'max:80'],
            'form.items.*.desktop_promo_cta_url' => ['nullable', 'string', 'max:2048'],
            'form.items.*.desktop_promo_title_translations' => ['nullable', 'array'],
            'form.items.*.desktop_promo_title_translations.*' => ['nullable', 'string', 'max:120'],
            'form.items.*.desktop_promo_subtitle_translations' => ['nullable', 'array'],
            'form.items.*.desktop_promo_subtitle_translations.*' => ['nullable', 'string', 'max:255'],
            'form.items.*.desktop_promo_cta_label_translations' => ['nullable', 'array'],
            'form.items.*.desktop_promo_cta_label_translations.*' => ['nullable', 'string', 'max:80'],
            'form.items.*.desktop_promo_cta_url_translations' => ['nullable', 'array'],
            'form.items.*.desktop_promo_cta_url_translations.*' => ['nullable', 'string', 'max:2048'],
            'form.appearance.container_width' => ['required', 'integer', 'min:960', 'max:1920'],
            'form.appearance.header_content_width' => ['required', 'integer', 'min:960', 'max:1920', 'lte:form.appearance.container_width'],
            'form.appearance.item_height' => ['required', 'integer', 'min:44', 'max:84'],
            'form.appearance.font_size' => ['required', 'integer', 'min:13', 'max:20'],
            'form.appearance.logo_height' => ['required', 'integer', 'min:48', 'max:82'],
            'form.appearance.background_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.appearance.text_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.appearance.highlight_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.top_bar.is_enabled' => ['required', 'boolean'],
            'form.top_bar.height' => ['required', 'integer', 'min:28', 'max:50'],
            'form.top_bar.font_size' => ['required', 'integer', 'min:11', 'max:16'],
            'form.top_bar.background_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.top_bar.text_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.top_bar.border_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'form.top_bar.links' => ['array'],
            'form.top_bar.links.*.label' => ['required', 'string', 'max:120'],
            'form.top_bar.links.*.url' => ['required', 'string', 'max:2048'],
            'form.top_bar.links.*.open_in_new_tab' => ['required', 'boolean'],
            'form.top_bar.links.*.is_active' => ['required', 'boolean'],
            'form.top_bar.links.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'form.top_bar.socials' => ['array'],
            'form.top_bar.socials.*.network' => ['required', 'in:facebook,youtube,instagram'],
            'form.top_bar.socials.*.url' => ['required', 'string', 'max:2048'],
            'form.top_bar.socials.*.is_active' => ['required', 'boolean'],
            'form.top_bar.socials.*.sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'desktopPromoUploads.*' => ['nullable', 'image', 'max:4096'],
        ]);

        $normalizedItems = [];
        foreach (($validated['form']['items'] ?? []) as $index => $item) {
            $normalized = $this->normalizeItem($item, $index, $this->desktopPromoUploads[$index] ?? null);
            $normalizedItems[] = $normalized;

            $type = (string) $normalized['type'];
            if ($type === 'category' && (int) $normalized['category_id'] <= 0) {
                $this->addError('form.items.'.$index.'.category_id', (string) __('admin.content.navigation.errors.select_category'));
            }
            if ($type === 'page' && (int) $normalized['page_id'] <= 0) {
                $this->addError('form.items.'.$index.'.page_id', (string) __('admin.content.navigation.errors.select_page'));
            }
            if ($type === 'custom') {
                if (trim((string) $normalized['label']) === '') {
                    $this->addError('form.items.'.$index.'.label', (string) __('admin.content.navigation.errors.enter_label'));
                }
                if (trim((string) $normalized['url']) === '') {
                    $this->addError('form.items.'.$index.'.url', (string) __('admin.content.navigation.errors.enter_url'));
                }
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        app(SystemSettingsService::class)->putMany([
            NavigationMenuService::SETTINGS_KEY => $normalizedItems,
            NavigationMenuService::APPEARANCE_SETTINGS_KEY => $validated['form']['appearance'],
            NavigationMenuService::TOP_BAR_SETTINGS_KEY => $validated['form']['top_bar'],
        ]);
        $this->desktopPromoUploads = [];

        $this->dispatch('notify', type: 'success', message: (string) __('admin.content.navigation.notify_saved'));
    }

    public function clearDesktopPromoImage(int $index): void
    {
        if (! isset($this->form['items'][$index])) {
            return;
        }

        $this->form['items'][$index]['desktop_promo_image_path'] = '';
        unset($this->desktopPromoUploads[$index]);
    }

    public function render()
    {
        $fallbackLocale = (string) config('app.locale', 'en');
        $locales = array_values(array_unique([$this->locale, $fallbackLocale]));

        $categoryOptions = Category::query()
            ->where('scope', Category::SCOPE_CATALOG)
            ->where('is_active', true)
            ->with([
                'translations' => fn ($q) => $q
                    ->where('scope', Category::SCOPE_CATALOG)
                    ->whereIn('locale', $locales),
            ])
            ->orderBy('_lft')
            ->get();

        $categoryNameById = $categoryOptions
            ->mapWithKeys(function (Category $category) use ($fallbackLocale): array {
                $translation = $category->translations->firstWhere('locale', $this->locale)
                    ?? $category->translations->firstWhere('locale', $fallbackLocale)
                    ?? $category->translations->first();

                return [
                    (int) $category->id => (string) ($translation?->name ?? $category->code ?? '#'.$category->id),
                ];
            });

        $categoryMap = $categoryOptions->keyBy(fn (Category $category): int => (int) $category->id);

        $categoryOptions = $categoryOptions
            ->map(function (Category $category) use ($categoryNameById, $categoryMap): array {
                $parts = [];
                $cursor = $category;
                $guard = 0;

                while ($cursor && $guard < 32) {
                    $parts[] = (string) ($categoryNameById[(int) $cursor->id] ?? ('#'.$cursor->id));
                    $parentId = (int) ($cursor->parent_id ?? 0);
                    $cursor = $parentId > 0 ? $categoryMap->get($parentId) : null;
                    $guard++;
                }

                $parts = array_reverse($parts);

                return [
                    'id' => (int) $category->id,
                    'label' => implode(' > ', $parts),
                ];
            })
            ->values()
            ->all();

        $pageOptions = InfoPage::query()
            ->where('is_active', true)
            ->with([
                'translations' => fn ($q) => $q->whereIn('locale', $locales),
            ])
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(function (InfoPage $page) use ($fallbackLocale): array {
                $translation = $page->translations->firstWhere('locale', $this->locale)
                    ?? $page->translations->firstWhere('locale', $fallbackLocale)
                    ?? $page->translations->first();

                return [
                    'id' => (int) $page->id,
                    'label' => (string) ($translation?->title ?? $page->code),
                ];
            })
            ->values()
            ->all();

        return view('livewire.admin.content.navigation.manager', [
            'categoryOptions' => $categoryOptions,
            'pageOptions' => $pageOptions,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item, int $index, mixed $desktopPromoUpload = null): array
    {
        $type = (string) ($item['type'] ?? 'custom');
        $locale = strtolower(trim($this->locale));
        $fallbackLocale = strtolower((string) config('app.locale', 'en'));
        $labelTranslations = $this->normalizeTranslations($item['label_translations'] ?? []);
        $urlTranslations = $this->normalizeTranslations($item['url_translations'] ?? []);

        $label = trim((string) ($item['label'] ?? ''));
        if ($label !== '' && $locale !== '') {
            $labelTranslations[$locale] = $label;
        }
        if ($label === '' && $locale !== '') {
            unset($labelTranslations[$locale]);
        }

        $url = trim((string) ($item['url'] ?? ''));
        if ($url !== '' && $locale !== '') {
            $urlTranslations[$locale] = $url;
        }
        if ($url === '' && $locale !== '') {
            unset($urlTranslations[$locale]);
        }

        $storedLabel = $this->pickTranslationValue($labelTranslations, $fallbackLocale);
        $storedUrl = $this->pickTranslationValue($urlTranslations, $fallbackLocale);

        $desktopPromoImagePath = trim((string) ($item['desktop_promo_image_path'] ?? ''));
        if ($desktopPromoUpload instanceof TemporaryUploadedFile) {
            $desktopPromoImagePath = $desktopPromoUpload->store('navigation/mega-promo', 'public');
        }

        $localizedPromo = [];
        foreach (self::PROMO_LOCALIZED_FIELDS as $field => $translationsField) {
            $translations = $this->normalizeTranslations($item[$translationsField] ?? []);
            $value = trim((string) ($item[$field] ?? ''));

            if ($value !== '' && $locale !== '') {
                $translations[$locale] = $value;
            } elseif ($locale !== '') {
                unset($translations[$locale]);
            }

            $localizedPromo[$field] = $this->pickTranslationValue($translations, $fallbackLocale);
            $localizedPromo[$translationsField] = $translations;
        }

        return array_merge([
            'type' => $type,
            'label' => $storedLabel,
            'label_translations' => $labelTranslations,
            'category_id' => (int) ($item['category_id'] ?? 0),
            'page_id' => (int) ($item['page_id'] ?? 0),
            'url' => $storedUrl,
            'url_translations' => $urlTranslations,
            'open_in_new_tab' => (bool) ($item['open_in_new_tab'] ?? false),
            'show_dropdown' => (bool) ($item['show_dropdown'] ?? true),
            'is_highlighted' => (bool) ($item['is_highlighted'] ?? false),
            'is_active' => (bool) ($item['is_active'] ?? true),
            'sort_order' => (int) ($item['sort_order'] ?? $index),
            'desktop_promo_image_path' => $desktopPromoImagePath,
        ], $localizedPromo);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeDefaultItem(string $type): array
    {
        return [
            'type' => $type,
            'label' => '',
            'label_translations' => [],
            'category_id' => 0,
            'page_id' => 0,
            'url' => '',
            'url_translations' => [],
            'open_in_new_tab' => false,
            'show_dropdown' => true,
            'is_highlighted' => false,
            'is_active' => true,
            'sort_order' => count($this->form['items']),
            'desktop_promo_image_path' => '',
            'desktop_promo_title' => '',
            'desktop_promo_title_translations' => [],
            'desktop_promo_subtitle' => '',
            'desktop_promo_subtitle_translations' => [],
            'desktop_promo_cta_label' => '',
            'desktop_promo_cta_label_translations' => [],
            'desktop_promo_cta_url' => '',
            'desktop_promo_cta_url_translations' => [],
        ];
    }

    private function syncLocaleTranslationsFromInputs(string $locale): void
    {
        $normalizedLocale = strtolower(trim($locale));
        if ($normalizedLocale === '') {
            return;
        }

        foreach ($this->form['items'] as $index => $item) {
            $labelTranslations = $this->normalizeTranslations($item['label_translations'] ?? []);
            $urlTranslations = $this->normalizeTranslations($item['url_translations'] ?? []);

            $label = trim((string) ($item['label'] ?? ''));
            if ($label !== '') {
                $labelTranslations[$normalizedLocale] = $label;
            } else {
                unset($labelTranslations[$normalizedLocale]);
            }

            $url = trim((string) ($item['url'] ?? ''));
            if ($url !== '') {
                $urlTranslations[$normalizedLocale] = $url;
            } else {
                unset($urlTranslations[$normalizedLocale]);
            }

            $this->form['items'][$index]['label_translations'] = $labelTranslations;
            $this->form['items'][$index]['url_translations'] = $urlTranslations;

            foreach (self::PROMO_LOCALIZED_FIELDS as $field => $translationsField) {
                $translations = $this->normalizeTranslations($item[$translationsField] ?? []);
                $value = trim((string) ($item[$field] ?? ''));

                if ($value !== '') {
                    $translations[$normalizedLocale] = $value;
                } else {
                    unset($translations[$normalizedLocale]);
                }

                $this->form['items'][$index][$translationsField] = $translations;
            }
        }
    }

    private function syncInputsFromLocaleTranslations(string $locale): void
    {
        $normalizedLocale = strtolower(trim($locale));
        $fallbackLocale = strtolower((string) config('app.locale', 'en'));

        foreach ($this->form['items'] as $index => $item) {
            $labelTranslations = $this->normalizeTranslations($item['label_translations'] ?? []);
            $urlTranslations = $this->normalizeTranslations($item['url_translations'] ?? []);

            $resolvedLabel = $this->pickTranslationValue($labelTranslations, $normalizedLocale, $fallbackLocale);
            $resolvedUrl = $this->pickTranslationValue($urlTranslations, $normalizedLocale, $fallbackLocale);

            $this->form['items'][$index]['label'] = $resolvedLabel !== '' ? $resolvedLabel : trim((string) ($item['label'] ?? ''));
            $this->form['items'][$index]['url'] = $resolvedUrl !== '' ? $resolvedUrl : trim((string) ($item['url'] ?? ''));
            $this->form['items'][$index]['label_translations'] = $labelTranslations;
            $this->form['items'][$index]['url_translations'] = $urlTranslations;

            foreach (self::PROMO_LOCALIZED_FIELDS as $field => $translationsField) {
                $translations = $this->normalizeTranslations($item[$translationsField] ?? []);
                $resolved = $this->pickTranslationValue($translations, $normalizedLocale, $fallbackLocale);

                $this->form['items'][$index][$field] = $resolved !== ''
                    ? $resolved
                    : trim((string) ($item[$field] ?? ''));
                $this->form['items'][$index][$translationsField] = $translations;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function normalizeTranslations(mixed $translations): array
    {
        if (! is_array($translations)) {
            return [];
        }

        $normalized = [];
        foreach ($translations as $locale => $value) {
            $key = strtolower(trim((string) $locale));
            if ($key === '') {
                continue;
            }

            $text = trim((string) $value);
            if ($text === '') {
                continue;
            }

            $normalized[$key] = $text;
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $translations
     */
    private function pickTranslationValue(array $translations, string ...$preferredLocales): string
    {
        foreach ($preferredLocales as $locale) {
            $key = strtolower(trim($locale));
            if ($key !== '' && isset($translations[$key]) && trim((string) $translations[$key]) !== '') {
                return trim((string) $translations[$key]);
            }
        }

        foreach ($translations as $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}
