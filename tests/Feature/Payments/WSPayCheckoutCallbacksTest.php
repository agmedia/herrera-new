<?php

namespace Tests\Feature\Payments;

use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\Front\StoreNotificationService;
use App\Services\Payments\WSPayFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class WSPayCheckoutCallbacksTest extends TestCase
{
    use RefreshDatabase;

    private OrderStatus $pending;

    private OrderStatus $paid;

    private OrderStatus $cancelled;

    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => false, 'commerce.local_safe_mode' => false]);
        Http::preventStrayRequests();
        $this->pending = OrderStatus::query()->create(['code' => 'new', 'name' => 'New', 'is_default' => true, 'is_active' => true]);
        $this->paid = OrderStatus::query()->create(['code' => 'paid', 'name' => 'Paid', 'is_paid' => true, 'is_active' => true]);
        $this->cancelled = OrderStatus::query()->create(['code' => 'cancelled', 'name' => 'Cancelled', 'is_cancelled' => true, 'is_active' => true]);
        $this->method = PaymentMethod::query()->create([
            'code' => 'wspay', 'name' => 'WSPay', 'provider' => 'wspay', 'is_active' => false,
            'settings' => ['wspay_mode' => 'live', 'wspay_shop_id' => 'test-shop', 'wspay_secret_key' => 'test-secret'],
        ]);
        $this->mock(StoreNotificationService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sendOrderNotification');
        });
    }

    public function test_unsigned_third_party_cancel_cannot_restock_or_authorize_the_order_session(): void
    {
        [$order, $product] = $this->order();
        $this->get(route('checkout.wspay.cancel', ['orderNumber' => $order->order_number]))
            ->assertNotFound()
            ->assertSessionMissing('front.checkout.last_order_id')
            ->assertSessionMissing('front.cart.items');

        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertSame($this->pending->id, $order->fresh()->status_id);
        $this->assertNull($order->fresh()->payload);
        $this->assertDatabaseCount('order_transactions', 0);
        $this->assertDatabaseCount('order_history', 0);
    }

    public function test_another_logged_in_customer_cannot_use_an_unsigned_cancel(): void
    {
        [$order, $product] = $this->order(User::factory()->create());
        $this->actingAs(User::factory()->create())
            ->get(route('checkout.wspay.cancel', ['orderNumber' => $order->order_number]))
            ->assertNotFound()
            ->assertSessionMissing('front.checkout.last_order_id')
            ->assertSessionMissing('front.cart.items');

        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertSame($this->pending->id, $order->fresh()->status_id);
    }

    #[DataProvider('ownerAuthorization')]
    public function test_owner_or_checkout_session_can_cancel_and_restore_stock_once_without_a_signature(bool $useAuthenticatedOwner): void
    {
        [$order, $product] = $this->order($useAuthenticatedOwner ? User::factory()->create() : null);
        $this->authorize($order, $useAuthenticatedOwner);
        $url = route('checkout.wspay.cancel', ['orderNumber' => $order->order_number]);

        $this->get($url)->assertRedirect(route('cart.index'))
            ->assertSessionHas('front.checkout.last_order_id', $order->id)
            ->assertSessionHas('front.cart.items', [
                $product->id.':0' => ['product_id' => $product->id, 'product_option_value_id' => null, 'quantity' => 2],
            ]);
        $this->assertSame(3, $product->fresh()->stock_qty);
        $this->assertSame($this->cancelled->id, $order->fresh()->status_id);
        $this->assertNotEmpty(data_get($order->fresh()->payload, 'wspay.cancel_restocked_at'));
        $this->assertDatabaseCount('order_transactions', 0);

        $this->get($url)->assertRedirect(route('cart.index'));
        $this->assertSame(3, $product->fresh()->stock_qty);
        $this->assertDatabaseCount('order_history', 1);
        $this->assertFalse($this->method->fresh()->is_active);
    }

    public static function ownerAuthorization(): array
    {
        return ['order owner' => [true], 'checkout session' => [false]];
    }

    public function test_a_signature_for_another_order_cannot_authorize_the_target_order_session(): void
    {
        [$order, $product] = $this->order();
        $input = $this->signedCallback($order, ['ShoppingCartID' => 'OTHER-ORDER', 'Success' => '0', 'ApprovalCode' => '']);
        $this->get(route('checkout.wspay.cancel', array_merge(['orderNumber' => $order->order_number], $input)))
            ->assertNotFound()
            ->assertSessionMissing('front.checkout.last_order_id')
            ->assertSessionMissing('front.cart.items');

        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertNull($order->fresh()->payload);
        $this->assertDatabaseCount('order_transactions', 0);
    }

    public function test_signed_cancel_of_a_paid_order_preserves_stock_and_the_existing_cart(): void
    {
        [$order, $product] = $this->order();
        app(WSPayFormService::class)->handleCallback($order, $this->signedCallback($order), 'return');
        $existingCart = ['999:0' => ['product_id' => 999, 'product_option_value_id' => null, 'quantity' => 1]];
        $input = $this->signedCallback($order, ['Success' => '0', 'ApprovalCode' => '']);

        $this->withSession(['front.cart.items' => $existingCart])
            ->get(route('checkout.wspay.cancel', array_merge(['orderNumber' => $order->order_number], $input)))
            ->assertRedirect(route('checkout.success', ['orderNumber' => $order->order_number]))
            ->assertSessionHas('front.cart.items', $existingCart);

        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertSame($this->paid->id, $order->fresh()->status_id);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertNull(data_get($order->fresh()->payload, 'wspay.cancel_restocked_at'));
    }

    public function test_unsigned_cancel_by_the_owner_of_a_paid_order_preserves_stock_and_the_existing_cart(): void
    {
        [$order, $product] = $this->order();
        app(WSPayFormService::class)->handleCallback($order, $this->signedCallback($order), 'return');
        $existingCart = ['999:0' => ['product_id' => 999, 'product_option_value_id' => null, 'quantity' => 1]];

        $this->withSession(['front.checkout.last_order_id' => $order->id, 'front.cart.items' => $existingCart])
            ->get(route('checkout.wspay.cancel', ['orderNumber' => $order->order_number]))
            ->assertRedirect(route('checkout.success', ['orderNumber' => $order->order_number]))
            ->assertSessionHas('front.cart.items', $existingCart);

        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertSame($this->paid->id, $order->fresh()->status_id);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertNull(data_get($order->fresh()->payload, 'wspay.cancel_restocked_at'));
    }

    public function test_local_safe_mode_start_returns_to_local_order_without_preparing_an_outbound_form(): void
    {
        [$order] = $this->order();
        config(['commerce.local_safe_mode' => true]);

        $this->withSession(['front.checkout.last_order_id' => $order->id])
            ->get(route('checkout.wspay.start', ['orderNumber' => $order->order_number]))
            ->assertRedirect(route('checkout.success', ['orderNumber' => $order->order_number]))
            ->assertSessionHas('status', __('ui.checkout.wspay.local_preview'))
            ->assertDontSee('wspay-redirect-form')
            ->assertDontSee(WSPayFormService::FORM_URL_LIVE);

        $this->assertNull($order->fresh()->payload);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertFalse($this->method->fresh()->is_active);
        Http::assertNothingSent();
    }

    #[DataProvider('delegationRevocations')]
    public function test_signed_payment_settles_an_existing_order_after_the_original_salespersons_access_is_revoked(string $reason): void
    {
        $customer = $this->revokedDelegation($reason);
        [$order, $product] = $this->order($customer);
        $this->mock(StoreNotificationService::class, function (MockInterface $mock) use ($order): void {
            $mock->shouldReceive('sendOrderNotification')->once()
                ->withArgs(fn (Order $notified): bool => $notified->id === $order->id)->andReturnNull();
        });
        $url = route('checkout.wspay.return', array_merge(['orderNumber' => $order->order_number], $this->signedCallback($order)));

        $this->get($url)->assertRedirect(route('checkout.success', ['orderNumber' => $order->order_number]))
            ->assertSessionHas('front.checkout.last_order_id', $order->id);
        $this->assertSame($this->paid->id, $order->fresh()->status_id);
        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertDatabaseHas('order_transactions', ['order_id' => $order->id, 'provider' => 'wspay', 'status' => 'approved']);
        $this->assertDatabaseCount('order_transactions', 1);
        $this->assertDatabaseCount('order_history', 1);

        // Replayed signed callbacks stay idempotent despite the invalid delegation.
        $this->get($url)->assertRedirect();
        $this->assertDatabaseCount('order_transactions', 1);
        $this->assertDatabaseCount('order_history', 1);
        $this->get(route('shop.index'))->assertForbidden();
        Http::assertNothingSent();
    }

    #[DataProvider('delegationRevocations')]
    public function test_signed_cancellation_releases_an_existing_orders_stock_once_after_delegation_revocation(string $reason): void
    {
        $customer = $this->revokedDelegation($reason);
        [$order, $product] = $this->order($customer);
        $input = $this->signedCallback($order, ['Success' => '0', 'ApprovalCode' => '']);
        $url = route('checkout.wspay.cancel', array_merge(['orderNumber' => $order->order_number], $input));

        $this->get($url)->assertRedirect(route('cart.index'));
        $this->assertSame($this->cancelled->id, $order->fresh()->status_id);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame(3, $product->fresh()->stock_qty);
        $this->assertNotEmpty(data_get($order->fresh()->payload, 'wspay.cancel_restocked_at'));
        $this->assertDatabaseHas('order_transactions', ['order_id' => $order->id, 'provider' => 'wspay', 'status' => 'cancelled']);
        $this->assertDatabaseCount('order_transactions', 1);
        $this->assertDatabaseCount('order_history', 1);

        $this->get($url)->assertRedirect(route('cart.index'));
        $this->assertSame(3, $product->fresh()->stock_qty);
        $this->assertDatabaseCount('order_transactions', 1);
        $this->assertDatabaseCount('order_history', 1);
        $this->get(route('cart.index'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_callback_exemption_does_not_authorize_unsigned_forged_changes_to_an_unrelated_order(): void
    {
        $this->revokedDelegation('disabled');
        [$order, $product] = $this->order(User::factory()->create());

        // Exercise callback authorization directly, without the HTML error layout
        // revalidating the revoked delegation while rendering its account banner.
        $this->getJson(route('checkout.wspay.cancel', ['orderNumber' => $order->order_number]))
            ->assertNotFound()->assertSessionMissing('front.checkout.last_order_id');
        $input = $this->signedCallback($order);
        unset($input['Signature']);
        $this->getJson(route('checkout.wspay.return', array_merge(['orderNumber' => $order->order_number], $input)))
            ->assertNotFound()->assertSessionMissing('front.checkout.last_order_id');

        $this->assertSame($this->pending->id, $order->fresh()->status_id);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertNull($order->fresh()->payload);
        $this->assertSame(1, $product->fresh()->stock_qty);
        $this->assertDatabaseCount('order_transactions', 0);
        $this->assertDatabaseCount('order_history', 0);
        Http::assertNothingSent();
    }

    public static function delegationRevocations(): array
    {
        return ['disabled original staff' => ['disabled'], 'revoked assignment' => ['assignment'], 'deleted original staff' => ['deleted']];
    }

    private function revokedDelegation(string $reason): User
    {
        config(['commerce.b2b_only' => true]);
        $manager = User::factory()->create(['account_type' => 'staff', 'admin_login_enabled' => true]);
        Bouncer::assign('order_manager')->to($manager);
        $customer = User::factory()->create();
        $group = CustomerGroup::query()->create(['code' => 'payment-callback-buyer', 'name' => 'Callback buyer', 'is_active' => true]);
        B2BAccount::query()->create([
            'user_id' => $customer->id, 'company_name' => 'Callback buyer', 'oib' => '12345678901',
            'status' => B2BAccount::STATUS_APPROVED, 'customer_group_id' => $group->id,
        ]);
        DB::table('admin_customer_assignments')->insert([
            'admin_user_id' => $manager->id, 'customer_user_id' => $customer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($manager)->post(route('admin.users.impersonate', $customer))->assertRedirect();
        $this->assertAuthenticatedAs($customer);

        match ($reason) {
            'disabled' => $manager->forceFill(['admin_login_enabled' => false])->save(),
            'assignment' => DB::table('admin_customer_assignments')->where('admin_user_id', $manager->id)->delete(),
            'deleted' => $manager->delete(),
        };

        return $customer;
    }

    private function authorize(Order $order, bool $useAuthenticatedOwner): void
    {
        if ($useAuthenticatedOwner) {
            $this->actingAs($order->user);
        } else {
            $this->withSession(['front.checkout.last_order_id' => $order->id]);
        }
    }

    private function order(?User $owner = null): array
    {
        $order = Order::query()->create([
            'order_number' => 'WS-CALLBACK-TEST', 'user_id' => $owner?->id, 'status_id' => $this->pending->id, 'source' => 'web', 'currency_code' => 'EUR',
            'customer_name' => 'Test Buyer', 'customer_email' => 'buyer@example.test', 'payment_method_code' => 'wspay', 'grand_total' => 20,
        ]);
        $product = Product::query()->create(['code' => 'WS-ITEM', 'sku' => 'WS-ITEM', 'base_price' => 10, 'stock_qty' => 1, 'is_active' => true]);
        $order->items()->create(['product_id' => $product->id, 'name' => 'Test item', 'quantity' => 2, 'unit_price' => 10, 'line_total' => 20]);

        return [$order, $product];
    }

    private function signedCallback(Order $order, array $overrides = []): array
    {
        $input = array_replace(['ShoppingCartID' => $order->order_number, 'Success' => '1', 'ApprovalCode' => '123456', 'WsPayOrderId' => 'test-transaction'], $overrides);
        $input['Signature'] = hash('sha512', 'test-shop'.'test-secret'.$input['ShoppingCartID'].'test-secret'.$input['Success'].'test-secret'.$input['ApprovalCode'].'test-secret');

        return $input;
    }
}
