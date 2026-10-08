<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Content\Blog\BlogPost;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_contains_only_active_canonical_catalog_urls_and_no_prices(): void
    {
        config(['app.url' => 'https://www.herrera.hr', 'app.locale' => 'hr', 'commerce.b2b_only' => true]);
        foreach ([['active', true], ['inactive', false]] as [$code, $active]) {
            $product = Product::query()->create(['code' => $code, 'sku' => $code, 'is_active' => $active, 'base_price' => 137.49]);
            $product->translations()->create(['locale' => 'hr', 'name' => $code, 'slug' => $code]);
        }
        $index = $this->get('/sitemap.xml')->assertOk()->assertSee('https://www.herrera.hr/sitemap-products-1.xml', false);
        $this->assertNotFalse(simplexml_load_string($index->getContent()));
        $part = $this->get('/sitemap-products-1.xml')->assertOk()
            ->assertSee('https://www.herrera.hr/product/active', false)->assertDontSee('/product/inactive', false)->assertDontSee('137.49');
        $this->assertNotFalse(simplexml_load_string($part->getContent()));
        $this->get('/sitemap-products-99.xml')->assertNotFound();
    }

    public function test_robots_blocks_preview_and_uses_own_domain_in_production(): void
    {
        $this->get('/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow: /\n")
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://www.herrera.hr']);
        $this->get('https://www.herrera.hr/robots.txt')->assertOk()
            ->assertSee('Sitemap: https://www.herrera.hr/sitemap.xml', false)
            ->assertDontSee("Disallow: /\n", false)->assertDontSee('videonadzor')
            ->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_known_test_hosts_remain_blocked_even_with_production_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => 'https://www.herrera.hr']);

        foreach (['http://herrera-new.test', 'https://herrera.herrera.hr'] as $origin) {
            $this->get($origin.'/robots.txt')->assertOk()
                ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertContent("User-agent: *\nDisallow: /\n");
            $this->get($origin.'/contact')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->get($origin.'/sitemap.xml')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $this->get('https://www.herrera.hr/contact')->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_staging_environment_blocks_indexing_regardless_of_requested_host(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');
        config(['app.url' => 'https://herrera.herrera.hr']);
        $this->get('https://www.herrera.hr/robots.txt')->assertOk()->assertContent("User-agent: *\nDisallow: /\n");
        $this->get('https://www.herrera.hr/contact')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_blog_sitemap_only_lists_enabled_published_posts(): void
    {
        config(['app.url' => 'https://www.herrera.hr', 'app.locale' => 'hr']);
        app(SystemSettingsService::class)->put('catalog_use_blog', true);
        foreach ([['published', true, now()->subDay()], ['disabled', false, null], ['future', true, now()->addDay()]] as [$code, $active, $published]) {
            $post = BlogPost::query()->create(['code' => $code, 'is_active' => $active, 'published_at' => $published]);
            $post->translations()->create(['locale' => 'hr', 'title' => $code, 'slug' => $code]);
        }
        $this->get('/sitemap.xml')->assertOk()->assertSee('/sitemap-blog-1.xml', false);
        $this->get('/sitemap-blog-1.xml')->assertOk()->assertSee('https://www.herrera.hr/blog/published', false)
            ->assertDontSee('/blog/disabled', false)->assertDontSee('/blog/future', false);
        app(SystemSettingsService::class)->put('catalog_use_blog', false);
        $this->get('/sitemap-blog-1.xml')->assertOk()->assertDontSee('/blog/published', false);
    }
}
