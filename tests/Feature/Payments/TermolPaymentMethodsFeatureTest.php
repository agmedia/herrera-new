<?php

namespace Tests\Feature\Payments;

use App\Livewire\Admin\Settings\Local\ResourceManager;
use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderTransaction;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Services\Front\CheckoutService;
use App\Services\Front\StoreNotificationService;
use App\Services\Payments\CorvusPayFormService;
use Database\Seeders\SettingsLocalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class TermolPaymentMethodsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_payment_methods_are_localized_and_unsafe_card_methods_are_disabled(): void
    {
        $this->createPaymentMethod('cod', 'Cash on Delivery', false);
        $this->createPaymentMethod('bank', 'Bank Transfer', false);
        $this->createPaymentMethod('pickup', 'Pay on Pickup', false);
        $this->createPaymentMethod('wspay', 'WSPay', true);
        $this->createPaymentMethod('corvus', 'CorvusPay', true, [
            'corvus_mode' => 'test',
        ]);
        $this->createPaymentMethod('corvuspay', 'Duplicate CorvusPay', true);

        $this->runPaymentLocalizationMigration();

        $this->assertDatabaseHas('payment_methods', [
            'code' => 'cod',
            'name' => 'Plaćanje pouzećem',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'code' => 'bank',
            'name' => 'Uplata na račun',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'code' => 'pickup',
            'name' => 'Plaćanje pri osobnom preuzimanju',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('payment_methods', ['code' => 'wspay', 'is_active' => false]);
        $this->assertDatabaseHas('payment_methods', ['code' => 'corvus', 'is_active' => false]);
        $this->assertDatabaseHas('payment_methods', ['code' => 'corvuspay', 'is_active' => false]);
    }

    public function test_existing_active_corvus_credentials_are_preserved_with_the_admin_activation(): void
    {
        $method = $this->createPaymentMethod('corvuspay', 'CorvusPay', true, [
            'corvus_mode' => 'live',
            'corvus_store_id' => 'termol-store',
            'corvus_secret_key' => 'existing-secret',
        ]);

        $this->runPaymentLocalizationMigration();

        $method->refresh();
        $this->assertTrue($method->is_active);
        $this->assertSame('corvus', $method->code);
        $this->assertSame('Kartično plaćanje (CorvusPay)', $method->name);
        $this->assertSame('termol-store', $method->settings['corvus_store_id']);
        $this->assertSame('existing-secret', $method->settings['corvus_secret_key']);
        $this->assertSame('https://wallet.corvuspay.com/checkout/', $method->settings['corvus_form_url']);
        $this->assertSame('hr', $method->settings['corvus_language']);
        $this->assertSame('EUR', $method->settings['corvus_currency']);
    }

    public function test_migration_recovers_credentials_from_a_legacy_alias_when_canonical_is_unconfigured(): void
    {
        $canonical = $this->createPaymentMethod('corvus', 'CorvusPay', false, [
            'corvus_mode' => 'test',
            'corvus_store_id' => '',
            'corvus_secret_key' => '',
        ]);
        $legacy = $this->createPaymentMethod('corvuspay', 'Legacy CorvusPay', true, [
            'corvus_mode' => 'live',
            'corvus_store_id' => 'legacy-store',
            'corvus_secret_key' => 'legacy-secret',
        ]);

        $this->runPaymentLocalizationMigration();

        $canonical->refresh();
        $this->assertFalse($canonical->is_active);
        $this->assertSame('legacy-store', $canonical->settings['corvus_store_id']);
        $this->assertSame('legacy-secret', $canonical->settings['corvus_secret_key']);
        $this->assertSame('live', $canonical->settings['corvus_mode']);
        $this->assertSame('https://wallet.corvuspay.com/checkout/', $canonical->settings['corvus_form_url']);
        $this->assertFalse($legacy->refresh()->is_active);
    }

    public function test_seeder_keeps_existing_corvus_credentials_and_uses_croatian_labels(): void
    {
        $method = $this->createPaymentMethod('corvus', 'CorvusPay', false, [
            'corvus_store_id' => 'termol-store',
            'corvus_secret_key' => 'existing-secret',
        ]);

        $this->seed(SettingsLocalSeeder::class);

        $method->refresh();
        $this->assertFalse($method->is_active);
        $this->assertSame('Kartično plaćanje (CorvusPay)', $method->name);
        $this->assertSame('existing-secret', $method->settings['corvus_secret_key']);
        $this->assertSame('hr', $method->settings['corvus_language']);
        $this->assertSame('EUR', $method->settings['corvus_currency']);
        $this->assertDatabaseHas('payment_methods', [
            'code' => 'cod',
            'name' => 'Plaćanje pouzećem',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'code' => 'bank',
            'name' => 'Uplata na račun',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('payment_methods', [
            'code' => 'pickup',
            'name' => 'Plaćanje pri osobnom preuzimanju',
            'is_active' => true,
        ]);
        $this->assertSame(1, PaymentMethod::query()->where('code', 'corvus')->count());
    }

    public function test_seeder_recovers_credentials_from_a_legacy_alias_without_creating_a_second_canonical_method(): void
    {
        $canonical = $this->createPaymentMethod('corvus', 'CorvusPay', false, [
            'corvus_mode' => 'test',
        ]);
        $legacy = $this->createPaymentMethod('corvus_pay', 'Legacy CorvusPay', true, [
            'corvus_store_id' => 'legacy-store',
            'corvus_secret_key' => 'legacy-secret',
        ]);

        $this->seed(SettingsLocalSeeder::class);

        $canonical->refresh();
        $this->assertFalse($canonical->is_active);
        $this->assertSame('legacy-store', $canonical->settings['corvus_store_id']);
        $this->assertSame('legacy-secret', $canonical->settings['corvus_secret_key']);
        $this->assertFalse($legacy->refresh()->is_active);
        $this->assertSame(1, PaymentMethod::query()->where('code', 'corvus')->count());
    }

    public function test_checkout_never_offers_active_corvus_without_required_credentials(): void
    {
        $this->createPaymentMethod('bank', 'Uplata na račun', true);
        $corvus = $this->createPaymentMethod('corvus', 'Kartično plaćanje (CorvusPay)', true, [
            'corvus_mode' => 'test',
        ]);

        $methods = app(CheckoutService::class)->availablePaymentMethods(50);
        $this->assertSame(['bank'], $methods->pluck('code')->all());

        $corvus->update(['settings' => [
            'corvus_mode' => 'test',
            'corvus_store_id' => 'termol-store',
            'corvus_secret_key' => 'configured-secret',
        ]]);

        $methods = app(CheckoutService::class)->availablePaymentMethods(50);
        $this->assertSame(['bank', 'corvus'], $methods->pluck('code')->all());
    }

    public function test_cash_payments_match_pickup_and_delivery_shipping_methods(): void
    {
        $this->createPaymentMethod('cod', 'Plaćanje pouzećem', true);
        $this->createPaymentMethod('bank', 'Uplata na račun', true);
        $this->createPaymentMethod('pickup', 'Plaćanje pri osobnom preuzimanju', true);
        $pickupShipping = ShippingMethod::query()->create([
            'code' => 'pickup-test',
            'name' => 'Osobno preuzimanje',
            'service_type' => 'pickup',
            'pricing_type' => 'free',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $homeShipping = ShippingMethod::query()->create([
            'code' => 'home-test',
            'name' => 'Dostava na adresu',
            'service_type' => 'home_delivery',
            'pricing_type' => 'flat',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $checkout = app(CheckoutService::class);

        $this->assertSame(
            ['bank', 'pickup'],
            $checkout->availablePaymentMethods(50, null, null, null, $pickupShipping)->pluck('code')->all(),
        );
        $this->assertSame(
            ['cod', 'bank'],
            $checkout->availablePaymentMethods(50, null, null, null, $homeShipping)->pluck('code')->all(),
        );
        $this->assertSame(
            ['bank'],
            $checkout->availablePaymentMethods(50)->pluck('code')->all(),
        );
    }

    public function test_quote_shipping_allows_only_a_non_charging_quote_request(): void
    {
        $this->createPaymentMethod('bank', 'Uplata na račun', true);
        $this->createPaymentMethod('corvus', 'Kartično plaćanje (CorvusPay)', true, [
            'corvus_store_id' => 'termol-store',
            'corvus_secret_key' => 'configured-secret',
        ]);
        PaymentMethod::query()->where('code', 'quote_request')->update(['is_active' => true]);
        $quoteShipping = ShippingMethod::query()->create([
            'code' => 'quote-test',
            'name' => 'Dostava na upit',
            'service_type' => 'quote',
            'pricing_type' => 'quote',
            'is_active' => true,
            'sort_order' => 1,
        ]);
        $homeShipping = ShippingMethod::query()->create([
            'code' => 'home-test',
            'name' => 'Dostava na adresu',
            'service_type' => 'home_delivery',
            'pricing_type' => 'flat',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $checkout = app(CheckoutService::class);

        $this->assertSame(
            ['quote_request'],
            $checkout->availablePaymentMethods(50, null, null, null, $quoteShipping)->pluck('code')->all(),
        );
        $this->assertSame(
            ['bank', 'corvus'],
            $checkout->availablePaymentMethods(50, null, null, null, $homeShipping)->pluck('code')->all(),
        );
    }

    public function test_admin_does_not_hydrate_stored_corvus_secret_and_blank_value_preserves_it(): void
    {
        $user = User::factory()->create();
        $method = $this->createPaymentMethod('corvus', 'CorvusPay', true, [
            'corvus_mode' => 'test',
            'corvus_store_id' => 'termol-store',
            'corvus_secret_key' => 'never-render-this-secret',
        ]);

        $component = Livewire::actingAs($user)
            ->test(ResourceManager::class, ['resource' => 'payment-methods'])
            ->call('edit', $method->id)
            ->assertSet('corvusCredentialsStored', true)
            ->assertSet('form.corvus_secret_key', '');

        $this->assertStringNotContainsString(
            'never-render-this-secret',
            (string) $component->get('form.settings_text'),
        );
        $this->assertStringNotContainsString('never-render-this-secret', $component->html());

        $component
            ->set('form.name', 'Kartično plaćanje (CorvusPay)')
            ->set('form.is_active', true)
            ->call('save')
            ->assertHasNoErrors();

        $method->refresh();
        $this->assertTrue($method->is_active);
        $this->assertSame('never-render-this-secret', $method->settings['corvus_secret_key']);
    }

    public function test_admin_cannot_toggle_corvus_active_until_credentials_are_stored(): void
    {
        $method = $this->createPaymentMethod('corvus', 'Kartično plaćanje (CorvusPay)', false, [
            'corvus_mode' => 'test',
        ]);

        Livewire::test(ResourceManager::class, ['resource' => 'payment-methods'])
            ->call('toggleActive', $method->id)
            ->assertHasErrors(['form.corvus_store_id']);

        $this->assertFalse($method->refresh()->is_active);

        $method->update(['settings' => [
            'corvus_mode' => 'test',
            'corvus_store_id' => 'termol-store',
            'corvus_secret_key' => 'configured-secret',
        ]]);

        Livewire::test(ResourceManager::class, ['resource' => 'payment-methods'])
            ->call('toggleActive', $method->id)
            ->assertHasNoErrors();

        $this->assertTrue($method->refresh()->is_active);
    }

    public function test_unsigned_corvus_cancel_callback_cannot_cancel_an_order(): void
    {
        $secret = 'callback-secret';
        $this->createPaymentMethod('corvus', 'Kartično plaćanje (CorvusPay)', true, [
            'corvus_store_id' => 'termol-store',
            'corvus_secret_key' => $secret,
        ]);
        $pending = $this->createOrderStatus('pending-corvus', false, false);
        $this->createOrderStatus('cancelled-corvus', false, true);
        $order = $this->createCorvusOrder($pending, 'AG-CORVUS-UNSIGNED');

        $response = $this->get(route('checkout.corvus.cancel', [
            'orderNumber' => $order->order_number,
            'order_number' => $order->order_number,
        ]));

        $response->assertRedirect(route('cart.index'));
        $order->refresh();
        $this->assertSame($pending->id, $order->status_id);
        $this->assertNull($order->paid_at);
        $this->assertSame([], $order->payload);
        $this->assertNull(data_get($order->payload, 'corvuspay.cancel_restocked_at'));
        $this->assertSame(0, OrderTransaction::query()->where('order_id', $order->id)->count());
    }

    public function test_valid_cancel_for_a_paid_legacy_code_order_uses_canonical_settings_but_never_reverses_payment(): void
    {
        $secret = 'callback-secret';
        $this->createPaymentMethod('corvus', 'Kartično plaćanje (CorvusPay)', true, [
            'corvus_store_id' => 'termol-store',
            'corvus_secret_key' => $secret,
        ]);
        $paid = $this->createOrderStatus('paid-corvus', true, false);
        $this->createOrderStatus('cancelled-corvus', false, true);
        $order = $this->createCorvusOrder($paid, 'AG-CORVUS-PAID', 'corvuspay', now());
        $callback = [
            'approval_code' => '',
            'language' => 'hr',
            'order_number' => $order->order_number,
        ];
        $callback['signature'] = $this->corvusSignature($callback, $secret);

        $response = $this->get(route('checkout.corvus.cancel', array_merge([
            'orderNumber' => $order->order_number,
        ], $callback)));

        $response->assertRedirect(route('cart.index'));
        $order->refresh();
        $this->assertSame($paid->id, $order->status_id);
        $this->assertNotNull($order->paid_at);
        $this->assertSame('already_paid', data_get($order->payload, 'corvuspay.status'));
        $this->assertTrue((bool) data_get($order->payload, 'corvuspay.callback_authorized'));
        $this->assertNull(data_get($order->payload, 'corvuspay.cancel_restocked_at'));
    }

    public function test_authorized_cancel_is_atomic_and_a_late_success_cannot_pay_a_restocked_order(): void
    {
        $secret = 'callback-secret';
        $this->createPaymentMethod('corvus', 'Kartično plaćanje (CorvusPay)', true, [
            'corvus_store_id' => 'termol-store',
            'corvus_secret_key' => $secret,
        ]);
        $pending = $this->createOrderStatus('pending-corvus', false, false);
        $paid = $this->createOrderStatus('paid-corvus', true, false);
        $cancelled = $this->createOrderStatus('cancelled-corvus', false, true);
        $product = Product::query()->create([
            'code' => 'corvus-race-product',
            'sku' => 'CORVUS-RACE',
            'is_active' => true,
            'base_price' => 10,
            'stock_qty' => 2,
        ]);
        $order = $this->createCorvusOrder($pending, 'AG-CORVUS-RACE');
        $order->items()->create([
            'product_id' => $product->id,
            'sku' => $product->sku,
            'code' => $product->code,
            'name' => 'Race product',
            'unit_price' => 10,
            'quantity' => 1,
            'line_total' => 10,
            'sort_order' => 1,
        ]);
        $cancel = [
            'approval_code' => '',
            'language' => 'hr',
            'order_number' => $order->order_number,
        ];
        $cancel['signature'] = $this->corvusSignature($cancel, $secret);

        $cancelResult = app(CorvusPayFormService::class)->handleCallback($order, $cancel, 'cancel');

        $this->assertSame('cancelled', $cancelResult['status']);
        $this->assertTrue($cancelResult['cancellation_applied']);
        $this->assertSame(3, $product->refresh()->stock_qty);
        $this->assertSame($cancelled->id, $order->refresh()->status_id);
        $this->assertNotNull(data_get($order->payload, 'corvuspay.cancel_restocked_at'));

        $success = [
            'approval_code' => 'APPROVED-LATE',
            'language' => 'hr',
            'order_number' => $order->order_number,
        ];
        $success['signature'] = $this->corvusSignature($success, $secret);
        $successResult = app(CorvusPayFormService::class)->handleCallback($order, $success, 'success');

        $this->assertSame('late_success_after_cancel', $successResult['status']);
        $this->assertFalse($successResult['paid']);
        $this->assertSame(3, $product->refresh()->stock_qty);
        $order->refresh();
        $this->assertSame($cancelled->id, $order->status_id);
        $this->assertNull($order->paid_at);
        $this->assertSame('cancelled', data_get($order->payload, 'corvuspay.status'));
        $this->assertSame('late_success_after_cancel', data_get($order->payload, 'corvuspay.latest_callback_status'));
        $this->assertSame(2, OrderTransaction::query()->where('order_id', $order->id)->count());
        $this->assertNotSame($paid->id, $order->status_id);
    }

    public function test_static_corvus_callback_routes_are_not_shadowed_by_the_start_route(): void
    {
        foreach ([
            ['GET', '/checkout/corvus/success', 'checkout.corvus.success.static'],
            ['POST', '/checkout/corvus/success', 'checkout.corvus.success.static'],
            ['GET', '/checkout/corvus/cancel', 'checkout.corvus.cancel.static'],
            ['POST', '/checkout/corvus/cancel', 'checkout.corvus.cancel.static'],
        ] as [$method, $uri, $expectedName]) {
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));

            $this->assertSame($expectedName, $route->getName());
        }
    }

    public function test_replayed_corvus_success_is_idempotent_and_sends_one_notification(): void
    {
        $secret = 'callback-secret';
        $this->createPaymentMethod('corvus', 'Kartično plaćanje (CorvusPay)', true, [
            'corvus_store_id' => 'termol-store',
            'corvus_secret_key' => $secret,
        ]);
        $pending = $this->createOrderStatus('pending-corvus-replay', false, false);
        $paid = $this->createOrderStatus('paid-corvus-replay', true, false);
        $order = $this->createCorvusOrder($pending, 'AG-CORVUS-REPLAY');
        $callback = [
            'approval_code' => 'APPROVED-ONCE',
            'language' => 'hr',
            'order_number' => $order->order_number,
        ];
        $callback['signature'] = $this->corvusSignature($callback, $secret);

        $this->mock(StoreNotificationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sendOrderNotification')->once();
        });

        $url = route('checkout.corvus.success', array_merge([
            'orderNumber' => $order->order_number,
        ], $callback));

        $this->get($url)->assertRedirect(route('checkout.success', ['orderNumber' => $order->order_number]));
        $this->get($url)->assertRedirect(route('checkout.success', ['orderNumber' => $order->order_number]));

        $order->refresh();
        $this->assertSame($paid->id, $order->status_id);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull(data_get($order->payload, 'corvuspay.notification_sent_at'));
        $this->assertCount(2, data_get($order->payload, 'corvuspay.callbacks', []));
        $this->assertSame(1, OrderTransaction::query()->where('order_id', $order->id)->count());
    }

    public function test_corvus_callback_replay_retries_a_failed_notification_without_duplicate_payment(): void
    {
        $secret = 'callback-secret';
        $this->createPaymentMethod('corvus', 'Kartično plaćanje (CorvusPay)', true, [
            'corvus_store_id' => 'termol-store',
            'corvus_secret_key' => $secret,
        ]);
        $pending = $this->createOrderStatus('pending-corvus-notification-retry', false, false);
        $this->createOrderStatus('paid-corvus-notification-retry', true, false);
        $order = $this->createCorvusOrder($pending, 'AG-CORVUS-NOTIFICATION-RETRY');
        $callback = [
            'approval_code' => 'APPROVED-RETRY',
            'language' => 'hr',
            'order_number' => $order->order_number,
        ];
        $callback['signature'] = $this->corvusSignature($callback, $secret);
        $attempts = 0;

        $this->mock(StoreNotificationService::class, function (MockInterface $mock) use (&$attempts): void {
            $mock->shouldReceive('sendOrderNotification')
                ->twice()
                ->andReturnUsing(function () use (&$attempts): void {
                    $attempts++;
                    if ($attempts === 1) {
                        throw new \RuntimeException('Simulated notification outage.');
                    }
                });
        });

        $url = route('checkout.corvus.success', array_merge([
            'orderNumber' => $order->order_number,
        ], $callback));

        $this->withoutExceptionHandling();
        try {
            $this->get($url);
            $this->fail('The first notification attempt must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated notification outage.', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }

        $order->refresh();
        $this->assertNotNull($order->paid_at);
        $this->assertNull(data_get($order->payload, 'corvuspay.notification_sent_at'));

        $this->get($url)->assertRedirect(route('checkout.success', ['orderNumber' => $order->order_number]));

        $order->refresh();
        $this->assertSame(2, $attempts);
        $this->assertNotNull(data_get($order->payload, 'corvuspay.notification_sent_at'));
        $this->assertSame(1, OrderTransaction::query()->where('order_id', $order->id)->count());
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function createPaymentMethod(string $code, string $name, bool $active, array $settings = []): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'code' => $code,
            'name' => $name,
            'provider' => $code,
            'description' => $name,
            'fee_type' => 'fixed',
            'fee_value' => 0,
            'is_active' => $active,
            'sort_order' => PaymentMethod::query()->count() + 1,
            'settings' => $settings,
        ]);
    }

    private function createOrderStatus(string $code, bool $paid, bool $cancelled): OrderStatus
    {
        return OrderStatus::query()->create([
            'code' => $code,
            'name' => $code,
            'is_default' => false,
            'is_paid' => $paid,
            'is_cancelled' => $cancelled,
            'is_active' => true,
            'sort_order' => OrderStatus::query()->count() + 1,
        ]);
    }

    private function createCorvusOrder(
        OrderStatus $status,
        string $orderNumber,
        string $paymentCode = 'corvus',
        mixed $paidAt = null,
    ): Order {
        return Order::query()->create([
            'order_number' => $orderNumber,
            'status_id' => $status->id,
            'source' => 'web',
            'locale' => 'hr',
            'currency_code' => 'EUR',
            'currency_rate' => 1,
            'customer_name' => 'Test Kupac',
            'customer_email' => 'kupac@example.test',
            'payment_method_code' => $paymentCode,
            'payment_method_name' => 'Kartično plaćanje (CorvusPay)',
            'shipping_method_code' => 'pickup',
            'shipping_method_name' => 'Osobno preuzimanje',
            'item_qty' => 0,
            'subtotal' => 10,
            'grand_total' => 10,
            'payload' => [],
            'placed_at' => now(),
            'paid_at' => $paidAt,
        ]);
    }

    /** @param array<string, string> $fields */
    private function corvusSignature(array $fields, string $secret): string
    {
        unset($fields['signature']);
        ksort($fields, SORT_STRING);

        $message = '';
        foreach ($fields as $key => $value) {
            $message .= $key.$value;
        }

        return hash_hmac('sha256', $message, $secret);
    }

    private function runPaymentLocalizationMigration(): void
    {
        $migration = require database_path('migrations/2026_08_30_084000_localize_termol_payment_methods.php');
        $migration->up();
    }
}
