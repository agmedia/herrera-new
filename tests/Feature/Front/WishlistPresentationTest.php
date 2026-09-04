<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WishlistPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_wishlist_uses_the_shared_lined_catalog_product_card(): void
    {
        $product = Product::query()->create([
            'code' => 'wishlist-card-product',
            'sku' => 'WISHLIST-CARD-1',
            'is_active' => true,
            'base_price' => 49.99,
            'stock_qty' => 10,
        ]);

        $this->post(route('wishlist.items.store', ['product' => $product]))
            ->assertRedirect();

        $this->get(route('wishlist.index'))
            ->assertOk()
            ->assertSee('front-theme/styles/category-catalog.css', false)
            ->assertSee('class="storefront-container px-3 sm:px-4 lg:px-6"', false)
            ->assertSee('class="storefront-container px-3 py-6 sm:px-4 lg:px-6"', false)
            ->assertSee('class="catalog-lined-grid', false)
            ->assertSee('data-product-card-lined', false)
            ->assertSee('class="product-card-lined-content', false);
    }
}
