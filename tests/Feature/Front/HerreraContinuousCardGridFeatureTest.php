<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HerreraContinuousCardGridFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
    }

    public function test_home_carousel_and_catalog_grids_use_continuous_frames_without_removing_cards(): void
    {
        $categories = collect(range(1, 7))->map(fn ($index) => $this->category($index));
        $products = collect(range(1, 7))->map(function ($index) use ($categories) {
            $product = $this->product($index);
            $product->categories()->attach($categories->first(), ['is_primary' => true]);

            return $product;
        });
        $home = $this->get(route('home'))->assertOk()->assertDontSee('9876.54', false);
        $xpath = $this->xpath($home->getContent());
        $this->assertSame(7, $xpath->query('//*[@data-herrera-home-products-splide and @data-continuous-card-carousel]//*[@data-product-card]')->count());
        $this->assertSame(7, $xpath->query('//*[@data-herrera-category-module]//*[@data-category-card]')->count());

        foreach ([route('shop.index'), route('categories.show', ['slug' => 'continuous-category-1'])] as $url) {
            $response = $this->get($url)->assertOk()->assertDontSee('9876.54', false)
                ->assertDontSee('data-product-card-form', false)->assertSee('data-wishlist-form', false);
            $this->assertSame($products->count(), $this->xpath($response->getContent())
                ->query('//*[@data-catalog-grid and @data-continuous-card-grid]/*[@data-product-card]')->count());
        }
        $directory = $this->get(route('categories.index'))->assertOk()->assertSee('data-herrera-category-module', false);
        $this->assertSame(7, $this->xpath($directory->getContent())->query('//*[@data-herrera-category-module]//*[@data-category-card]')->count());
    }

    public function test_related_product_carousel_preserves_cards_and_swipe_features_with_continuous_dividers(): void
    {
        $related = $this->product(2);
        $product = $this->product(1);
        $product->update(['payload' => ['related_product_ids' => [$related->id]]]);
        $response = $this->get(route('products.show', ['slug' => 'continuous-product-1']))->assertOk()
            ->assertSee('data-related-products-splide', false)
            ->assertSee('data-continuous-card-carousel', false)
            ->assertDontSee('9876.54', false)->assertDontSee('data-product-card-form', false);
        $this->assertSame(1, $this->xpath($response->getContent())
            ->query('//*[@data-related-products-splide and @data-continuous-card-carousel]//*[@data-product-card]')->count());
    }

    public function test_grid_styles_draw_single_dividers_and_frame_without_count_dependent_last_row_rules(): void
    {
        $css = file_get_contents(public_path('front-theme/styles/herrera.css'));
        $this->assertMatchesRegularExpression('/\.herrera-storefront \[data-continuous-card-grid\]\s*\{[^}]*gap:\s*0;[^}]*border:\s*0;/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \[data-continuous-card-grid\]::after,[^{]*\{[^}]*border:\s*1px solid #e2eaec;[^}]*pointer-events:\s*none;/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \[data-continuous-card-grid\] > article\s*\{[^}]*border:\s*0;[^}]*border-right:\s*1px solid #e2eaec;[^}]*border-bottom:\s*1px solid #e2eaec;[^}]*border-radius:\s*0;[^}]*box-shadow:\s*none;/s', $css);
        $this->assertMatchesRegularExpression('/\.herrera-storefront \[data-product-card\]\s*\{[^}]*border:\s*0;[^}]*border-radius:\s*0;[^}]*box-shadow:\s*none;/s', $css);
        $this->assertStringNotContainsString('nth-last', $css);
    }

    private function product(int $index): Product
    {
        $product = Product::query()->create(['code' => 'CONTINUOUS-'.$index, 'is_active' => true, 'base_price' => 9876.54, 'stock_qty' => 3]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Continuous product '.$index, 'slug' => 'continuous-product-'.$index]);

        return $product;
    }

    private function category(int $index): Category
    {
        $category = Category::query()->create(['code' => 'continuous-category-'.$index, 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'scope' => Category::SCOPE_CATALOG,
            'name' => 'Continuous category '.$index, 'slug' => 'continuous-category-'.$index]);

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
