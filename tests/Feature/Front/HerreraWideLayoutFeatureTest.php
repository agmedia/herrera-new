<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Manufacturer\Manufacturer;
use App\Models\Catalog\Product\Product;
use App\Models\Content\ContentBlock;
use App\Services\Front\NavigationMenuService;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HerreraWideLayoutFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->putMany([
            'store_brand_name' => 'Herrera', 'store_product_desktop_default_cols' => 5,
            'store_product_mobile_default_cols' => 2, 'catalog_use_manufacturers' => true,
            NavigationMenuService::APPEARANCE_SETTINGS_KEY => ['container_width' => 1860, 'header_content_width' => 1860],
        ]);
    }

    public function test_all_product_listing_variants_use_wide_layout_with_five_columns_and_existing_filters(): void
    {
        [$category, $manufacturer] = $this->catalog();
        foreach ([
            route('shop.index'),
            route('categories.show', ['slug' => $category->translations->first()->slug]),
            route('manufacturers.show', ['slug' => $manufacturer->translations->first()->slug]),
            route('shop.index', ['q' => 'Wide product']),
        ] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('Wide product')
                ->assertSee('herrera-wide-catalog-main', false)
                ->assertSee('catalog-desktop-sidebar', false)->assertSee('data-mobile-filter-toggle', false)
                ->assertDontSee('data-price-range-root', false)->assertDontSee('9876.54', false)
                ->assertDontSee('data-product-card-form', false);
            $grid = $this->xpath($response->getContent())->query('//*[@data-catalog-grid and @data-continuous-card-grid]')->item(0);
            $this->assertNotNull($grid);
            $this->assertStringContainsString('2xl:grid-cols-5', $grid->getAttribute('class'));
            $this->assertStringContainsString('grid-cols-2', $grid->getAttribute('class'));
        }
    }

    public function test_home_header_slider_categories_products_and_footer_follow_the_same_wide_body(): void
    {
        $this->catalog();
        $response = $this->get(route('home'))->assertOk()->assertSee('herrera-home-main', false)
            ->assertSee('herrera-hero', false)->assertSee('data-herrera-home-products-splide', false)
            ->assertSee('data-herrera-category-module', false)->assertSee('data-herrera-b2b-hero', false)
            ->assertDontSee('9876.54', false)
            ->assertSee('Sve za vaš posao. Na jednom mjestu.')
            ->assertSee(route('account.b2b.quick-order'), false)->assertSee(route('account.orders'), false);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//main[contains(@class, "herrera-home-main")]/section[contains(@class, "herrera-hero")]')->count());
        $this->assertSame(1, $xpath->query('//main[contains(@class, "herrera-home-main")]//*[@data-category-card]')->count());
        $this->assertSame(1, $xpath->query('//main[contains(@class, "herrera-home-main")]//*[@data-product-card]')->count());
        $css = file_get_contents(public_path('front-theme/styles/herrera.css'));
        $this->assertStringContainsString('--herrera-wide-layout-width: var(--storefront-container-width, 1860px)', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.herrera-layout-container\s*\{[^}]*max-width:\s*var\(--herrera-layout-width\);[^}]*padding-right:\s*var\(--herrera-layout-gutter\);/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.herrera-home-main,\s*\.herrera-storefront \.herrera-wide-catalog-main,\s*\.herrera-storefront \.site-footer-shell\s*\{\s*max-width:\s*var\(--herrera-wide-layout-width\);\s*\}/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.store-announcement-shell\.herrera-layout-container\s*\{[^}]*max-width:\s*var\(--herrera-wide-layout-width\);/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \.herrera-home-main\s*\{[^}]*padding-top:\s*24px;/s', $css);
        $this->get(route('front.storefront.styles'))->assertOk()
            ->assertSee('--storefront-container-width:1860px;--header-content-width:1860px;', false);
    }

    public function test_managed_home_categories_render_once_between_the_hero_and_representative_products(): void
    {
        [$category] = $this->catalog();
        $block = ContentBlock::query()->create(['code' => 'managed-herrera-categories', 'name' => 'Managed categories',
            'type' => 'featured_categories', 'is_active' => true]);
        $block->translations()->create(['locale' => 'hr', 'title' => 'Managed categories']);
        $block->items()->create(['item_type' => 'category', 'item_id' => $category->id, 'sort_order' => 0]);
        $block->slots()->create(['placement' => 'home.categories', 'frontend_variant' => 'desktop', 'sort_order' => 0, 'is_active' => true]);

        $response = $this->get(route('home'))->assertOk()
            ->assertSeeInOrder(['data-herrera-b2b-hero', 'Managed categories', 'data-herrera-home-products'], false);
        $xpath = $this->xpath($response->getContent());
        $this->assertSame(1, $xpath->query('//main//*[@data-herrera-category-module]')->count());
        $this->assertSame(1, $xpath->query('//main//*[@data-category-card]')->count());
        $this->assertSame(1, $xpath->query('//main//*[@data-product-card]')->count());
    }

    public function test_user_can_still_select_four_columns_in_the_wide_catalog(): void
    {
        $this->catalog();
        $response = $this->get(route('shop.index', ['cols' => 4]))->assertOk();
        $grid = $this->xpath($response->getContent())->query('//*[@data-catalog-grid]')->item(0);
        $this->assertStringContainsString('xl:grid-cols-4', $grid->getAttribute('class'));
        $this->assertStringNotContainsString('2xl:grid-cols-5', $grid->getAttribute('class'));
    }

    #[DataProvider('unpublishedCategoryModuleStates')]
    public function test_unpublished_managed_category_module_does_not_resurrect_fallback_categories(string $state): void
    {
        $this->catalog();
        $block = ContentBlock::query()->create([
            'code' => 'unpublished-herrera-categories',
            'name' => 'Unpublished categories',
            'type' => 'featured_categories',
            'is_active' => $state !== 'inactive-block',
            'payload' => ['category_source' => 'all_root'],
        ]);
        $block->slots()->create([
            'placement' => 'home.categories',
            'frontend_variant' => 'desktop',
            'sort_order' => 0,
            'is_active' => $state !== 'inactive-slot',
            'starts_at' => $state === 'future-slot' ? now()->addDay() : null,
        ]);

        $response = $this->get(route('home'))->assertOk()
            ->assertSee('data-herrera-b2b-hero', false)
            ->assertSee('data-herrera-home-products', false)
            ->assertDontSee('data-herrera-category-module', false);

        $this->assertSame(0, $this->xpath($response->getContent())->query('//main//*[@data-category-card]')->count());
    }

    public static function unpublishedCategoryModuleStates(): array
    {
        return [
            'inactive block' => ['inactive-block'],
            'inactive slot' => ['inactive-slot'],
            'future slot' => ['future-slot'],
        ];
    }

    public function test_other_brands_keep_the_existing_catalog_layout(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Termol');
        $this->catalog();
        $this->get(route('shop.index'))->assertOk()->assertDontSee('herrera-wide-catalog-main', false)
            ->assertDontSee('herrera-layout-container', false);
    }

    private function catalog(): array
    {
        $category = Category::query()->create(['code' => 'wide-category', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG,
            'name' => 'Wide category', 'slug' => 'wide-category']);
        $manufacturer = Manufacturer::query()->create(['code' => 'wide-manufacturer', 'is_active' => true]);
        $manufacturer->translations()->create(['locale' => 'hr', 'name' => 'Wide manufacturer', 'slug' => 'wide-manufacturer']);
        $product = Product::query()->create(['code' => 'WIDE-PRODUCT', 'is_active' => true, 'base_price' => 9876.54,
            'stock_qty' => 5, 'manufacturer_id' => $manufacturer->id]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Wide product', 'slug' => 'wide-product']);
        $product->categories()->attach($category, ['is_primary' => true]);

        return [$category->load('translations'), $manufacturer->load('translations')];
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
