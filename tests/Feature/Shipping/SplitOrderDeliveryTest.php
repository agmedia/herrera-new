<?php

namespace Tests\Feature\Shipping;

use App\Models\Catalog\Product\Product;
use App\Models\Integrations\SupplierDeliverySchedule;
use App\Models\Sales\Order\OrderItem;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Front\StoreNotificationService;
use App\Services\Payments\BankTransferUpiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class SplitOrderDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.b2b_only' => false]);
        app()->setLocale('hr');
        Http::preventStrayRequests();
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'Europe/Zagreb'));
        Currency::query()->create(['code' => 'EUR', 'name' => 'Euro', 'exchange_rate' => 1, 'decimal_places' => 2, 'is_default' => true, 'is_active' => true]);
        OrderStatus::query()->create(['code' => 'new', 'name' => 'New', 'is_default' => true, 'is_active' => true]);
        ShippingMethod::query()->create(['code' => 'standard', 'name' => 'Standard', 'price' => 8, 'is_active' => true]);
        PaymentMethod::query()->create(['code' => 'bank', 'name' => 'Bank', 'provider' => 'bank', 'fee_type' => 'fixed', 'fee_value' => 0, 'is_active' => true]);
        $this->mock(BankTransferUpiService::class)->shouldReceive('ensureForOrder')->andReturn(null);
    }

    public static function destinations(): array
    {
        return ['Croatia' => ['HR', '2026-11-11', '2026-12-01', '48 sati'],
            'international' => ['SI', '2026-11-14', '2026-12-04', 'Dostava do 5 dana']];
    }

    #[DataProvider('destinations')]
    public function test_checkout_saves_two_fulfilment_rows_with_one_financial_line_and_one_shipping_charge(string $country, string $localDate, string $supplierDate, string $localLabel): void
    {
        $product = $this->product();
        app(CartService::class)->add($product, 10);
        // Missing shipping country must fall back to billing country consistently.
        $order = app(CheckoutService::class)->placeOrder($this->payload($country));
        $item = $order->items()->sole();
        $rows = $item->fulfilmentRows();

        $this->assertSame(10, $item->quantity);
        $this->assertSame(10, $order->item_qty);
        $this->assertSame([5, 5], array_column($rows, 'quantity'));
        $this->assertSame(['local', 'supplier'], array_column($rows, 'source'));
        $this->assertSame([$localDate, $supplierDate], array_column($rows, 'delivery_date'));
        $this->assertSame($localLabel, $rows[0]['label']);
        $this->assertSame($country, $order->shipping_country_code);
        $this->assertSame($country, data_get($item->payload, 'delivery.country_code'));
        $this->assertSame(8.0, (float) $order->shipping_total);
        $this->assertSame(1, $order->totals()->where('code', 'shipping')->count());
        $this->assertSame(100.0, (float) $item->line_total);

        $product->update(['stock_qty' => 100, 'supplier_stock_qty' => 0]);
        SupplierDeliverySchedule::query()->where('supplier', 'master')->update(['lead_days' => 90]);
        $this->travel(20)->days();
        $this->assertSame($rows, $item->fresh()->fulfilmentRows());
        $this->assertSame(1, $order->items()->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_unknown_supplier_still_keeps_the_local_part_and_does_not_invent_a_supplier_date(): void
    {
        $product = $this->product();
        $product->update(['payload' => ['supplier_catalog' => ['supplier' => 'unknown']]]);
        app(CartService::class)->add($product, 10);
        $item = app(CheckoutService::class)->placeOrder($this->payload('HR'))->items()->sole();
        $rows = $item->fulfilmentRows();
        $this->assertSame([5, 5], array_column($rows, 'quantity'));
        $this->assertSame('2026-11-11', $rows[0]['delivery_date']);
        $this->assertSame('48 sati', $rows[0]['label']);
        $this->assertNull($rows[1]['delivery_date']);
        $this->assertSame('Rok isporuke na upit', $rows[1]['label']);
    }

    public function test_pickup_has_no_customer_transport_days_for_either_part(): void
    {
        app(\App\Services\Shipping\HerreraPickupConfiguration::class)->configure();
        app(CartService::class)->add($this->product(), 10);
        $order = app(CheckoutService::class)->placeOrder(array_replace($this->payload('SI'), ['shipping_method_code' => 'herrera_pickup']));
        $rows = $order->items()->sole()->fulfilmentRows();
        $this->assertSame('Preuzimanje uz dogovor', $rows[0]['label']);
        $this->assertSame(['2026-11-09', '2026-11-29'], array_column($rows, 'delivery_date'));
        $this->assertSame(0.0, (float) $order->shipping_total);
    }

    public function test_email_and_print_display_the_two_saved_delivery_rows_without_duplicating_financial_items(): void
    {
        app(CartService::class)->add($this->product(), 10);
        $order = app(CheckoutService::class)->placeOrder($this->payload('HR'));
        $data = (new ReflectionMethod(StoreNotificationService::class, 'buildOrderMailData'))->invoke(app(StoreNotificationService::class), $order);
        $this->assertCount(1, $data['items']);
        $this->assertSame(10, $data['items'][0]['quantity']);
        $this->assertSame([5, 5], array_column($data['items'][0]['fulfilments'], 'quantity'));

        foreach ([view('emails.orders.notification', $data + ['variant' => 'customer'])->render(),
            view('admin.orders.invoice', ['order' => $order->load('totals')])->render()] as $html) {
            $this->assertSame(1, substr_count($html, 'data-order-fulfilment="local"'));
            $this->assertSame(1, substr_count($html, 'data-order-fulfilment="supplier"'));
            $this->assertStringContainsString('48 sati', $html);
            $this->assertStringContainsString('01.12.', $html);
        }
        Mail::assertNothingSent();
    }

    public function test_legacy_and_invalid_saved_rows_fall_back_to_saved_quantities_and_dates_only(): void
    {
        $item = new OrderItem(['quantity' => 10, 'payload' => [
            'inventory' => ['local_quantity' => 3, 'supplier_quantity' => 7],
            'delivery' => ['status' => 'mixed', 'first_delivery_date' => '2026-11-11', 'delivery_date' => '2026-12-01',
                'fulfilments' => [['source' => 'local', 'quantity' => 999, 'label' => 'Invalid']]],
        ]]);
        $rows = $item->fulfilmentRows();
        $this->assertSame([3, 7], array_column($rows, 'quantity'));
        $this->assertSame(['2026-11-11', '2026-12-01'], array_column($rows, 'delivery_date'));
        $item->payload = null;
        $this->assertSame(10, $item->fulfilmentRows()[0]['quantity']);
        $this->assertNull($item->fulfilmentRows()[0]['delivery_date']);
    }

    public function test_success_account_and_admin_pages_show_both_saved_delivery_parts(): void
    {
        app(CartService::class)->add($this->product(), 10);
        $order = app(CheckoutService::class)->placeOrder($this->payload('HR'));
        $success = $this->withSession(['front.checkout.last_order_id' => $order->id])
            ->get(route('checkout.success', $order->order_number))->assertOk();
        $customer = User::factory()->create();
        $order->update(['user_id' => $customer->id]);
        $account = $this->actingAs($customer)->get(route('account.orders.show', $order->order_number))->assertOk();
        $admin = User::factory()->create();
        $admin->assign('superadmin');
        $adminPage = $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk();

        foreach ([$success, $account, $adminPage] as $response) {
            $response->assertSee('data-order-fulfilment="local"', false)
                ->assertSee('data-order-fulfilment="supplier"', false)
                ->assertSee('48 sati');
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    #[DataProvider('destinations')]
    public function test_selected_country_preview_matches_confirmation_and_keeps_stock_private(string $country, string $localDate, string $supplierDate, string $localLabel): void
    {
        $product = $this->product();
        $cart = app(CartService::class);
        $cart->add($product, 10);
        $line = $cart->lines(countryCode: $country)->sole();
        $this->assertSame($localDate, $line['delivery']['first_delivery_date']);
        $this->assertSame($supplierDate, $line['delivery']['delivery_date']);
        $this->assertSame($localLabel, $line['delivery']['first_delivery_label']);
        $preview = $this->getJson(route('checkout.options', ['billing_country_code' => $country, 'shipping_method_code' => 'standard']))
            ->assertOk()->assertJsonPath('delivery_estimates.0.key', $product->id.':0')
            ->assertJsonPath('delivery_estimates.0.label', $line['delivery']['label']);
        $this->assertArrayNotHasKey('stock_qty', $preview->json('delivery_estimates.0'));
        $this->assertArrayNotHasKey('quantity', $preview->json('delivery_estimates.0'));
        $order = app(CheckoutService::class)->placeOrder($this->payload($country));
        $this->assertSame($preview->json('delivery_estimates.0.label'), data_get($order->items()->sole()->payload, 'delivery.label'));
    }

    public function test_shipping_address_and_pickup_changes_refresh_checkout_preview(): void
    {
        app(\App\Services\Shipping\HerreraPickupConfiguration::class)->configure();
        $product = $this->product();
        app(CartService::class)->add($product, 10);
        $this->getJson(route('checkout.options', ['billing_country_code' => 'HR', 'shipping_country_code' => 'SI', 'ship_to_different_address' => 1, 'shipping_method_code' => 'standard']))
            ->assertOk()->assertJsonPath('delivery_estimates.0.label', 'Dio pošiljke za 5 dana, ostatak oko 25 dana (04.12.)');
        $this->getJson(route('checkout.options', ['billing_country_code' => 'HR', 'shipping_country_code' => 'SI', 'ship_to_different_address' => 0, 'shipping_method_code' => 'standard']))
            ->assertOk()->assertJsonPath('delivery_estimates.0.label', 'Dio pošiljke za 2 dana, ostatak oko 22 dana (01.12.)');
        $pickup = $this->getJson(route('checkout.options', ['billing_country_code' => 'SI', 'shipping_method_code' => 'herrera_pickup']))
            ->assertOk();
        $this->assertStringContainsString('ostatak oko 20 dana (29.11.)', $pickup->json('delivery_estimates.0.label'));
    }

    public function test_saved_customer_country_is_used_on_cart_and_product_and_endpoint_validates_override(): void
    {
        $customer = User::factory()->create();
        $customer->addresses()->create(['type' => 'billing', 'country_code' => 'SI', 'address_line_1' => 'Test 1', 'city' => 'Ljubljana']);
        $this->actingAs($customer);
        $product = $this->product();
        $cart = app(CartService::class);
        $cart->add($product, 10);
        $this->assertSame('Dostava do 5 dana', $cart->lines()->sole()['delivery']['first_delivery_label']);
        $this->get(route('checkout.create'))->assertOk()->assertSee('Dio pošiljke za 5 dana')
            ->assertSee('data-cart-line-delivery', false);
        $this->get(route('products.show', ['slug' => 'split-delivery']))->assertOk()->assertSee('data-product-delivery-country="SI"', false);
        $this->getJson(route('products.delivery', $product).'?quantity=10')->assertOk()->assertJsonPath('first_delivery_label', 'Dostava do 5 dana');
        $this->getJson(route('products.delivery', $product).'?quantity=10&country_code=hr')->assertOk()->assertJsonPath('first_delivery_label', '48 sati');
        $this->getJson(route('products.delivery', $product).'?quantity=10&country_code=ZZ')->assertUnprocessable();
    }

    public function test_metre_units_are_saved_and_remain_correct_after_the_product_unit_changes(): void
    {
        $product = $this->product();
        $product->update(['unit_of_measure' => 'm']);
        app(CartService::class)->add($product, 10);
        $item = app(CheckoutService::class)->placeOrder($this->payload('HR'))->items()->sole();
        $this->assertSame('m', data_get($item->payload, 'unit_of_measure'));
        $this->assertSame(['m', 'm'], array_column($item->fulfilmentRows(), 'unit'));
        $product->update(['unit_of_measure' => 'kg']);
        $html = view('shared.order-fulfilments', ['fulfilments' => $item->fresh()->fulfilmentRows()])->render();
        $this->assertSame(2, substr_count($html, '<strong>5 m</strong>'));
        $this->assertStringNotContainsString('kom', $html);
        $legacy = new OrderItem(['quantity' => 5]);
        $this->assertSame('', $legacy->fulfilmentRows()[0]['unit']);
    }

    private function product(): Product
    {
        $product = Product::query()->create(['code' => 'SPLIT', 'sku' => 'SPLIT', 'base_price' => 10, 'stock_qty' => 5, 'supplier_stock_qty' => 50, 'is_active' => true,
            'payload' => ['supplier_catalog' => ['supplier' => 'master']]]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Split delivery product', 'slug' => 'split-delivery']);

        return $product;
    }

    private function payload(string $country): array
    {
        return ['customer_email' => 'split@example.test', 'billing_country_code' => $country, 'billing_postal_code' => '10000', 'billing_city' => 'Zagreb',
            'billing_address_line_1' => 'Test1', 'shipping_method_code' => 'standard', 'payment_method_code' => 'bank'];
    }
}
