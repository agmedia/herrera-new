<?php

namespace App\Services\Import;

use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use LogicException;

class HerreraQuickFixConfigurationService
{
    public const APPLIED_KEY = 'herrera_quick_fixes_2026_10_applied';

    public const FILTER_DEFAULTS_KEY = 'herrera_filter_defaults_2026_10_applied';

    private const FILTER_GROUP_CODES = [
        'herrera-oc-attribute-45', // Temperatura boje
        'herrera-oc-attribute-31', // Boja svjetla
        'herrera-oc-attribute-3',  // Snaga
        'herrera-oc-attribute-44', // Lumen
        'herrera-oc-attribute-32', // Svjetlosni tok
        'herrera-oc-attribute-42', // Jamstvo
        'herrera-oc-attribute-70', // Dimenzije
        'herrera-oc-attribute-30', // Dimenzije (mm)
    ];

    public function __construct(private readonly SystemSettingsService $settings) {}

    /** Apply the agreed settings once; later administrator edits remain intact. */
    public function apply(): bool
    {
        $this->settings->flush();
        if (! config('commerce.b2b_only')
            || ! str_contains(strtolower((string) $this->settings->get('store_brand_name', '')), 'herrera')) {
            throw new LogicException('Quick-fix settings require the Herrera B2B store.');
        }

        if ($this->settings->get(self::APPLIED_KEY, false)) {
            return false;
        }

        $profiles = [
            ['network' => 'facebook', 'label' => 'Facebook Hrvatska', 'url' => 'https://www.facebook.com/people/Herrera-Hrvatska/61592455240805/'],
            ['network' => 'facebook', 'label' => 'Facebook Slovenija', 'url' => 'https://www.facebook.com/people/Herrera-Slovenija/61593625222066/'],
            ['network' => 'instagram', 'label' => 'Instagram', 'url' => 'https://www.instagram.com/herrerahrvatska/'],
            ['network' => 'tiktok', 'label' => 'TikTok', 'url' => 'https://www.tiktok.com/@herrera.hr1'],
        ];
        $topBar = $this->settings->get(NavigationMenuService::TOP_BAR_SETTINGS_KEY, []);
        $topBar = is_array($topBar) ? $topBar : [];
        $topBar['socials'] = array_map(fn (array $profile, int $index): array => $profile + [
            'is_active' => true, 'sort_order' => $index,
        ], $profiles, array_keys($profiles));

        $navigation = collect($this->settings->get(NavigationMenuService::SETTINGS_KEY, []))
            ->filter(fn ($item): bool => is_array($item))->values();
        $blogIndex = $navigation->search(fn (array $item): bool => ($item['type'] ?? '') === 'blog'
            || rtrim((string) ($item['url'] ?? ''), '/') === '/blog');
        if ($blogIndex === false) {
            $navigation->push([
                'type' => 'blog', 'label' => 'Blog', 'is_active' => true,
                'sort_order' => ((int) $navigation->max('sort_order')) + 1,
            ]);
        } else {
            $navigation[$blogIndex] = array_replace($navigation[$blogIndex], ['type' => 'blog', 'is_active' => true]);
        }

        $filterCodes = collect($this->settings->get('store_product_filter_attribute_group_codes', []))
            ->filter(fn ($code): bool => is_string($code) && trim($code) !== '')
            ->merge(self::FILTER_GROUP_CODES)->unique()->values()->all();

        try {
            DB::transaction(fn () => $this->settings->putMany([
                'store_social_facebook_url' => $profiles[0]['url'],
                'store_social_facebook_slovenia_url' => $profiles[1]['url'],
                'store_social_instagram_url' => $profiles[2]['url'],
                'store_social_tiktok_url' => $profiles[3]['url'],
                'store_footer_social_facebook_enabled' => true,
                'store_footer_social_facebook_slovenia_enabled' => true,
                'store_footer_social_instagram_enabled' => true,
                'store_footer_social_tiktok_enabled' => true,
                NavigationMenuService::TOP_BAR_SETTINGS_KEY => $topBar,
                NavigationMenuService::SETTINGS_KEY => $navigation->all(),
                'catalog_use_blog' => true,
                'catalog_use_attributes' => true,
                'catalog_hide_out_of_stock_products' => true,
                'store_product_filter_attribute_group_codes' => $filterCodes,
                self::APPLIED_KEY => true,
            ]));
        } finally {
            $this->settings->flush();
        }

        return true;
    }

    public function applyFilterDefaults(): bool
    {
        if (! config('commerce.b2b_only')
            || ! str_contains(strtolower((string) $this->settings->get('store_brand_name', '')), 'herrera')) {
            throw new LogicException('Filter defaults require the Herrera B2B store.');
        }
        if ($this->settings->get(self::FILTER_DEFAULTS_KEY, false)) {
            return false;
        }

        $panels = $this->settings->get('store_product_filter_panel_settings', []);
        $panels = is_array($panels) ? $panels : [];
        foreach ($panels as $key => $panel) {
            $panels[$key] = array_replace(is_array($panel) ? $panel : [], ['default_open' => $key === 'category']);
        }
        foreach (['category', 'manufacturer', 'price'] as $key) {
            $panels[$key] = array_replace($panels[$key] ?? [], ['default_open' => $key === 'category']);
        }
        DB::transaction(fn () => $this->settings->putMany([
            'store_product_filter_panel_settings' => $panels,
            self::FILTER_DEFAULTS_KEY => true,
        ]));

        return true;
    }
}
