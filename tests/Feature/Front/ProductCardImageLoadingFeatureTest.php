<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Category\Category;
use App\Models\Catalog\Product\Product;
use App\Services\Settings\SystemSettingsService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCardImageLoadingFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_and_shop_keep_only_the_first_image_prioritized_and_every_frame_reserved(): void
    {
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        config(['legacy_media.enabled' => true, 'legacy_media.origin' => 'https://www.herrera.hr']);
        $category = Category::query()->create(['code' => 'image-loading', 'scope' => Category::SCOPE_CATALOG, 'is_active' => true]);
        $category->translations()->create(['locale' => 'hr', 'name' => 'Image loading', 'slug' => 'image-loading']);
        foreach (range(1, 4) as $index) {
            $product = Product::query()->create([
                'code' => 'IMAGE-LOADING-'.$index, 'is_active' => true, 'base_price' => 10,
                'payload' => ['opencart' => ['image' => 'catalog/products/loading-'.$index.'.jpg']],
            ]);
            $product->translations()->create(['locale' => 'hr', 'name' => 'Loading product '.$index, 'slug' => 'loading-product-'.$index]);
            $product->categories()->attach($category->id, ['is_primary' => true]);
        }

        foreach ([route('shop.index'), route('categories.show', ['slug' => 'image-loading'])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $dom = new DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            $images = $xpath->query('//*[@data-catalog-grid]//img[@data-product-card-image]');
            $this->assertSame(4, $images->count());
            foreach ($images as $index => $image) {
                $this->assertSame($index === 0 ? 'eager' : 'lazy', $image->getAttribute('loading'));
                $this->assertSame($index === 0 ? 'high' : '', $image->getAttribute('fetchpriority'));
                $this->assertGreaterThan(0, (int) $image->getAttribute('width'));
                $this->assertGreaterThan(0, (int) $image->getAttribute('height'));
                $this->assertTrue($image->parentNode->hasAttribute('data-product-card-image-frame'));
                $this->assertSame(1, $xpath->query('./span[contains(@class, "product-card-image-placeholder") and @aria-hidden="true"]', $image->parentNode)->count());
                $this->assertFalse($image->parentNode->hasAttribute('data-image-state'));
            }
            $this->assertSame(1, $xpath->query('//script[contains(@src, "product-card-images.js")]')->count());
        }
    }
}
