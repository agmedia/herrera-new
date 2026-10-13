<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Models\Content\ContentBlock;
use App\Models\Sales\Order\Order;
use App\Services\Content\ContentBlockResolver;
use App\Services\Import\HerreraHomeBlogCarouselService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraHomeBlogCarouselTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'commerce.b2b_only' => true, 'storefront_cache.enabled' => false,
            'legacy_media.enabled' => true, 'legacy_media.origin' => 'https://legacy.example.test',
        ]);
        app(SystemSettingsService::class)->putMany(['store_brand_name' => 'Herrera', 'store_newsletter_enabled' => true]);
    }

    public function test_existing_cms_carousel_renders_published_active_articles_after_home_sections_and_before_newsletter(): void
    {
        $this->block('carousel-hero', 'home.hero', 'Hero');
        $this->block('earlier-home-section', 'home.after_products', 'Earlier section');
        $this->block('previous-bottom-section', 'home.bottom', 'Existing bottom section', 40, 'all');
        $this->popularProduct();
        $static = $this->article('visible-static', 'Obnovljeni članak', now()->subDay()->toDateTimeString(), [
            'herrera_cover_path' => 'assets/blog/herrera/bella-v1.jpg',
        ]);
        $legacy = $this->article('visible-legacy', 'Članak s izvornom slikom', now()->subDays(2)->toDateTimeString(), [
            'opencart' => ['image' => 'catalog/blog/legacy-cover.jpg'],
        ]);
        $this->article('hidden-future', 'Budući članak', now()->addDay()->toDateTimeString());
        $inactive = $this->article('hidden-inactive', 'Neaktivan članak', now()->subHour()->toDateTimeString());
        $inactive->update(['is_active' => false]);

        $this->assertTrue(app(HerreraHomeBlogCarouselService::class)->apply());
        $block = ContentBlock::query()->where('code', 'herrera-home-blog')->sole();
        $this->assertSame('blogs_carousel', $block->type);
        $this->assertSame(['all'], $block->slots()->pluck('frontend_variant')->all());
        $this->assertSame(['home.bottom'], $block->slots()->pluck('placement')->unique()->all());
        $this->assertTrue($block->slots()->get()->every(fn ($slot): bool => $slot->sort_order > 40));

        $response = $this->get(route('home'))->assertOk()
            ->assertSee('Savjeti i novosti')->assertSee('data-post-count="2"', false)
            ->assertDontSee('Budući članak')->assertDontSee('Neaktivan članak');
        $xpath = $this->xpath($response->getContent());
        $sections = $xpath->query('//*[@data-herrera-popular-products] | //*[contains(concat(" ", normalize-space(@class), " "), " earlier-home-section ")]'
            .' | //*[contains(concat(" ", normalize-space(@class), " "), " previous-bottom-section ")]'
            .' | //*[contains(concat(" ", normalize-space(@class), " "), " herrera-home-blog ")]'
            .' | //*[contains(concat(" ", normalize-space(@class), " "), " site-footer-newsletter ")]');
        $order = [];
        foreach ($sections as $section) {
            $order[] = $section->hasAttribute('data-herrera-popular-products') ? 'popular-products' : $section->getAttribute('class');
        }
        $this->assertSame(['earlier-home-section', 'popular-products', 'previous-bottom-section', 'herrera-home-blog', 'site-footer-newsletter'], $order);
        $carousel = '//*[contains(concat(" ", normalize-space(@class), " "), " herrera-home-blog ")]';
        $this->assertSame(1, $xpath->query($carousel.'//a[@href="/blog"]')->count());
        $this->assertSame(1, $xpath->query($carousel.'//a[@href="'.route('blog.show', ['slug' => $static->code]).'"]//img[@src="'.asset('assets/blog/herrera/bella-v1.jpg').'"]')->count());
        $this->assertSame(1, $xpath->query($carousel.'//a[@href="'.route('blog.show', ['slug' => $legacy->code]).'"]//img[@src="https://legacy.example.test/image/catalog/blog/legacy-cover.jpg"]')->count());
    }

    public function test_repeated_setup_preserves_admin_content_and_deactivation_without_duplicate_blocks_or_slots(): void
    {
        $service = app(HerreraHomeBlogCarouselService::class);
        $this->assertTrue($service->apply());
        $block = ContentBlock::query()->where('code', 'herrera-home-blog')->sole();
        $block->update(['is_active' => false, 'payload' => ['items_limit' => 2, 'blog_source' => 'featured']]);
        $block->translations()->where('locale', 'hr')->update(['title' => 'Administratorov izbor']);
        $block->slots()->where('frontend_variant', 'all')->update(['is_active' => false, 'sort_order' => 99]);

        $this->assertFalse($service->apply());
        $this->assertSame(1, ContentBlock::query()->where('code', 'herrera-home-blog')->count());
        $this->assertSame(1, $block->slots()->count());
        $this->assertSame(2, $block->translations()->count());
        $this->assertFalse($block->fresh()->is_active);
        $this->assertSame(['items_limit' => 2, 'blog_source' => 'featured'], $block->fresh()->payload);
        $this->assertSame('Administratorov izbor', $block->translations()->where('locale', 'hr')->sole()->title);
        $slot = $block->slots()->where('frontend_variant', 'all')->sole();
        $this->assertFalse($slot->is_active);
        $this->assertSame(99, $slot->sort_order);
        ContentBlockResolver::bumpCacheVersion();
        $this->get(route('home'))->assertOk()->assertDontSee('data-latest-news', false)->assertDontSee('Administratorov izbor');
    }

    public function test_inactive_scheduled_or_targeted_specific_slots_do_not_hide_active_shared_bottom_content(): void
    {
        $this->block('carousel-hero', 'home.hero', 'Hero');
        $this->block('shared-bottom', 'home.bottom', 'Shared footer content', 10, 'all');
        foreach ([
            'inactive-slot' => ['is_active' => false],
            'future-slot' => ['starts_at' => now()->addDay()],
            'expired-slot' => ['ends_at' => now()->subDay()],
            'targeted-slot' => ['target_type' => 'category', 'target_ref' => '123'],
        ] as $code => $attributes) {
            $this->block($code, 'home.bottom', $code, 20);
            ContentBlock::query()->where('code', $code)->sole()->slots()->update($attributes);
        }
        $this->block('inactive-block', 'home.bottom', 'Inactive block', 20);
        ContentBlock::query()->where('code', 'inactive-block')->update(['is_active' => false]);

        $this->assertTrue(app(HerreraHomeBlogCarouselService::class)->apply());
        $carousel = ContentBlock::query()->where('code', 'herrera-home-blog')->sole();
        $this->assertSame(['all'], $carousel->slots()->pluck('frontend_variant')->all());
        $resolved = app(ContentBlockResolver::class)->forPlacement('home.bottom', 'hr', null, null, 'desktop');
        $this->assertSame(['shared-bottom', 'herrera-home-blog'], $resolved->pluck('block.code')->all());
        $this->get(route('home'))->assertOk()->assertSee('Shared footer content')->assertSee('data-latest-news', false)
            ->assertDontSee('Inactive block');
    }

    private function block(string $code, string $placement, string $title, int $sort = 0, string $variant = 'desktop'): void
    {
        $block = ContentBlock::query()->create(['code' => $code, 'name' => $title, 'type' => 'rich_text', 'is_active' => true]);
        $block->translations()->create(['locale' => 'hr', 'title' => $title, 'payload' => ['custom_classes' => $code]]);
        $block->slots()->create(['placement' => $placement, 'frontend_variant' => $variant, 'sort_order' => $sort, 'is_active' => true]);
    }

    private function article(string $code, string $title, string $publishedAt, array $payload = []): BlogPost
    {
        $post = BlogPost::query()->create(['code' => $code, 'published_at' => $publishedAt, 'is_active' => true, 'payload' => $payload]);
        $post->translations()->create(['locale' => 'hr', 'title' => $title, 'slug' => $code, 'excerpt' => 'Sažetak članka', 'body_html' => '<p>Tekst članka</p>']);

        return $post;
    }

    private function popularProduct(): void
    {
        $product = Product::query()->create(['code' => 'carousel-popular', 'is_active' => true, 'stock_qty' => 5, 'base_price' => 10]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Popularni artikl', 'slug' => 'carousel-popular']);
        $order = Order::query()->create(['order_number' => 'carousel-order', 'currency_code' => 'EUR', 'customer_name' => 'Test', 'customer_email' => 'carousel@example.test', 'grand_total' => 10, 'placed_at' => now()->subDay(), 'paid_at' => now()]);
        $order->items()->create(['product_id' => $product->id, 'sku' => 'carousel-popular', 'name' => 'Popularni artikl', 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10]);
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }
}
