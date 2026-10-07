<?php

namespace Tests\Feature\Front;

use App\Models\Catalog\Option\Option;
use App\Models\Catalog\Option\OptionValue;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAccountQuickOrderFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => true, 'commerce.b2b_display_net' => true]);
    }

    public function test_dashboard_counts_all_orders_and_keeps_cancelled_totals_and_other_customers_out(): void
    {
        [$buyer, $account] = $this->buyer();
        for ($index = 1; $index <= 8; $index++) {
            $this->order($buyer, 'ORD-'.$index, 10);
        }
        $cancelled = OrderStatus::query()->create(['code' => 'cancelled', 'name' => 'Otkazano', 'is_cancelled' => false, 'is_active' => true]);
        $this->order($buyer, 'CANCELLED', 1000, ['status_id' => $cancelled->id]);
        $this->order($buyer, 'USD', 40, ['currency_code' => 'USD']);
        $this->order(User::factory()->create(), 'OTHER', 9999);
        $account->update(['quick_order_draft' => [['product_id' => 1, 'quantity' => 3]]]);

        $this->actingAs($buyer)->get(route('account.dashboard'))->assertOk()
            ->assertViewHas('orderCount', 10)
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 6)
            ->assertViewHas('orderTotals', fn ($totals) => (float) $totals->firstWhere('currency_code', 'EUR')->total === 80.0
                && (float) $totals->firstWhere('currency_code', 'USD')->total === 40.0)
            ->assertViewHas('draftItemCount', 1)
            ->assertSee('Nastavi narudžbu (1 stavka)')->assertSee('Ponovi zadnju narudžbu');
    }

    public function test_bulk_resolves_exact_identifiers_merges_quantities_and_returns_per_line_errors(): void
    {
        [$buyer, , $group] = $this->buyer();
        $product = $this->product('BULK', 11, ['minimum_order_quantity' => 2, 'order_quantity_step' => 3]);
        ProductGroupPrice::query()->create(['product_id' => $product->id, 'customer_group_id' => $group->id, 'price' => 7.50, 'minimum_quantity' => 1, 'currency_code' => 'EUR', 'is_active' => true]);
        $soldOut = $this->product('EMPTY', 0);
        $response = $this->actingAs($buyer)->postJson(route('account.b2b.quick-order.resolve'), [
            'lines' => "BULK; 2\nBULK\t2\nBULK-SKU; 99\nNOT-FOUND; 1\nEMPTY; 2\nBULK; 0\nBULK; 1; extra",
        ])->assertOk()->assertJsonCount(1, 'items')->assertJsonCount(4, 'errors')
            ->assertJsonPath('items.0.product_id', $product->id)
            ->assertJsonPath('items.0.quantity', 11)
            ->assertJsonPath('items.0.maximum_quantity', 11)
            ->assertJsonPath('items.0.is_b2b_price', true)
            ->assertJsonPath('items.0.display_includes_tax', false)
            ->assertJsonPath('errors.0.line', 4)->assertJsonPath('errors.1.identifier', $soldOut->code);
        $this->assertGreaterThan(0, count($response->json('warnings')));
        $this->assertEmpty($buyer->b2bAccount->fresh()->quick_order_draft);
        $this->assertEmpty(app(CartService::class)->lines());
    }

    public function test_bulk_requires_variant_sku_and_preserves_the_selected_variant_and_supplier_stock(): void
    {
        [$buyer] = $this->buyer();
        $product = $this->product('SHIRT', 20);
        $variant = $this->variant($product, 'SHIRT-XL', 5);
        $supplier = $this->product('SUPPLIER', 0, ['supplier_stock_qty' => 9]);
        $this->actingAs($buyer)->postJson(route('account.b2b.quick-order.resolve'), ['lines' => "SHIRT; 1\nSHIRT-XL; 3\nSUPPLIER; 4"])
            ->assertOk()->assertJsonCount(2, 'items')->assertJsonCount(1, 'errors')
            ->assertJsonPath('items.0.product_option_value_id', $variant->id)
            ->assertJsonPath('items.0.quantity', 3)
            ->assertJsonPath('items.1.product_id', $supplier->id)
            ->assertJsonPath('items.1.maximum_quantity', 9)
            ->assertJsonPath('errors.0.identifier', 'SHIRT');
    }

    public function test_bulk_is_private_and_bounded(): void
    {
        $this->postJson(route('account.b2b.quick-order.resolve'), ['lines' => 'SECRET; 1'])->assertUnauthorized();
        $unapproved = User::factory()->create();
        $this->actingAs($unapproved)->postJson(route('account.b2b.quick-order.resolve'), ['lines' => 'SECRET; 1'])->assertForbidden();
        [$buyer] = $this->buyer();
        $this->actingAs($buyer)->postJson(route('account.b2b.quick-order.resolve'), ['lines' => implode("\n", array_fill(0, 101, 'ITEM; 1'))])
            ->assertUnprocessable()->assertJsonValidationErrors('lines');
    }

    public function test_product_shortcut_merges_into_a_saved_draft_and_variant_shortcut_opens_search(): void
    {
        [$buyer, $account] = $this->buyer();
        $existing = $this->product('DRAFT', 10);
        $added = $this->product('NEW', 10);
        $variantProduct = $this->product('VARIANTS', 10);
        $this->variant($variantProduct, 'VARIANT-XL', 4);
        $account->update(['quick_order_draft' => [['product_id' => $existing->id, 'quantity' => 2]]]);
        $this->actingAs($buyer)->get(route('account.b2b.quick-order', ['code' => $added->sku]))->assertOk()
            ->assertViewHas('initialQuickOrderItems', fn ($items) => $items->pluck('product_id')->all() === [$existing->id, $added->id]);
        $this->get(route('account.b2b.quick-order', ['code' => $variantProduct->sku]))->assertOk()
            ->assertViewHas('initialQuickOrderItems', fn ($items) => $items->pluck('product_id')->all() === [$existing->id])
            ->assertViewHas('initialQuickOrderQuery', $variantProduct->sku);
    }

    public function test_suggestions_are_personal_keep_variants_and_exclude_cancelled_or_unavailable_items(): void
    {
        [$buyer] = $this->buyer();
        $product = $this->product('PERSONAL', 10);
        $variant = $this->variant($product, 'PERSONAL-XL', 10);
        $cancelledProduct = $this->product('CANCELLED-ONLY', 10);
        $foreignProduct = $this->product('FOREIGN', 10);
        $soldOut = $this->product('SOLD-OUT', 0);
        $order = $this->order($buyer, 'PERSONAL-ORDER', 10);
        $this->item($order, $product, 4, $variant);
        $this->item($order, $soldOut, 100);
        $cancelled = OrderStatus::query()->create(['code' => 'legacy-cancel', 'name' => ' Otkazano ', 'is_cancelled' => false, 'is_active' => true]);
        $this->item($this->order($buyer, 'CANCEL-ORDER', 10, ['status_id' => $cancelled->id]), $cancelledProduct, 100);
        $this->item($this->order(User::factory()->create(), 'FOREIGN-ORDER', 10), $foreignProduct, 100);
        $buyer->wishlistItems()->create(['product_id' => $product->id]);
        $this->actingAs($buyer)->get(route('account.b2b.quick-order'))->assertOk()
            ->assertViewHas('quickOrderSuggestions', fn ($suggestions) => $suggestions['frequent']->pluck('product_option_value_id')->all() === [$variant->id]
                && $suggestions['recent']->pluck('product_id')->all() === [$product->id]
                && $suggestions['favorites']->sole()['requires_variant'] === true)
            ->assertSee('data-quick-order-import', false)->assertSee('data-quick-order-clear', false)
            ->assertDontSee('Cijene artikala iskazane su bez PDV-a.');
    }

    public function test_successful_add_clears_the_draft_and_partial_add_retains_skipped_items(): void
    {
        [$buyer, $account] = $this->buyer();
        $product = $this->product('AVAILABLE', 20);
        $inactive = $this->product('RETIRED', 20, ['is_active' => false]);
        $items = [['product_id' => $product->id, 'quantity' => 2], ['product_id' => $inactive->id, 'quantity' => 2]];
        $account->update(['quick_order_draft' => $items]);
        $this->actingAs($buyer)->post(route('account.b2b.quick-order.store'), ['items' => $items])->assertRedirect(route('cart.index'));
        $this->assertSame($inactive->id, $account->fresh()->quick_order_draft[0]['product_id']);
        $this->assertSame(2, app(CartService::class)->lines()->sole()['quantity']);
        $this->post(route('account.b2b.quick-order.store'), ['items' => [['product_id' => $product->id, 'quantity' => 1]]])->assertRedirect(route('cart.index'));
        $this->assertNull($account->fresh()->quick_order_draft);
    }

    private function buyer(): array
    {
        $user = User::factory()->create();
        $group = CustomerGroup::query()->firstOrCreate(['code' => 'quick-order-group'], ['name' => 'B2B', 'is_active' => true]);
        $user->customerGroups()->attach($group);
        $account = B2BAccount::query()->create(['user_id' => $user->id, 'status' => B2BAccount::STATUS_APPROVED, 'company_name' => 'Test d.o.o.', 'oib' => '12345678901', 'customer_group_id' => $group->id]);

        return [$user, $account, $group];
    }

    private function product(string $code, int $stock, array $attributes = []): Product
    {
        $product = Product::query()->create([...['code' => $code, 'sku' => $code.'-SKU', 'base_price' => 20, 'stock_qty' => $stock, 'is_active' => true], ...$attributes]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Artikl '.$code, 'slug' => strtolower($code)]);

        return $product;
    }

    private function variant(Product $product, string $sku, int $stock): ProductOptionValue
    {
        $option = Option::query()->create(['code' => 'size-'.$product->id, 'type' => 'radio', 'is_active' => true]);
        $value = OptionValue::query()->create(['option_id' => $option->id, 'code' => 'size-xl-'.$product->id, 'is_active' => true]);

        return $product->optionValues()->create(['option_value_id' => $value->id, 'sku' => $sku, 'mode' => 'variant', 'stock_qty' => $stock, 'is_active' => true, 'combination_hash' => hash('sha256', $sku)]);
    }

    private function order(User $user, string $number, float $total, array $attributes = []): Order
    {
        return Order::query()->create([...['user_id' => $user->id, 'order_number' => $number, 'source' => 'web', 'locale' => 'hr', 'currency_code' => 'EUR', 'currency_rate' => 1, 'grand_total' => $total, 'customer_name' => $user->name, 'customer_email' => $user->email], ...$attributes]);
    }

    private function item(Order $order, Product $product, int $quantity, ?ProductOptionValue $variant = null): void
    {
        $order->items()->create(['product_id' => $product->id, 'product_option_value_id' => $variant?->id, 'name' => $product->code, 'quantity' => $quantity, 'unit_price' => 20, 'line_total' => $quantity * 20]);
    }
}
