<?php

namespace Tests\Feature\Shipping;

use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Services\Front\CartService;
use App\Services\Front\CheckoutService;
use App\Services\Settings\SystemSettingsService;
use App\Services\Shipping\CroatianIslandDestinationClassifier;
use App\Services\Shipping\ShippingCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class CroatianIslandCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_offers_only_the_matching_mbe_tariff_and_keeps_boxnow_and_pickup(): void
    {
        $checkout = $this->checkoutService();

        $visCodes = $checkout
            ->availableShippingMethods(50, 'HR', null, '21480', 'Vis')
            ->pluck('code')
            ->all();

        $this->assertContains('mbe_islands_hr', $visCodes);
        $this->assertNotContains('mbe_mainland_hr', $visCodes);
        $this->assertContains('boxnow', $visCodes);
        $this->assertContains('pickup', $visCodes);

        $zagrebCodes = $checkout
            ->availableShippingMethods(50, 'HR', null, '10000', 'Zagreb')
            ->pluck('code')
            ->all();

        $this->assertContains('mbe_mainland_hr', $zagrebCodes);
        $this->assertNotContains('mbe_islands_hr', $zagrebCodes);
        $this->assertContains('boxnow', $zagrebCodes);
        $this->assertContains('pickup', $zagrebCodes);

        $ambiguousCodes = $checkout
            ->availableShippingMethods(50, 'HR', null, '21220', 'Trogir')
            ->pluck('code')
            ->all();

        $this->assertContains('mbe_mainland_hr', $ambiguousCodes);
        $this->assertNotContains('mbe_islands_hr', $ambiguousCodes);
        $this->assertContains('boxnow', $ambiguousCodes);
        $this->assertContains('pickup', $ambiguousCodes);
    }

    public function test_admin_policy_changes_bridged_islands_without_affecting_unconnected_islands(): void
    {
        $checkout = $this->checkoutService();
        $settings = app(SystemSettingsService::class);

        $settings->put(
            CroatianIslandDestinationClassifier::SETTING_KEY,
            CroatianIslandDestinationClassifier::POLICY_UNCONNECTED_ONLY,
        );
        $this->assertContains(
            'mbe_mainland_hr',
            $checkout->availableShippingMethods(50, 'HR', null, '51500', 'Krk')->pluck('code')->all(),
        );
        $this->assertContains(
            'mbe_islands_hr',
            $checkout->availableShippingMethods(50, 'HR', null, '21480', 'Vis')->pluck('code')->all(),
        );

        $settings->put(
            CroatianIslandDestinationClassifier::SETTING_KEY,
            CroatianIslandDestinationClassifier::POLICY_ALL_ISLANDS,
        );
        $checkout = $this->checkoutService();
        $this->assertContains(
            'mbe_islands_hr',
            $checkout->availableShippingMethods(50, 'HR', null, '51500', 'Krk')->pluck('code')->all(),
        );
        $this->assertContains(
            'mbe_islands_hr',
            $checkout->availableShippingMethods(50, 'HR', null, '21480', 'Vis')->pluck('code')->all(),
        );
    }

    public function test_checkout_keeps_a_safe_address_delivery_option_while_product_weight_is_missing(): void
    {
        $checkout = $this->checkoutService(productWeight: null);

        $initialCodes = $checkout
            ->availableShippingMethods(50, 'HR')
            ->pluck('code')
            ->all();
        $zagrebCodes = $checkout
            ->availableShippingMethods(50, 'HR', null, '10000', 'Zagreb')
            ->pluck('code')
            ->all();

        $this->assertContains('mbe_missing_weight_quote_hr', $initialCodes);
        $this->assertContains('mbe_missing_weight_quote_hr', $zagrebCodes);
        $this->assertNotContains('mbe_mainland_hr', $zagrebCodes);
        $this->assertNotContains('mbe_islands_hr', $zagrebCodes);
        $this->assertNotContains('boxnow', $zagrebCodes);
        $this->assertContains('pickup', $zagrebCodes);

        $nonCroatianCodes = $checkout
            ->availableShippingMethods(50, 'SI', null, '1000', 'Ljubljana')
            ->pluck('code')
            ->all();
        $this->assertNotContains('mbe_missing_weight_quote_hr', $nonCroatianCodes);
    }

    public function test_checkout_hides_an_active_boxnow_method_when_any_safety_setting_is_invalid(): void
    {
        $checkout = $this->checkoutService();
        $boxNow = ShippingMethod::query()->where('code', 'boxnow')->firstOrFail();

        $boxNow->update(['service_type' => 'home_delivery']);
        $this->assertNotContains(
            'boxnow',
            $checkout->availableShippingMethods(50, 'HR', null, '10000', 'Zagreb')->pluck('code')->all(),
        );

        $boxNow->update([
            'service_type' => 'parcel_locker',
            'max_weight_kg' => null,
        ]);
        $this->assertNotContains(
            'boxnow',
            $checkout->availableShippingMethods(50, 'HR', null, '10000', 'Zagreb')->pluck('code')->all(),
        );

        $boxNow->update([
            'max_weight_kg' => 20,
            'allows_fragile' => true,
        ]);
        $this->assertNotContains(
            'boxnow',
            $checkout->availableShippingMethods(50, 'HR', null, '10000', 'Zagreb')->pluck('code')->all(),
        );
    }

    public function test_server_rejects_a_tampered_mainland_method_for_vis_and_persists_the_destination_snapshot(): void
    {
        $checkout = $this->checkoutService(withOrderDependencies: true);
        $payload = $this->checkoutPayload([
            'shipping_method_code' => 'mbe_mainland_hr',
            'billing_postal_code' => '21480',
            'billing_city' => 'Vis',
            'shipping_postal_code' => '21480',
            'shipping_city' => 'Vis',
        ]);

        try {
            $checkout->placeOrder($payload);
            $this->fail('A mainland tariff must not be accepted for Vis.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('shipping_method_code', $exception->errors());
        }

        $order = $checkout->placeOrder(array_merge($payload, [
            'shipping_method_code' => 'mbe_islands_hr',
        ]));

        $this->assertSame('hr_islands', data_get($order->payload, 'shipping.destination.scope'));
        $this->assertSame('Vis', data_get($order->payload, 'shipping.destination.island'));
        $this->assertFalse((bool) data_get($order->payload, 'shipping.destination.road_connected_to_mainland'));
        $this->assertSame(
            CroatianIslandDestinationClassifier::POLICY_UNCONNECTED_ONLY,
            data_get($order->payload, 'shipping.destination.policy'),
        );
        $this->assertSame(10.0, (float) $order->shipping_total);
    }

    private function checkoutService(
        bool $withOrderDependencies = false,
        ?float $productWeight = 2,
    ): CheckoutService {
        $identity = strtolower((string) str()->ulid());
        $product = Product::query()->create([
            'code' => 'island-shipping-'.$identity,
            'sku' => 'ISLAND-SHIPPING-'.strtoupper($identity),
            'is_active' => true,
            'base_price' => 50,
            'stock_qty' => 10,
            'weight_kg' => $productWeight,
            'length_cm' => 20,
            'width_cm' => 20,
            'height_cm' => 20,
            'shipping_labels' => [],
        ]);
        $product->setRelation('categories', collect());
        $product->setRelation('packages', collect());

        $lines = new Collection([[
            'product' => $product,
            'translation' => null,
            'quantity' => 1,
            'base_unit_price' => 50,
            'unit_price' => 50,
            'display_unit_price' => 50,
            'line_discount_total' => 0,
            'line_tax_total' => 0,
            'tax_rate' => 0,
            'line_total' => 50,
        ]]);

        $cart = Mockery::mock(CartService::class);
        $cart->shouldReceive('lines')->zeroOrMoreTimes()->andReturn($lines);
        $cart->shouldReceive('summary')->zeroOrMoreTimes()->andReturn([
            'subtotal' => 50,
            'subtotal_after_discount' => 50,
            'discount_total' => 0,
            'tax_total' => 0,
            'tax_rate' => 0,
            'grand_total' => 50,
            'coupon_code' => '',
        ]);

        ShippingMethod::query()->updateOrCreate(
            ['code' => 'boxnow'],
            [
                'name' => 'BOX NOW paketomat',
                'carrier' => 'boxnow',
                'service_type' => 'parcel_locker',
                'pricing_type' => 'flat',
                'price' => 2.99,
                'max_weight_kg' => 20,
                'max_length_cm' => 60,
                'max_width_cm' => 45,
                'max_height_cm' => 36,
                'allows_fragile' => false,
                'allows_oversized' => false,
                'allows_heavy' => false,
                'missing_measurements_policy' => 'block',
                'is_active' => true,
                'sort_order' => 70,
                'settings' => ['boxnow_partner_id' => 'partner-test'],
            ],
        );

        if ($withOrderDependencies) {
            PaymentMethod::query()->updateOrCreate(
                ['code' => 'bank'],
                [
                    'name' => 'Plaćanje po ponudi / virmanu',
                    'provider' => 'bank',
                    'fee_type' => 'fixed',
                    'fee_value' => 0,
                    'is_active' => true,
                    'sort_order' => 1,
                ],
            );
            Currency::query()->updateOrCreate(
                ['code' => 'EUR'],
                [
                    'name' => 'Euro',
                    'symbol' => '€',
                    'symbol_position' => 'right',
                    'decimal_places' => 2,
                    'exchange_rate' => 1,
                    'is_default' => true,
                    'is_active' => true,
                    'sort_order' => 1,
                ],
            );
            OrderStatus::query()->updateOrCreate(
                ['code' => 'new'],
                [
                    'name' => 'Nova',
                    'is_default' => true,
                    'is_active' => true,
                    'sort_order' => 1,
                ],
            );
        }

        return new CheckoutService(
            $cart,
            app(ShippingCalculator::class),
            app(CroatianIslandDestinationClassifier::class),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function checkoutPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_first_name' => 'Test',
            'customer_last_name' => 'Kupac',
            'customer_email' => 'kupac@example.test',
            'customer_phone' => '+385911234567',
            'billing_first_name' => 'Test',
            'billing_last_name' => 'Kupac',
            'billing_address_line_1' => 'Riva 1',
            'billing_postal_code' => '21480',
            'billing_city' => 'Vis',
            'billing_country_code' => 'HR',
            'shipping_first_name' => 'Test',
            'shipping_last_name' => 'Kupac',
            'shipping_address_line_1' => 'Riva 1',
            'shipping_postal_code' => '21480',
            'shipping_city' => 'Vis',
            'shipping_country_code' => 'HR',
            'shipping_method_code' => 'mbe_islands_hr',
            'payment_method_code' => 'bank',
        ], $overrides);
    }
}
