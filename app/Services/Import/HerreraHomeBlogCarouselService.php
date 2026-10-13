<?php

namespace App\Services\Import;

use App\Models\Content\ContentBlock;
use App\Models\Content\ContentBlockSlot;
use App\Services\Content\ContentBlockResolver;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\DB;
use LogicException;

class HerreraHomeBlogCarouselService
{
    public const APPLIED_KEY = 'herrera_home_blog_carousel_2026_10_applied';

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function apply(): bool
    {
        if (! config('commerce.b2b_only')
            || ! str_contains(strtolower((string) $this->settings->get('store_brand_name', '')), 'herrera')) {
            throw new LogicException('The blog carousel setup requires the Herrera B2B store.');
        }
        if ($this->settings->get(self::APPLIED_KEY, false)) {
            return false;
        }

        DB::transaction(function (): void {
            $block = ContentBlock::query()->firstOrCreate(['code' => 'herrera-home-blog'], [
                'name' => 'Herrera - savjeti i novosti', 'type' => 'blogs_carousel', 'is_active' => true,
                'payload' => ['blog_source' => 'latest', 'items_limit' => 6],
            ]);
            foreach (['hr' => ['Savjeti i novosti', 'Svi članci'], 'en' => ['Advice and news', 'All articles']] as $locale => [$title, $cta]) {
                $block->translations()->firstOrCreate(['locale' => $locale], [
                    'title' => $title, 'cta_label' => $cta, 'cta_url' => '/blog',
                    'payload' => ['custom_classes' => 'herrera-home-blog'],
                ]);
            }
            // Preserve the resolver's shared-slot fallback: introducing a specific
            // slot must not hide existing shared home.bottom widgets.
            $variants = ['all'];
            foreach (['desktop', 'mobile'] as $variant) {
                if (ContentBlockSlot::query()->where('placement', 'home.bottom')->where('frontend_variant', $variant)
                    ->whereNull('target_type')->currentlyActive()
                    ->whereHas('block', fn ($query) => $query->where('is_active', true))->exists()) {
                    $variants[] = $variant;
                }
            }
            foreach ($variants as $variant) {
                $sort = (int) ContentBlockSlot::query()->where('placement', 'home.bottom')->max('sort_order');
                $block->slots()->firstOrCreate(['placement' => 'home.bottom', 'frontend_variant' => $variant], [
                    'sort_order' => $sort + 10, 'is_active' => true,
                ]);
            }
            $this->settings->put(self::APPLIED_KEY, true);
        });
        ContentBlockResolver::bumpCacheVersion();

        return true;
    }
}
