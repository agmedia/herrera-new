<?php

namespace App\Services\Import;

use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use App\Models\Settings\System\SystemSetting;
use App\Services\Content\ContentBlockResolver;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use LogicException;

class HerreraHomeWidgetsService
{
    public const APPLIED_KEY = 'herrera_home_widgets_2026_11_applied';

    private const CATEGORIES = 'herrera-home-category-catalog';

    private const BLOG = 'herrera-home-blog';

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function apply(): bool
    {
        if (! config('commerce.b2b_only')
            || ! str_contains(strtolower((string) $this->settings->get('store_brand_name', '')), 'herrera')) {
            throw new LogicException('Homepage widget setup requires the Herrera B2B store.');
        }

        if ($this->settings->get(self::APPLIED_KEY, false)) {
            return false;
        }

        $applied = DB::transaction(function (): bool {
            // Serialize the first run even when two local commands start together.
            SystemSetting::query()->where('key', 'store_brand_name')->lockForUpdate()->first();
            $marker = SystemSetting::query()->where('key', self::APPLIED_KEY)->lockForUpdate()->first();
            if ($marker && json_decode((string) $marker->value, true)) {
                return false;
            }

            $homeSlots = ContentBlockSlot::query()->where('placement', 'like', 'home.%')
                ->whereNull('target_type')->whereNull('target_ref')
                ->where(fn ($query) => $query->whereNull('frontend_variant')->orWhereIn('frontend_variant', ['all', 'desktop']));
            $visibleSlots = (clone $homeSlots)->currentlyActive()
                ->whereHas('block', fn ($query) => $query->where('is_active', true))
                ->with('block')->get()
                ->groupBy('placement')->flatMap(function ($slots) {
                    $desktop = $slots->where('frontend_variant', 'desktop');

                    return $desktop->isEmpty() ? $slots : $desktop;
                });

            $replacements = [
                'herrera-home-hero' => $visibleSlots->contains('placement', 'home.hero'),
                self::CATEGORIES => (clone $homeSlots)->where('placement', 'home.categories')
                    ->whereHas('block', fn ($query) => $query->where('type', 'featured_categories'))->exists(),
                'herrera-home-brands' => $visibleSlots->contains('block.type', 'popular_brands'),
                self::BLOG => $this->settings->get(HerreraHomeBlogCarouselService::APPLIED_KEY, false)
                    || $visibleSlots->contains('block.type', 'blogs_carousel'),
            ];

            foreach ($this->definitions() as $code => $definition) {
                $block = ContentBlock::query()->where('code', $code)->first();
                if ($block) {
                    if ($code === self::CATEGORIES) {
                        $this->enableResponsiveDefaultCategories($block);
                    }

                    continue;
                }

                if ($replacements[$code] ?? false) {
                    continue;
                }

                $block = ContentBlock::query()->create([
                    'code' => $code,
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'is_active' => true,
                    'payload' => $definition['payload'] ?? [],
                ]);

                foreach (['hr', 'en'] as $locale) {
                    $block->translations()->create([
                        'locale' => $locale,
                        'title' => __($definition['title'], [], $locale),
                        'subtitle' => isset($definition['subtitle']) ? __($definition['subtitle'], [], $locale) : null,
                        'cta_label' => __($definition['cta_label'], [], $locale),
                        'cta_url' => route($definition['cta_route'], [], false),
                        'payload' => $definition['translation_payload'] ?? [],
                    ]);
                }

                $lastSort = ContentBlockSlot::query()->where('placement', $definition['placement'])
                    ->whereNull('target_type')->whereNull('target_ref')->max('sort_order');
                $block->slots()->create([
                    'placement' => $definition['placement'],
                    'frontend_variant' => 'all',
                    'sort_order' => ((int) $lastSort) + 10,
                    'is_active' => true,
                ]);
            }

            $this->settings->put(self::APPLIED_KEY, true);

            return true;
        });

        $this->settings->flush();
        if ($applied) {
            ContentBlockResolver::bumpCacheVersion();
        }

        return $applied;
    }

    private function enableResponsiveDefaultCategories(ContentBlock $block): void
    {
        if (! $block->is_active || $block->type !== 'featured_categories' || data_get($block->payload, 'category_source') !== 'all_root') {
            return;
        }

        $slots = $block->slots()->get();
        $slot = $slots->count() === 1 ? $slots->first() : null;
        if ($slot?->placement === 'home.categories' && $slot->is_active && $slot->frontend_variant === 'desktop'
            && $slot->target_type === null && $slot->target_ref === null
            && $slot->starts_at === null && $slot->ends_at === null) {
            $slot->update(['frontend_variant' => 'all']);
        }
    }

    private function definitions(): array
    {
        return [
            'herrera-home-hero' => [
                'name' => 'Herrera - uvodni blok', 'type' => 'herrera_hero', 'placement' => 'home.hero',
                'title' => 'herrera.headline', 'subtitle' => 'herrera.description',
                'cta_label' => 'herrera.catalog', 'cta_route' => 'categories.index',
            ],
            self::CATEGORIES => [
                'name' => 'Herrera - kategorije na početnoj', 'type' => 'featured_categories', 'placement' => 'home.categories',
                'title' => 'herrera.categories', 'cta_label' => 'herrera.all_categories', 'cta_route' => 'categories.index',
                'payload' => ['category_source' => 'all_root'],
            ],
            'herrera-home-featured-products' => [
                'name' => 'Herrera - odabrani proizvodi', 'type' => 'herrera_curated_products', 'placement' => 'home.before_products',
                'title' => 'herrera.products', 'cta_label' => 'herrera.all_products', 'cta_route' => 'categories.index',
                'translation_payload' => ['items_limit' => 8],
            ],
            'herrera-home-brands' => [
                'name' => 'Herrera - proizvođači', 'type' => 'popular_brands', 'placement' => 'home.after_products',
                'title' => 'herrera.brands_title', 'cta_label' => 'herrera.brands_all', 'cta_route' => 'manufacturers.index',
            ],
            'herrera-home-popular-products' => [
                'name' => 'Herrera - najpopularniji proizvodi', 'type' => 'herrera_popular_products', 'placement' => 'home.after_products',
                'title' => 'herrera.popular_products', 'cta_label' => 'herrera.all_products', 'cta_route' => 'categories.index',
                'translation_payload' => ['items_limit' => 8],
            ],
            'herrera-home-registration' => [
                'name' => 'Herrera - registracija poslovnih kupaca', 'type' => 'herrera_registration', 'placement' => 'home.after_products',
                'title' => 'herrera.registration.title', 'subtitle' => 'herrera.registration.description',
                'cta_label' => 'herrera.registration.action', 'cta_route' => 'front.auth.b2b-register',
            ],
            self::BLOG => [
                'name' => 'Herrera - savjeti i novosti', 'type' => 'blogs_carousel', 'placement' => 'home.bottom',
                'title' => 'herrera.blog.title', 'cta_label' => 'herrera.blog.all', 'cta_route' => 'blog.index',
                'payload' => ['blog_source' => 'latest', 'items_limit' => 6],
                'translation_payload' => ['custom_classes' => 'herrera-home-blog'],
            ],
        ];
    }
}
