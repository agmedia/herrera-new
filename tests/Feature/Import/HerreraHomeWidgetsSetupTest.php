<?php

namespace Tests\Feature\Import;

use App\Models\Content\ContentBlock;
use App\Services\Import\HerreraHomeBlogCarouselService;
use App\Services\Import\HerreraHomeWidgetsService;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraHomeWidgetsSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
    }

    public function test_setup_creates_all_homepage_widgets_in_their_existing_visual_order(): void
    {
        $banner = $this->block('authored-banner', 'rich_text', 'home.after_products', ['sort_order' => 100]);

        $this->assertTrue(app(HerreraHomeWidgetsService::class)->apply());
        $this->assertTrue(app(SystemSettingsService::class)->get(HerreraHomeWidgetsService::APPLIED_KEY));

        $expected = [
            'hero' => ['herrera_hero', 'home.hero', __('herrera.headline', [], 'hr')],
            'category-catalog' => ['featured_categories', 'home.categories', __('herrera.categories', [], 'hr')],
            'featured-products' => ['herrera_curated_products', 'home.before_products', __('herrera.products', [], 'hr')],
            'brands' => ['popular_brands', 'home.after_products', __('herrera.brands_title', [], 'hr')],
            'popular-products' => ['herrera_popular_products', 'home.after_products', __('herrera.popular_products', [], 'hr')],
            'registration' => ['herrera_registration', 'home.after_products', __('herrera.registration.title', [], 'hr')],
            'blog' => ['blogs_carousel', 'home.bottom', 'Savjeti i novosti'],
        ];
        $blocks = ContentBlock::query()->where('code', 'like', 'herrera-home-%')->with(['slots', 'translations'])->get()->keyBy('code');
        $this->assertCount(7, $blocks);
        foreach ($expected as $code => [$type, $placement, $title]) {
            $block = $blocks->get('herrera-home-'.$code);
            $this->assertSame($type, $block->type);
            $this->assertTrue($block->is_active);
            $this->assertCount(1, $block->slots);
            $this->assertSame($placement, $block->slots->first()->placement);
            $this->assertSame('all', $block->slots->first()->frontend_variant);
            $this->assertSame($title, $block->translations->firstWhere('locale', 'hr')->title);
            $this->assertCount(2, $block->translations);
        }
        $this->assertSame(['category_source' => 'all_root'], $blocks['herrera-home-category-catalog']->payload);
        $this->assertSame(0, $blocks['herrera-home-featured-products']->items()->count());
        $this->assertSame(8, $blocks['herrera-home-popular-products']->translations->firstWhere('locale', 'hr')->payload['items_limit']);
        $this->assertSame(['blog_source' => 'latest', 'items_limit' => 6], $blocks['herrera-home-blog']->payload);
        $this->assertSame(100, $banner->slots()->sole()->sort_order);
        $this->assertSame([110, 120, 130], collect(['brands', 'popular-products', 'registration'])
            ->map(fn (string $code): int => $blocks['herrera-home-'.$code]->slots->first()->sort_order)->all());
    }

    public function test_setup_broadens_only_the_default_category_slot_and_preserves_edits_on_redeploy(): void
    {
        $category = $this->block('herrera-home-category-catalog', 'featured_categories', 'home.categories', ['frontend_variant' => 'desktop', 'sort_order' => 25], ['category_source' => 'all_root']);
        $blog = $this->block('herrera-home-blog', 'blogs_carousel', 'home.before_products', ['is_active' => false, 'sort_order' => 99], ['blog_source' => 'featured']);
        $category->translations()->create(['locale' => 'hr', 'title' => 'Uređeni naslov kategorija']);

        $service = app(HerreraHomeWidgetsService::class);
        $this->assertTrue($service->apply());
        $this->assertSame('all', $category->slots()->sole()->frontend_variant);
        $this->assertSame(25, $category->slots()->sole()->sort_order);
        $this->assertSame('Uređeni naslov kategorija', $category->translations()->sole()->title);
        $this->assertSame('home.before_products', $blog->slots()->sole()->placement);
        $this->assertFalse($blog->slots()->sole()->is_active);
        $this->assertSame(['blog_source' => 'featured'], $blog->fresh()->payload);

        $category->slots()->update(['frontend_variant' => 'desktop', 'sort_order' => 80]);
        $blog->delete();
        ContentBlock::query()->where('code', 'herrera-home-brands')->update(['is_active' => false]);

        $this->assertFalse($service->apply());
        $this->assertSame('desktop', $category->slots()->sole()->frontend_variant);
        $this->assertSame(80, $category->slots()->sole()->sort_order);
        $this->assertDatabaseMissing('content_blocks', ['code' => 'herrera-home-blog']);
        $this->assertDatabaseHas('content_blocks', ['code' => 'herrera-home-brands', 'is_active' => false]);
    }

    public function test_authored_replacement_widgets_keep_their_own_slots_and_prevent_duplicates(): void
    {
        $this->block('authored-hero', 'hero_single', 'home.hero', ['frontend_variant' => 'desktop']);
        $this->block('authored-categories', 'featured_categories', 'home.categories', ['is_active' => false]);
        $this->block('authored-brands', 'popular_brands', 'home.before_products');
        $this->block('authored-blog', 'blogs_carousel', 'home.bottom');

        $this->assertTrue(app(HerreraHomeWidgetsService::class)->apply());
        $this->assertSame(['herrera-home-featured-products', 'herrera-home-popular-products', 'herrera-home-registration'],
            ContentBlock::query()->where('code', 'like', 'herrera-home-%')->orderBy('code')->pluck('code')->all());
        $this->assertDatabaseHas('content_block_slots', ['placement' => 'home.hero', 'frontend_variant' => 'desktop']);
        $this->assertDatabaseHas('content_block_slots', ['placement' => 'home.categories', 'is_active' => false]);
    }

    public function test_setup_respects_a_scheduled_category_slot_and_previously_removed_blog(): void
    {
        $category = $this->block('herrera-home-category-catalog', 'featured_categories', 'home.categories', [
            'frontend_variant' => 'desktop', 'starts_at' => now()->addDay(),
        ], ['category_source' => 'all_root']);
        app(SystemSettingsService::class)->put(HerreraHomeBlogCarouselService::APPLIED_KEY, true);

        $this->assertTrue(app(HerreraHomeWidgetsService::class)->apply());
        $this->assertSame('desktop', $category->slots()->sole()->frontend_variant);
        $this->assertDatabaseMissing('content_blocks', ['code' => 'herrera-home-blog']);
    }

    private function block(string $code, string $type, string $placement, array $slot = [], array $payload = []): ContentBlock
    {
        $block = ContentBlock::query()->create(['code' => $code, 'name' => $code, 'type' => $type, 'is_active' => true, 'payload' => $payload]);
        $block->slots()->create($slot + ['placement' => $placement, 'frontend_variant' => 'all', 'sort_order' => 0, 'is_active' => true]);

        return $block;
    }
}
