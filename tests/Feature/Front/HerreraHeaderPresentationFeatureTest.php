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
