<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManyItemCartTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('cartSizes')]
    public function test_large_cart_keeps_every_line_and_quantity_in_preview_and_checkout(int $count, bool $b2b): void
    {
        config(['commerce.b2b_only' => $b2b]);
        app(SystemSettingsService::class)->put('store_brand_name', 'Herrera');
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'standard', 'name' => 'Standard', 'price' => 0, 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'bank', 'name' => 'Bank', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);

        if ($b2b) {
            $customer = User::factory()->create();
            $group = CustomerGroup::query()->create(['code' => 'bulk-buyers', 'name' => 'Bulk buyers', 'is_active' => true]);
            B2BAccount::query()->create(['user_id' => $customer->id, 'company_name' => 'Test business', 'oib' => '12345678901', 'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id]);
            $this->actingAs($customer);
        }

        $items = [];
        $expectedQuantity = 0;
        for ($number = 1; $number <= $count; $number++) {
            $product = Product::query()->create(['code' => 'BULK-'.$number, 'sku' => 'BULK-'.$number, 'is_active' => true, 'base_price' => 2, 'stock_qty' => 1000]);
            $product->translations()->create(['locale' => 'hr', 'name' => 'Instalacijski pribor '.$number.' za vanjsku montažu i razvodnu kutiju IP65', 'slug' => 'bulk-'.$number]);
            $quantity = $number % 7 + 1;
            $items[$product->id.':0'] = ['product_id' => $product->id, 'product_option_value_id' => null, 'quantity' => $quantity];
            $expectedQuantity += $quantity;
        }
        $this->withSession(['front.cart.items' => $items]);

        $preview = $this->get(route('cart.preview'))->assertOk();
        $this->assertSame($count, substr_count($preview->getContent(), 'role="listitem"'));
        $preview->assertSee('Instalacijski pribor 1 za vanjsku montažu')->assertSee('bulk-'.$count, false);

        $checkout = $this->get(route('checkout.create'))->assertOk();
        $checkout->assertViewHas('summary', fn (array $summary): bool => $summary['line_count'] === $count
            && $summary['item_qty'] === $expectedQuantity
            && $summary['subtotal'] === (float) ($expectedQuantity * 2));
        $checkout->assertViewHas('lines', function ($lines) use ($items, $count): bool {
            return $lines->count() === $count && $lines->every(fn (array $line): bool => $line['quantity'] === $items[$line['key']]['quantity']);
        });
        $this->assertSame($count, substr_count($checkout->getContent(), 'class="checkout-summary-line '));
        $checkout->assertSessionHas('front.cart.items', $items);

        // Removing a deep item must keep all other lines and the full total.
        $removed = array_values($items)[$count - 2];
        $this->deleteJson(route('cart.items.destroy', ['product' => $removed['product_id']]))
            ->assertOk()->assertJsonPath('summary.line_count', $count - 1)
            ->assertJsonPath('summary.item_qty', $expectedQuantity - $removed['quantity']);
        $this->get(route('checkout.create'))->assertOk()
            ->assertViewHas('lines', fn ($lines): bool => $lines->count() === $count - 1
                && ! $lines->contains(fn (array $line): bool => $line['product']->id === $removed['product_id']));
    }

    public static function cartSizes(): array
    {
        return ['20 retail' => [20, false], '100 retail' => [100, false], '20 B2B' => [20, true], '100 B2B' => [100, true]];
    }
}
