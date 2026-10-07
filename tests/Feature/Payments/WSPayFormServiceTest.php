<?php

namespace Tests\Feature\Payments;

use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Services\Payments\WSPayFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WSPayFormServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderStatus $pending;

    private OrderStatus $paid;

    private OrderStatus $cancelled;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.local_safe_mode' => false]);
        $this->pending = OrderStatus::query()->create(['code' => 'new', 'name' => 'New', 'is_default' => true, 'is_active' => true]);
        $this->paid = OrderStatus::query()->create(['code' => 'paid', 'name' => 'Paid', 'is_paid' => true, 'is_active' => true]);
        $this->cancelled = OrderStatus::query()->create(['code' => 'cancelled', 'name' => 'Cancelled', 'is_cancelled' => true, 'is_active' => true]);
    }

    public function test_only_configured_wspay_methods_can_be_offered(): void
    {
        $service = app(WSPayFormService::class);
        $method = $this->method(['wspay_secret_key' => '']);
        $this->assertFalse($service->canBeOffered($method));
        $this->assertTrue($service->canBeOffered(new PaymentMethod(['code' => 'bank'])));
        $method->settings = ['wspay_shop_id' => 'test-shop', 'wspay_secret_key' => 'test-secret'];
        config(['commerce.local_safe_mode' => true]);
        $this->assertTrue($service->canBeOffered($method));
    }

    public function test_form_uses_version_2_sha512_and_the_requested_amount_without_exposing_secret(): void
    {
        $this->method();
        $order = $this->order();
        $form = app(WSPayFormService::class)->buildFormData($order);

        $this->assertSame(WSPayFormService::FORM_URL_TEST, $form['action_url']);
        $this->assertSame('2.0', $form['version']);
        $this->assertSame('1234,56', $form['total_amount']);
        $this->assertSame(hash('sha512', 'test-shop'.'test-secret'.$order->order_number.'test-secret'.'123456'.'test-secret'), $form['signature']);
        $this->assertSame('GET', $form['return_method']);
        $this->assertSame('HR', $form['customer']['country']);
        $this->assertStringNotContainsString('test-secret', json_encode($form));
        $this->assertStringNotContainsString('test-secret', json_encode($order->fresh()->payload));
        $this->assertSame('test', data_get($order->fresh()->payload, 'wspay.request.mode'));
    }

    public function test_local_safe_mode_never_prepares_a_live_or_test_payment_form(): void
    {
        $method = $this->method(['wspay_mode' => 'live']);
        $order = $this->order();
        config(['commerce.local_safe_mode' => true]);
        $this->assertNull(app(WSPayFormService::class)->buildFormData($order));
        $this->assertNull($order->fresh()->payload);

        $method->settings = array_replace($method->settings, ['wspay_mode' => 'test']);
        $method->save();
        $this->assertNull(app(WSPayFormService::class)->buildFormData($order));
    }

    public function test_paid_restocked_and_zero_amount_orders_cannot_start_another_payment(): void
    {
        $this->method();
        $order = $this->order();
        $order->forceFill(['paid_at' => now()])->save();
        $this->assertNull(app(WSPayFormService::class)->buildFormData($order));

        $order->forceFill(['paid_at' => null, 'payload' => ['wspay' => ['cancel_restocked_at' => now()->toIso8601String()]]])->save();
        $this->assertNull(app(WSPayFormService::class)->buildFormData($order));

        $order->forceFill(['payload' => null, 'grand_total' => 0])->save();
        $this->assertNull(app(WSPayFormService::class)->buildFormData($order));
    }

    public function test_forged_and_cross_order_callbacks_do_not_change_the_order(): void
    {
        $this->method();
        $order = $this->order();
        $invalid = $this->signedCallback($order, ['Signature' => str_repeat('0', 128)]);
        $result = app(WSPayFormService::class)->handleCallback($order, $invalid, 'return');
        $this->assertFalse($result['signature_valid']);
        $this->assertFalse($result['callback_authorized']);

        $other = $this->order('OTHER');
        $result = app(WSPayFormService::class)->handleCallback($order, $this->signedCallback($other, ['Success' => '0', 'ApprovalCode' => '']), 'cancel');
        $this->assertFalse($result['signature_valid']);
        $this->assertFalse($result['paid']);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertNull($order->fresh()->payload);
        $this->assertSame($this->pending->id, $order->fresh()->status_id);
        $this->assertDatabaseCount('order_transactions', 0);
        $this->assertDatabaseCount('order_history', 0);
    }

    public function test_success_marks_paid_once_and_replayed_callbacks_preserve_other_payload(): void
    {
        $this->method();
        $order = $this->order();
        $order->forceFill(['payload' => ['inventory' => ['reserved' => true]]])->save();
        $input = $this->signedCallback($order, ['SecretKey' => 'must-not-store', 'secretkey' => 'must-not-store-either']);
        $result = app(WSPayFormService::class)->handleCallback($order, $input, 'return');
        $this->assertTrue($result['paid']);
        $this->assertTrue($result['newly_paid']);
        $this->assertTrue($result['signature_valid']);
        $this->assertTrue($result['callback_authorized']);
        $this->assertSame('approved', $result['status']);
        $paidAt = $order->fresh()->paid_at->toIso8601String();
        $this->assertSame($this->paid->id, $order->fresh()->status_id);
        $this->assertTrue(data_get($order->fresh()->payload, 'inventory.reserved'));

        $this->travel(1)->minutes();
        $result = app(WSPayFormService::class)->handleCallback($order, $input, 'return');
        $this->assertTrue($result['paid']);
        $this->assertFalse($result['newly_paid']);
        $this->assertSame($paidAt, $order->fresh()->paid_at->toIso8601String());
        $this->assertDatabaseCount('order_transactions', 1);
        $this->assertDatabaseCount('order_history', 1);
        $this->assertStringNotContainsString('must-not-store', json_encode($order->transactions()->sole()->payload));
    }

    public function test_signed_cancel_cannot_restock_or_downgrade_an_already_paid_order(): void
    {
        $this->method();
        $order = $this->order();
        $product = $this->item($order);
        app(WSPayFormService::class)->handleCallback($order, $this->signedCallback($order), 'return');
        $result = app(WSPayFormService::class)->handleCallback($order, $this->signedCallback($order, ['Success' => '0', 'ApprovalCode' => '', 'WsPayOrderId' => '']), 'cancel');
        app(WSPayFormService::class)->handleCancellationEffects($order);

        $this->assertTrue($result['paid']);
        $this->assertFalse($result['newly_paid']);
        $this->assertSame($this->paid->id, $order->fresh()->status_id);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame('approved', data_get($order->fresh()->payload, 'wspay.status'));
        $this->assertSame('123456', data_get($order->fresh()->payload, 'wspay.approval_code'));
        $this->assertNull(data_get($order->fresh()->payload, 'wspay.cancel_restocked_at'));
        $this->assertSame(1, $product->fresh()->stock_qty);
    }

    public function test_cancel_restores_stock_once_and_a_late_success_does_not_pay_a_restocked_order(): void
    {
        $this->method();
        $order = $this->order();
        $product = $this->item($order);
        $result = app(WSPayFormService::class)->handleCallback($order, $this->signedCallback($order, ['Success' => '0', 'ApprovalCode' => '']), 'cancel');
        $this->assertTrue($result['signature_valid']);
        $this->assertSame('cancelled', $result['status']);
        app(WSPayFormService::class)->handleCancellationEffects($order);
        app(WSPayFormService::class)->handleCancellationEffects($order);
        $this->assertSame(3, $product->fresh()->stock_qty);
        $this->assertSame($this->cancelled->id, $order->fresh()->status_id);

        $result = app(WSPayFormService::class)->handleCallback($order, $this->signedCallback($order), 'return');
        $this->assertSame('late_success_after_cancel', $result['status']);
        $this->assertFalse($result['paid']);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame($this->cancelled->id, $order->fresh()->status_id);
        $this->assertSame('cancelled', data_get($order->fresh()->payload, 'wspay.status'));
        $this->assertSame(3, $product->fresh()->stock_qty);
    }

    public function test_signed_error_keeps_the_order_unpaid(): void
    {
        $this->method();
        $order = $this->order();
        $result = app(WSPayFormService::class)->handleCallback($order, $this->signedCallback($order, ['Success' => '0', 'ApprovalCode' => '', 'ErrorMessage' => 'DECLINED']), 'error');
        $this->assertSame('error', $result['status']);
        $this->assertFalse($result['paid']);
        $this->assertSame($this->pending->id, $order->fresh()->status_id);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertDatabaseHas('order_transactions', ['order_id' => $order->id, 'status' => 'error']);
    }

    private function method(array $settings = []): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'code' => 'wspay', 'name' => 'WSPay', 'provider' => 'wspay', 'is_active' => true,
            'settings' => array_replace(['wspay_mode' => 'test', 'wspay_shop_id' => 'test-shop', 'wspay_secret_key' => 'test-secret'], $settings),
        ]);
    }

    private function order(string $number = 'WS-TEST-1'): Order
    {
        return Order::query()->create([
            'order_number' => $number, 'status_id' => $this->pending->id, 'source' => 'web', 'currency_code' => 'EUR',
            'customer_name' => 'Test Buyer', 'customer_email' => 'buyer@example.test', 'payment_method_code' => 'wspay', 'grand_total' => 1234.56,
        ]);
    }

    private function item(Order $order): Product
    {
        $product = Product::query()->create(['code' => 'WS-ITEM', 'sku' => 'WS-ITEM', 'base_price' => 10, 'stock_qty' => 1, 'is_active' => true]);
        $order->items()->create(['product_id' => $product->id, 'name' => 'Test item', 'quantity' => 2, 'unit_price' => 10, 'line_total' => 20]);

        return $product;
    }

    private function signedCallback(Order $order, array $overrides = []): array
    {
        $input = array_replace(['ShoppingCartID' => $order->order_number, 'Success' => '1', 'ApprovalCode' => '123456', 'WsPayOrderId' => 'test-transaction'], $overrides);
        $input['Signature'] ??= hash('sha512', 'test-shop'.'test-secret'.$input['ShoppingCartID'].'test-secret'.$input['Success'].'test-secret'.$input['ApprovalCode'].'test-secret');

        return $input;
    }
}
