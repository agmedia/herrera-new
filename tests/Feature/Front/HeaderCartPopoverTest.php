<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeaderCartPopoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_desktop_header_loads_the_external_cart_popover_assets(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-header-cart', false)
            ->assertSee('data-header-cart-popover', false)
            ->assertSee('front-theme/styles/header-cart-popover.css', false)
            ->assertSee('front-theme/scripts/header-cart-popover.js', false);
    }

    public function test_cart_preview_renders_all_lines_inside_the_scrollable_item_list(): void
    {
        $products = collect([
            $this->makeProduct('Hover proizvod jedan'),
            $this->makeProduct('Hover proizvod dva'),
            $this->makeProduct('Hover proizvod tri'),
        ]);

        $products->each(function (Product $product): void {
            $this->postJson(route('cart.items.store'), [
                'product_id' => $product->id,
                'quantity' => 1,
            ])->assertOk();
        });

        $response = $this->get(route('cart.preview'))->assertOk();

        foreach ($products as $product) {
            $response->assertSee($product->translations->first()->name);
        }

        $this->assertSame(3, substr_count($response->getContent(), 'class="header-cart-item"'));
        $response
            ->assertSee('class="header-cart-items"', false)
            ->assertSee('data-header-cart-remove', false)
            ->assertSee(__('ui.cart.preview.view_cart'));
    }

    public function test_cart_item_can_be_removed_from_the_popover_without_a_page_reload(): void
    {
        $product = $this->makeProduct('Artikl za uklanjanje');

        $this->postJson(route('cart.items.store'), [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertOk();

        $this->deleteJson(route('cart.items.destroy', ['product' => $product->id]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('summary.item_qty', 0);

        $this->get(route('cart.preview'))
            ->assertOk()
            ->assertSee(__('ui.cart.empty'))
            ->assertDontSee('Artikl za uklanjanje');
    }

    private function makeProduct(string $name): Product
    {
        $product = Product::query()->create([
            'code' => str($name)->slug()->value(),
            'sku' => 'HOVER-'.strtoupper((string) str()->random(6)),
            'is_active' => true,
            'base_price' => 49.99,
            'stock_qty' => 10,
        ]);

        ProductTranslation::query()->create([
            'product_id' => $product->id,
            'locale' => 'hr',
            'name' => $name,
            'slug' => str($name)->slug()->value().'-'.$product->id,
            'excerpt' => null,
            'description' => null,
        ]);

        return $product->load('translations');
    }
}
