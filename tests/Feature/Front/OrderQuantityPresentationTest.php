<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderQuantityPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_cards_and_cart_start_with_the_imported_pack_quantity_and_keep_unit_prices(): void
    {
        config(['commerce.b2b_only' => false]);
        $product = Product::query()->create([
            'code' => 'BAT-PACK', 'sku' => 'BAT-PACK', 'is_active' => true, 'base_price' => 2.5,
            'stock_qty' => 100, 'minimum_order_quantity' => 4, 'order_quantity_step' => 4, 'unit_of_measure' => 'pcs',
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Baterija u pakiranju', 'slug' => 'baterija-u-pakiranju']);
        $product->translations()->create(['locale' => 'en', 'name' => 'Battery pack', 'slug' => 'battery-pack']);
        foreach ([route('products.show', ['slug' => 'baterija-u-pakiranju']), route('shop.index')] as $url) {
            $response = $this->get($url)->assertOk()->assertSee('data-product-quantity-rules', false)->assertSee('order-quantity.js', false);
            $inputs = $this->quantityInputs($response->getContent());
            $this->assertGreaterThan(0, $inputs->count());
            foreach ($inputs as $input) {
                $this->assertSame('4', $input->getAttribute('value'));
                $this->assertSame('4', $input->getAttribute('min'));
                $this->assertSame('4', $input->getAttribute('step'));
                $this->assertSame('999', $input->getAttribute('max'));
            }
        }
        $this->postJson(route('cart.items.store'), ['product_id' => $product->id, 'quantity' => 4])->assertOk();
        $cart = $this->get(route('cart.index'))->assertOk()->assertSee('data-product-quantity-rules', false);
        $inputs = $this->quantityInputs($cart->getContent());
        $this->assertSame(2, $inputs->count());
        foreach ($inputs as $input) {
            $this->assertSame('4', $input->getAttribute('value'));
            $this->assertSame('4', $input->getAttribute('min'));
            $this->assertSame('4', $input->getAttribute('step'));
        }
        $line = app(\App\Services\Front\CartService::class)->lines()->sole();
        $this->assertSame(2.5, (float) $line['unit_price']);
        $this->assertSame(10.0, (float) $line['line_total']);
    }

    private function quantityInputs(string $html): \DOMNodeList
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return (new \DOMXPath($document))->query('//input[@name="quantity"]');
    }
}
