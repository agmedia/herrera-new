<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Models\Content\Page\InfoPage;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraHeaderPresentationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_herrera_header_has_requested_main_links_separate_catalog_search_and_b2b_actions(): void
    {
        $this->configure();
        $category = $this->category('rasvjeta');
        $this->category('unutarnja-rasvjeta', ['parent_id' => $category->id]);
        $product = Product::query()->create(['code' => 'HEADER-B2B', 'is_active' => true, 'base_price' => 9876.54]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Header test product', 'slug' => 'header-test-product']);
        config(['commerce.b2b_only' => true]);
        $response = $this->get(route('home'))->assertOk()
            ->assertSee('data-herrera-header', false)->assertSee('data-herrera-catalog-navigation', false)
            ->assertSee('data-catalog-mega-trigger', false)->assertSee('data-catalog-mega-tree', false)
            ->assertSee('data-mobile-menu-open', false)->assertSee('data-header-search-form', false)
            ->assertSee('data-autocomplete-enabled="1"', false)->assertSee('data-header-cart-trigger', false)
            ->assertSee('B2B prijava')->assertSee('Unutarnja rasvjeta')
            ->assertDontSee('9876.54', false)->assertDontSee('data-product-card-form', false);

        $xpath = $this->xpath($response->getContent());
        $links = $xpath->query('//*[@data-herrera-primary-navigation]/a');
        $this->assertSame(['Brandovi', 'Akcije', 'O nama', 'Kontakt'],
            array_map(fn ($node): string => trim($node->textContent), iterator_to_array($links)));
        $this->assertSame(route('manufacturers.index'), $links->item(0)->getAttribute('href'));
        $this->assertSame(url('/akcije'), $links->item(1)->getAttribute('href'));
        $this->assertSame(route('account.b2b.quick-order'), $xpath->query('//*[@data-herrera-quick-order]')->item(0)->getAttribute('href'));
        $this->assertSame(1, $xpath->query('//*[@data-header-search-form]')->count());
    }

    public function test_mobile_search_toggle_precedes_account_and_controls_the_single_autocomplete_form(): void
    {
        $this->configure();
        $response = $this->withHeader('User-Agent', 'iPhone Mobile')->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $toggle = $xpath->query('//*[contains(@class, "responsive-header-actions")]/*[1]')->item(0);
        $this->assertTrue($toggle->hasAttribute('data-header-search-toggle'));
        $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
        $this->assertSame('header-search-panel', $toggle->getAttribute('aria-controls'));
        $account = $xpath->query('//*[contains(@class, "responsive-header-actions")]/*[2]')->item(0);
        $this->assertSame(route('front.auth.login'), $account->getAttribute('href'));
        $panel = $xpath->query('//*[@id="header-search-panel"]')->item(0);
        $this->assertFalse($panel->hasAttribute('data-header-search-persistent'));
        $this->assertSame('1023', $panel->getAttribute('data-header-search-breakpoint'));
        $this->assertSame(1, $xpath->query('//*[@data-header-search-form]')->count());
        $this->assertSame('1', $xpath->query('//*[@data-header-search-form]')->item(0)->getAttribute('data-autocomplete-enabled'));
    }

    public function test_header_controls_download_during_html_parsing_and_mobile_height_stays_stable(): void
    {
        $this->configure();
        $response = $this->get(route('home'))->assertOk();
        $xpath = $this->xpath($response->getContent());

        foreach (['desktop-header-menu.js', 'header-search-panel.js'] as $script) {
            $nodes = $xpath->query('//head/script[contains(@src, "'.$script.'")]');
            $this->assertSame(1, $nodes->count());
            $this->assertTrue($nodes->item(0)->hasAttribute('defer'));
        }

        $css = file_get_contents(public_path('front-theme/styles/herrera-b2b.css'));
        $mobileCss = substr($css, strpos($css, '@media (max-width: 1023px)'));
        $this->assertStringContainsString('grid-template-rows: 72px', $mobileCss);
        $this->assertStringNotContainsString('grid-template-rows: 64px', $mobileCss);
    }

    public function test_shared_header_preloads_its_font_on_every_public_page_type(): void
    {
        $this->configure();
        $this->category('rasvjeta');
        app(SystemSettingsService::class)->putMany([
            'catalog_use_manufacturers' => true,
            'catalog_use_blog' => true,
        ]);

        foreach (['/', '/shop', '/categories', '/category/rasvjeta', '/brendovi', '/blog', '/faq',
            '/page/o-nama', '/contact', '/cart', '/wishlist', '/auth/login', '/auth/register',
            '/auth/b2b-register', '/auth/forgot-password'] as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response->status(), $path);
            $xpath = $this->xpath($response->getContent());
            $preloads = $xpath->query('//head/link[@rel="preload" and @as="font"]');
            $this->assertSame(1, $preloads->count(), $path);
            $this->assertStringContainsString('assets/fonts/sora/Sora-Variable.woff2', $preloads->item(0)->getAttribute('href'), $path);
            $this->assertTrue($preloads->item(0)->hasAttribute('crossorigin'), $path);
            $this->assertSame(1, $xpath->query('//*[@data-site-main-header-spacer]')->count(), $path);
            $this->assertSame(1, $xpath->query('//*[@data-herrera-header]')->count(), $path);
        }
    }

    public function test_homepage_displays_every_active_catalog_root_and_no_nested_or_scheduled_root(): void
    {
        $this->configure();
        $roots = collect(range(1, 19))->map(fn ($index) => $this->category('root-'.$index));
        $this->category('nested-hidden-from-home', ['parent_id' => $roots->first()->id]);
        $this->category('inactive-hidden-from-home', ['is_active' => false]);
        $this->category('scheduled-hidden-from-home', ['starts_at' => now()->addDay()]);
        $this->category('blog-hidden-from-home', ['scope' => Category::SCOPE_BLOG]);
        $response = $this->get(route('home'))->assertOk();
        $cards = $this->xpath($response->getContent())->query('//*[@data-herrera-category-module]//*[@data-category-card]');

        $this->assertSame($roots->pluck('id')->map(fn ($id): string => (string) $id)->all(),
            array_map(fn ($node): string => $node->getAttribute('data-category-card'), iterator_to_array($cards)));
        $css = file_get_contents(public_path('front-theme/styles/herrera.css'));
        $this->assertStringContainsString('grid-template-columns: repeat(5, minmax(0, 1fr))', $css);
        $this->assertStringContainsString('object-fit: contain', $css);
    }

    public function test_other_brands_keep_the_existing_header_and_navigation_layout(): void
    {
        $this->configure('Termol');
        $this->get(route('home'))->assertOk()
            ->assertDontSee('data-herrera-header', false)
            ->assertDontSee('data-herrera-primary-navigation', false)
            ->assertDontSee('data-herrera-catalog-navigation', false)
            ->assertSee('site-main-nav-shell', false)->assertSee('data-header-search-form', false);
    }

    private function configure(string $brand = 'Herrera'): void
    {
        $about = InfoPage::query()->create(['code' => 'about', 'is_active' => true]);
        $about->translations()->create(['locale' => 'hr', 'title' => 'O nama', 'slug' => 'o-nama']);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => $brand, 'store_brand_logo_path' => 'assets/brand/herrera-logo.svg',
            'store_brand_logo_optimized_path' => '', 'store_search_autocomplete_enabled' => true,
            NavigationMenuService::SETTINGS_KEY => [
                ['type' => 'catalog', 'label' => 'Svi artikli', 'url' => route('shop.index')],
                ['type' => 'custom', 'label' => 'Brandovi', 'url' => route('manufacturers.index')],
                ['type' => 'custom', 'label' => 'Akcije', 'url' => url('/akcije')],
                ['type' => 'page', 'label' => 'O nama', 'page_id' => $about->id],
                ['type' => 'contact', 'label' => 'Kontakt'],
            ],
        ]);
    }

    private function category(string $slug, array $overrides = []): Category
    {
        $category = Category::query()->create(array_merge([
            'code' => $slug, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true,
        ], $overrides));
        $category->translations()->create(['locale' => 'hr', 'scope' => $category->scope,
            'name' => ucfirst(str_replace('-', ' ', $slug)), 'slug' => $slug]);

        return $category;
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
