<?php

namespace Tests\Feature\Payments;

use App\Models\Catalog\Product\Product;
use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Front\CartService;
use App\Services\Payments\BankTransferUpiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HerreraBankTransferFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['commerce.b2b_only' => false, 'commerce.local_safe_mode' => true]);
        Http::preventStrayRequests();
    }

    public function test_local_safe_mode_preserves_payment_instructions_without_requesting_a_barcode(): void
    {
        Http::fake();
        $instructions = "Herrera d.o.o.\nIBAN: HR1210010051863000160";
        $this->bankMethod(['bank_instructions' => $instructions]);
        $order = $this->bankOrder();

        $snapshot = app(BankTransferUpiService::class)->ensureForOrder($order);

        Http::assertNothingSent();
        $this->assertSame($instructions, $snapshot['instructions']);
        $this->assertSame('HR1210010051863000160', $snapshot['receiver_iban']);
        $this->assertSame(12345, $snapshot['amount_cents']);
        $this->assertSame('', $snapshot['qr_image_base64']);
        $this->assertNotEmpty($snapshot['reference']);
        $this->assertSame($snapshot, $order->fresh()->payload['bank_transfer']);
    }

    public function test_barcode_generation_remains_available_outside_local_safe_mode(): void
    {
        config(['commerce.local_safe_mode' => false]);
        Http::fake(['https://hub3.bigfish.software/api/v2/barcode' => Http::response('barcode-image', 200, ['Content-Type' => 'image/png'])]);
        $this->bankMethod();

        $snapshot = app(BankTransferUpiService::class)->ensureForOrder($this->bankOrder());

        Http::assertSent(fn ($request): bool => $request->url() === 'https://hub3.bigfish.software/api/v2/barcode'
            && $request['data']['amount'] === 12345
            && $request['data']['receiver']['iban'] === 'HR1210010051863000160');
        $this->assertSame(base64_encode('barcode-image'), $snapshot['qr_image_base64']);
        $this->assertSame('image/png', $snapshot['qr_image_mime']);
    }

    public function test_local_safe_mode_keeps_existing_instructions_and_barcode_after_method_settings_change(): void
    {
        Http::fake();
        $this->bankMethod(['bank_instructions' => 'Changed bank instructions']);
        $order = $this->bankOrder(['payload' => ['bank_transfer' => [
            'instructions' => 'Original bank instructions',
            'receiver_iban' => 'HR1210010051863000160',
            'reference' => '12326',
            'qr_image_base64' => base64_encode('existing-image'),
            'qr_image_mime' => 'image/png',
        ]]]);

        $snapshot = app(BankTransferUpiService::class)->ensureForOrder($order);

        Http::assertNothingSent();
        $this->assertSame('Original bank instructions', $snapshot['instructions']);
        $this->assertSame('12326', $snapshot['reference']);
        $this->assertSame(base64_encode('existing-image'), $snapshot['qr_image_base64']);
    }

    public function test_receipt_displays_legacy_text_instructions_without_a_structured_iban_and_escapes_html(): void
    {
        Http::fake();
        $instructions = "Uplata po ponudi\n<script>alert('bank')</script>";
        PaymentMethod::query()->create([
            'code' => 'bank', 'name' => 'Uplata na račun', 'provider' => 'bank', 'is_active' => true,
            'settings' => ['bank_instructions' => $instructions],
        ]);
        $order = $this->bankOrder();

        $this->withSession(['front.checkout.last_order_id' => $order->id])
            ->get(route('checkout.success', ['orderNumber' => $order->order_number]))
            ->assertOk()
            ->assertSee($instructions)
            ->assertDontSee("<script>alert('bank')</script>", false);

        Http::assertNothingSent();
    }

    public function test_checkout_keeps_method_descriptions_visible_when_options_refresh(): void
    {
        $paymentDescription = 'Platite prema podacima za bankovnu uplatu.';
        $shippingDescription = 'Dostava na adresu poslovnog kupca.';
        $this->bankMethod()->update(['description' => $paymentDescription]);
        ShippingMethod::query()->create([
            'code' => 'flat', 'name' => 'Dostavnom službom', 'price' => 5,
            'description' => $shippingDescription, 'is_active' => true,
        ]);
        $product = Product::query()->create([
            'code' => 'CHECKOUT-DESCRIPTION', 'sku' => 'CHECKOUT-DESCRIPTION',
            'base_price' => 20, 'stock_qty' => 2, 'is_active' => true, 'is_visible' => true,
        ]);
        $product->translations()->create(['locale' => 'hr', 'name' => 'Checkout proizvod', 'slug' => 'checkout-proizvod']);
        $this->assertTrue(app(CartService::class)->add($product, 1));

        $this->get(route('checkout.create'))
            ->assertOk()
            ->assertSee($paymentDescription)
            ->assertSee($shippingDescription);
        $this->getJson(route('checkout.options'))
            ->assertOk()
            ->assertJsonPath('shipping_methods.0.description', $shippingDescription)
            ->assertJsonPath('payment_methods.0.description', $paymentDescription);
    }

    private function bankMethod(array $settings = []): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'code' => 'bank', 'name' => 'Uplata na račun', 'provider' => 'bank', 'is_active' => true,
            'settings' => array_merge([
                'upi_receiver_name' => 'Herrera d.o.o.',
                'upi_receiver_street' => 'Testna 1',
                'upi_receiver_place' => '10000 Zagreb',
                'upi_receiver_iban' => 'HR12 1001 0051 8630 0016 0',
            ], $settings),
        ]);
    }

    private function bankOrder(array $attributes = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'HERRERA-BANK-TEST', 'source' => 'web', 'locale' => 'hr',
            'currency_code' => 'EUR', 'currency_rate' => 1, 'customer_name' => 'Test Kupac',
            'customer_email' => 'kupac@example.test', 'payment_method_code' => 'bank',
            'payment_method_name' => 'Uplata na račun', 'shipping_method_code' => 'flat',
            'shipping_method_name' => 'Dostavnom službom', 'item_qty' => 0, 'subtotal' => 123.45,
            'grand_total' => 123.45, 'payload' => [], 'placed_at' => now(),
        ], $attributes));
    }
}
