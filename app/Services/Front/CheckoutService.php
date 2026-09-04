<?php

namespace App\Services\Front;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderHistory;
use App\Models\Sales\Order\OrderItem;
use App\Models\Sales\Order\OrderTotal;
use App\Models\Settings\Local\Currency;
use App\Models\Settings\Local\GeoZoneCountry;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\User;
use App\Services\Payments\CorvusPayFormService;
use App\Services\Shipping\CroatianIslandDestinationClassifier;
use App\Services\Shipping\ShippingCalculator;
use App\Support\GlsShipping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    private readonly CroatianIslandDestinationClassifier $islandDestinationClassifier;

    public function __construct(
        private readonly CartService $cart,
        private readonly ShippingCalculator $shippingCalculator,
        ?CroatianIslandDestinationClassifier $islandDestinationClassifier = null,
    ) {
        $this->islandDestinationClassifier = $islandDestinationClassifier
            ?? app(CroatianIslandDestinationClassifier::class);
    }

    /**
     * @return Collection<int, PaymentMethod>
     */
    public function availablePaymentMethods(
        float $subtotal,
        ?string $countryCode = null,
        ?string $regionCode = null,
        ?string $postalCode = null,
        ?ShippingMethod $shippingMethod = null,
    ): Collection {
        $zoneIds = $this->resolveGeoZoneIdsForAddress($countryCode, $regionCode, $postalCode);
        $corvusPay = app(CorvusPayFormService::class);

        return PaymentMethod::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (PaymentMethod $method) => $corvusPay->canBeOffered($method))
            ->filter(fn (PaymentMethod $method) => $this->paymentMethodMatchesShippingMethod($method, $shippingMethod))
            ->filter(fn (PaymentMethod $method) => $this->methodMatchesGeoZones($method->geo_zone_id, $zoneIds))
            ->filter(fn (PaymentMethod $method) => $this->subtotalFits($subtotal, $method->min_subtotal, $method->max_subtotal))
            ->values();
    }

    /**
     * @return Collection<int, ShippingMethod>
     */
    public function availableShippingMethods(
        float $subtotal,
        ?string $countryCode = null,
        ?string $regionCode = null,
        ?string $postalCode = null,
        ?string $city = null,
    ): Collection {
        $zoneIds = $this->resolveGeoZoneIdsForAddress($countryCode, $regionCode, $postalCode);
        $lines = $this->cart->lines();
        $destination = $this->islandDestinationClassifier->classify($countryCode, $postalCode, $city);

        return ShippingMethod::query()
            ->where('is_active', true)
            ->with('rates')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (ShippingMethod $method) => $this->shippingMethodIsConfigured($method))
            ->filter(fn (ShippingMethod $method) => $this->methodMatchesGeoZones($method->geo_zone_id, $zoneIds))
            ->filter(fn (ShippingMethod $method) => $this->methodMatchesDestinationScope(
                $method,
                $destination->scope,
                $countryCode,
            ))
            ->filter(fn (ShippingMethod $method) => $this->subtotalFits($subtotal, $method->min_subtotal, $method->max_subtotal))
            ->filter(function (ShippingMethod $method) use ($lines, $subtotal): bool {
                $quote = $this->shippingCalculator->quote($method, $lines, $subtotal);
                if ($quote === null) {
                    return false;
                }

                $method->setAttribute('resolved_price', (float) $quote['price']);
                $method->setAttribute('shipping_quote', $quote);

                return true;
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function placeOrder(array $payload, ?User $user = null): Order
    {
        $lines = $this->cart->lines();
        $summary = $this->cart->summary();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => 'Your cart is empty.',
            ]);
        }

        $subtotal = round((float) ($summary['subtotal'] ?? 0), 2);
        $discountTotal = round((float) ($summary['discount_total'] ?? 0), 2);
        $subtotalAfterDiscount = round((float) ($summary['subtotal_after_discount'] ?? $subtotal), 2);
        $taxTotal = round((float) ($summary['tax_total'] ?? 0), 2);
        $defaultTaxRate = round((float) ($summary['tax_rate'] ?? 0), 4);

        $shippingCountryCode = (string) ($payload['shipping_country_code'] ?? $payload['billing_country_code'] ?? 'HR');
        $shippingRegionCode = (string) ($payload['shipping_state'] ?? $payload['billing_state'] ?? '');
        $shippingPostalCode = (string) ($payload['shipping_postal_code'] ?? $payload['billing_postal_code'] ?? '');
        $shippingCity = (string) ($payload['shipping_city'] ?? $payload['billing_city'] ?? '');
        $shippingDestination = $this->islandDestinationClassifier->classify(
            $shippingCountryCode,
            $shippingPostalCode,
            $shippingCity,
        );

        /** @var ShippingMethod|null $shippingMethod */
        $shippingMethod = $this->availableShippingMethods(
            $subtotalAfterDiscount,
            $shippingCountryCode,
            $shippingRegionCode,
            $shippingPostalCode,
            $shippingCity,
        )
            ->firstWhere('code', (string) ($payload['shipping_method_code'] ?? ''));

        $billingCountryCode = (string) ($payload['billing_country_code'] ?? $shippingCountryCode);
        $billingRegionCode = (string) ($payload['billing_state'] ?? '');
        $billingPostalCode = (string) ($payload['billing_postal_code'] ?? '');

        /** @var PaymentMethod|null $paymentMethod */
        $paymentMethod = $this->availablePaymentMethods(
            $subtotalAfterDiscount,
            $billingCountryCode,
            $billingRegionCode,
            $billingPostalCode,
            $shippingMethod,
        )
            ->firstWhere('code', (string) ($payload['payment_method_code'] ?? ''));

        if (! $shippingMethod) {
            throw ValidationException::withMessages([
                'shipping_method_code' => 'Selected shipping method is not available.',
            ]);
        }

        if (! $paymentMethod) {
            throw ValidationException::withMessages([
                'payment_method_code' => 'Selected payment method is not available.',
            ]);
        }

        if ($this->isBoxNowCode((string) $shippingMethod->code)) {
            $lockerId = trim((string) ($payload['shipping_boxnow_locker_id'] ?? ''));
            if ($lockerId === '') {
                throw ValidationException::withMessages([
                    'shipping_boxnow_locker_id' => __('ui.checkout.validation.boxnow_locker_required'),
                ]);
            }
        }

        if (GlsShipping::isGlsDpmShippingMethod($shippingMethod)) {
            $glsDpmId = trim((string) ($payload['shipping_gls_dpm_id'] ?? ''));
            if ($glsDpmId === '') {
                throw ValidationException::withMessages([
                    'shipping_gls_dpm_id' => __('Odaberite GLS paketomat ili ParcelShop lokaciju.'),
                ]);
            }
        }

        $shippingTotal = $this->resolveShippingTotal($shippingMethod, $subtotalAfterDiscount);
        $paymentFeeTotal = $this->resolvePaymentFeeTotal($paymentMethod, $subtotalAfterDiscount);
        $grandTotal = round($subtotalAfterDiscount + $shippingTotal + $paymentFeeTotal + $taxTotal, 2);

        /** @var Currency|null $currency */
        $currency = Currency::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        $status = $this->resolveOrderStatusForPayment($paymentMethod);

        $locale = (string) app()->getLocale();

        return DB::transaction(function () use (
            $payload,
            $user,
            $lines,
            $summary,
            $subtotal,
            $discountTotal,
            $taxTotal,
            $defaultTaxRate,
            $shippingMethod,
            $shippingTotal,
            $paymentMethod,
            $paymentFeeTotal,
            $grandTotal,
            $currency,
            $status,
            $locale,
            $shippingDestination,
        ): Order {
            $customerFirst = (string) ($payload['customer_first_name'] ?? '');
            $customerLast = (string) ($payload['customer_last_name'] ?? '');
            $customerName = trim($customerFirst.' '.$customerLast);

            $order = Order::query()->create([
                'order_number' => $this->nextOrderNumber(),
                'status_id' => $status?->id,
                'user_id' => $user?->id,
                'source' => 'web',
                'locale' => $locale,
                'currency_code' => (string) ($currency?->code ?? 'EUR'),
                'currency_rate' => (float) ($currency?->exchange_rate ?? 1),

                'customer_name' => $customerName !== '' ? $customerName : (string) ($user?->name ?? 'Guest Customer'),
                'customer_email' => (string) ($payload['customer_email'] ?? ''),
                'customer_phone' => (string) ($payload['customer_phone'] ?? ''),

                'billing_first_name' => (string) ($payload['billing_first_name'] ?? $customerFirst),
                'billing_last_name' => (string) ($payload['billing_last_name'] ?? $customerLast),
                'billing_company' => (string) ($payload['billing_company'] ?? ''),
                'billing_oib' => (string) ($payload['billing_oib'] ?? ''),
                'billing_vat_id' => (string) ($payload['billing_vat_id'] ?? ''),
                'billing_address_line_1' => (string) ($payload['billing_address_line_1'] ?? ''),
                'billing_address_line_2' => (string) ($payload['billing_address_line_2'] ?? ''),
                'billing_postal_code' => (string) ($payload['billing_postal_code'] ?? ''),
                'billing_city' => (string) ($payload['billing_city'] ?? ''),
                'billing_state' => (string) ($payload['billing_state'] ?? ''),
                'billing_country_code' => (string) ($payload['billing_country_code'] ?? 'HR'),

                'shipping_first_name' => (string) ($payload['shipping_first_name'] ?? $customerFirst),
                'shipping_last_name' => (string) ($payload['shipping_last_name'] ?? $customerLast),
                'shipping_company' => (string) ($payload['shipping_company'] ?? ''),
                'shipping_oib' => (string) ($payload['shipping_oib'] ?? ''),
                'shipping_vat_id' => (string) ($payload['shipping_vat_id'] ?? ''),
                'shipping_address_line_1' => (string) ($payload['shipping_address_line_1'] ?? ''),
                'shipping_address_line_2' => (string) ($payload['shipping_address_line_2'] ?? ''),
                'shipping_postal_code' => (string) ($payload['shipping_postal_code'] ?? ''),
                'shipping_city' => (string) ($payload['shipping_city'] ?? ''),
                'shipping_state' => (string) ($payload['shipping_state'] ?? ''),
                'shipping_country_code' => (string) ($payload['shipping_country_code'] ?? 'HR'),

                'payment_method_code' => (string) $paymentMethod->code,
                'payment_method_name' => (string) $paymentMethod->name,
                'shipping_method_code' => (string) $shippingMethod->code,
                'shipping_method_name' => (string) $shippingMethod->name,

                'item_qty' => (int) $lines->sum('quantity'),
                'subtotal' => $subtotal,
                'shipping_total' => $shippingTotal,
                'payment_fee_total' => $paymentFeeTotal,
                'discount_total' => $discountTotal,
                'tax_total' => $taxTotal,
                'grand_total' => $grandTotal,

                'customer_note' => (string) ($payload['customer_note'] ?? ''),
                'payload' => [
                    'placed_from' => 'frontend_checkout',
                    'coupon_code' => (string) ($summary['coupon_code'] ?? ''),
                    'shipping' => [
                        'destination' => $shippingDestination->toArray(),
                        'calculation' => is_array($shippingMethod->getAttribute('shipping_quote'))
                            ? $shippingMethod->getAttribute('shipping_quote')
                            : null,
                        'boxnow' => $this->isBoxNowCode((string) $shippingMethod->code)
                            ? [
                                'locker_id' => trim((string) ($payload['shipping_boxnow_locker_id'] ?? '')),
                                'locker_name' => trim((string) ($payload['shipping_boxnow_locker_name'] ?? '')),
                                'address_line_1' => trim((string) ($payload['shipping_boxnow_address_line_1'] ?? '')),
                                'postal_code' => trim((string) ($payload['shipping_boxnow_postal_code'] ?? '')),
                                'city' => trim((string) ($payload['shipping_boxnow_city'] ?? '')),
                            ]
                            : null,
                        'gls_dpm' => GlsShipping::isGlsDpmShippingMethod($shippingMethod)
                            ? [
                                'id' => trim((string) ($payload['shipping_gls_dpm_id'] ?? '')),
                                'external_id' => trim((string) ($payload['shipping_gls_dpm_external_id'] ?? '')),
                                'name' => trim((string) ($payload['shipping_gls_dpm_name'] ?? '')),
                                'type' => trim((string) ($payload['shipping_gls_dpm_type'] ?? '')),
                                'address_line_1' => trim((string) ($payload['shipping_gls_dpm_address_line_1'] ?? '')),
                                'postal_code' => trim((string) ($payload['shipping_gls_dpm_postal_code'] ?? '')),
                                'city' => trim((string) ($payload['shipping_gls_dpm_city'] ?? '')),
                                'filter_type' => GlsShipping::glsDpmFilterType($shippingMethod),
                            ]
                            : null,
                    ],
                ],
                'placed_at' => now(),
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]);

            $index = 0;
            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $translation = $line['translation'];

                $quantity = (int) $line['quantity'];
                $baseUnitPrice = (float) ($line['base_unit_price'] ?? $line['unit_price']);
                $unitPrice = (float) $line['unit_price'];
                $lineDiscountTotal = (float) ($line['line_discount_total'] ?? 0);
                $lineTotal = (float) $line['line_total'];
                $lineTaxAmount = round((float) ($line['line_tax_total'] ?? 0), 2);
                $lineTaxRate = round((float) ($line['tax_rate'] ?? $defaultTaxRate), 4);
                $productOptionValueId = isset($line['product_option_value_id']) ? (int) $line['product_option_value_id'] : null;
                $optionRow = $productOptionValueId
                    ? ProductOptionValue::query()->find($productOptionValueId)
                    : null;

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'product_option_value_id' => $productOptionValueId,
                    'sku' => (string) ($optionRow?->sku ?: $product->sku),
                    'code' => $product->code,
                    'name' => (string) ($translation?->name ?? $product->code),
                    'unit_price' => $baseUnitPrice,
                    'discount_amount' => $lineDiscountTotal,
                    'tax_rate' => $lineTaxRate,
                    'tax_amount' => $lineTaxAmount,
                    'quantity' => $quantity,
                    'line_total' => $lineTotal,
                    'sort_order' => $index++,
                    'payload' => [
                        'product_slug' => (string) ($translation?->slug ?? ''),
                    ],
                ]);

                if ($optionRow) {
                    if ((int) $optionRow->stock_qty > 0) {
                        $optionRow->forceFill([
                            'stock_qty' => max(0, ((int) $optionRow->stock_qty) - $quantity),
                        ])->save();
                    }
                } elseif ((int) $product->stock_qty > 0) {
                    $nextStock = max(0, ((int) $product->stock_qty) - $quantity);
                    $product->forceFill(['stock_qty' => $nextStock])->save();
                }
            }

            OrderTotal::query()->create([
                'order_id' => $order->id,
                'code' => 'subtotal',
                'title' => 'Subtotal',
                'value' => $subtotal,
                'sort_order' => 100,
            ]);

            if ($discountTotal > 0) {
                OrderTotal::query()->create([
                    'order_id' => $order->id,
                    'code' => 'discount',
                    'title' => 'Discount',
                    'value' => -1 * $discountTotal,
                    'sort_order' => 150,
                ]);
            }

            OrderTotal::query()->create([
                'order_id' => $order->id,
                'code' => 'shipping',
                'title' => 'Shipping',
                'value' => $shippingTotal,
                'sort_order' => 200,
            ]);

            OrderTotal::query()->create([
                'order_id' => $order->id,
                'code' => 'payment_fee',
                'title' => 'Payment Fee',
                'value' => $paymentFeeTotal,
                'sort_order' => 300,
            ]);

            if ($taxTotal > 0) {
                OrderTotal::query()->create([
                    'order_id' => $order->id,
                    'code' => 'tax',
                    'title' => 'Tax',
                    'value' => $taxTotal,
                    'sort_order' => 400,
                ]);
            }

            OrderTotal::query()->create([
                'order_id' => $order->id,
                'code' => 'grand_total',
                'title' => 'Grand Total',
                'value' => $grandTotal,
                'sort_order' => 900,
            ]);

            OrderHistory::query()->create([
                'order_id' => $order->id,
                'from_status_id' => null,
                'to_status_id' => $status?->id,
                'changed_by' => $user?->id,
                'comment' => 'Order placed from storefront checkout.',
            ]);

            return $order;
        });
    }

    private function subtotalFits(float $subtotal, mixed $min, mixed $max): bool
    {
        $minVal = is_numeric($min) ? (float) $min : null;
        $maxVal = is_numeric($max) ? (float) $max : null;

        if ($minVal !== null && $subtotal < $minVal) {
            return false;
        }

        if ($maxVal !== null && $subtotal > $maxVal) {
            return false;
        }

        return true;
    }

    private function resolveShippingTotal(ShippingMethod $shippingMethod, float $subtotal): float
    {
        if (is_numeric($shippingMethod->getAttribute('resolved_price'))) {
            return round(max(0, (float) $shippingMethod->getAttribute('resolved_price')), 2);
        }

        $price = (float) $shippingMethod->price;
        $freeOver = is_numeric($shippingMethod->free_over) ? (float) $shippingMethod->free_over : null;

        if ($freeOver !== null && $freeOver >= 0 && $subtotal >= $freeOver) {
            return 0.0;
        }

        return round(max(0, $price), 2);
    }

    private function resolvePaymentFeeTotal(PaymentMethod $paymentMethod, float $subtotal): float
    {
        $feeType = (string) ($paymentMethod->fee_type ?? 'fixed');
        $feeValue = (float) ($paymentMethod->fee_value ?? 0);

        if ($feeType === 'percent') {
            return round(($subtotal * max(0, $feeValue)) / 100, 2);
        }

        return round(max(0, $feeValue), 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function estimateCheckoutTotals(
        float $subtotal,
        float $discountTotal,
        float $subtotalAfterDiscount,
        float $taxTotal,
        ?string $shippingMethodCode,
        ?string $paymentMethodCode,
        ?string $shippingCountryCode,
        ?string $shippingRegionCode,
        ?string $shippingPostalCode,
        ?string $billingCountryCode,
        ?string $billingRegionCode,
        ?string $billingPostalCode,
        ?string $shippingCity = null,
        ?string $billingCity = null,
    ): array {
        $shippingMethod = $this->availableShippingMethods(
            $subtotalAfterDiscount,
            $shippingCountryCode,
            $shippingRegionCode,
            $shippingPostalCode,
            $shippingCity,
        )->firstWhere('code', (string) $shippingMethodCode);
        if (! $shippingMethod) {
            $shippingMethod = $this->availableShippingMethods(
                $subtotalAfterDiscount,
                $shippingCountryCode,
                $shippingRegionCode,
                $shippingPostalCode,
                $shippingCity,
            )->first();
        }

        $paymentMethod = $this->availablePaymentMethods(
            $subtotalAfterDiscount,
            $billingCountryCode,
            $billingRegionCode,
            $billingPostalCode,
            $shippingMethod,
        )->firstWhere('code', (string) $paymentMethodCode);
        if (! $paymentMethod) {
            $paymentMethod = $this->availablePaymentMethods(
                $subtotalAfterDiscount,
                $billingCountryCode,
                $billingRegionCode,
                $billingPostalCode,
                $shippingMethod,
            )->first();
        }

        $shippingTotal = $shippingMethod ? $this->resolveShippingTotal($shippingMethod, $subtotalAfterDiscount) : 0.0;
        $paymentFeeTotal = $paymentMethod ? $this->resolvePaymentFeeTotal($paymentMethod, $subtotalAfterDiscount) : 0.0;
        $grandTotal = round($subtotalAfterDiscount + $shippingTotal + $paymentFeeTotal + $taxTotal, 2);

        return [
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'subtotal_after_discount' => round($subtotalAfterDiscount, 2),
            'tax_total' => round($taxTotal, 2),
            'shipping_total' => round($shippingTotal, 2),
            'payment_fee_total' => round($paymentFeeTotal, 2),
            'grand_total' => $grandTotal,
            'shipping_method_code' => (string) ($shippingMethod?->code ?? ''),
            'payment_method_code' => (string) ($paymentMethod?->code ?? ''),
        ];
    }

    /**
     * @return array<int, int>
     */
    private function resolveGeoZoneIdsForAddress(
        ?string $countryCode,
        ?string $regionCode = null,
        ?string $postalCode = null
    ): array {
        $country = strtoupper(trim((string) $countryCode));
        if ($country === '' || strlen($country) !== 2) {
            return [];
        }

        $region = strtoupper(trim((string) $regionCode));
        $postal = strtoupper(trim((string) $postalCode));

        /** @var Collection<int, GeoZoneCountry> $rows */
        $rows = GeoZoneCountry::query()
            ->where('country_code', $country)
            ->get();

        $zoneIds = [];
        foreach ($rows as $row) {
            $ruleRegion = strtoupper(trim((string) ($row->region_code ?? '')));
            if ($ruleRegion !== '' && $ruleRegion !== $region) {
                continue;
            }

            $from = strtoupper(trim((string) ($row->postal_code_from ?? '')));
            $to = strtoupper(trim((string) ($row->postal_code_to ?? '')));

            if (($from !== '' || $to !== '') && $postal === '') {
                continue;
            }

            if ($from !== '' && strcmp($postal, $from) < 0) {
                continue;
            }

            if ($to !== '' && strcmp($postal, $to) > 0) {
                continue;
            }

            $zoneIds[] = (int) $row->geo_zone_id;
        }

        return array_values(array_unique($zoneIds));
    }

    /**
     * @param  array<int, int>  $zoneIds
     */
    private function methodMatchesGeoZones(int|string|null $methodGeoZoneId, array $zoneIds): bool
    {
        if ($methodGeoZoneId === null) {
            return true;
        }

        $zoneId = (int) $methodGeoZoneId;

        return in_array($zoneId, $zoneIds, true);
    }

    private function methodMatchesDestinationScope(
        ShippingMethod $method,
        ?string $destinationScope,
        ?string $countryCode,
    ): bool {
        $settings = is_array($method->settings) ? $method->settings : [];
        $countryCodes = array_values(array_filter(
            is_array($settings['destination_country_codes'] ?? null) ? $settings['destination_country_codes'] : [],
            static fn (mixed $code): bool => is_string($code) && strlen(trim($code)) === 2,
        ));
        $countryCodes = array_map(static fn (string $code): string => strtoupper(trim($code)), $countryCodes);
        if ($countryCodes !== [] && ! in_array(strtoupper(trim((string) $countryCode)), $countryCodes, true)) {
            return false;
        }

        $methodScope = trim((string) ($settings['destination_scope'] ?? ''));
        $methodScopes = array_values(array_filter(
            is_array($settings['destination_scopes'] ?? null) ? $settings['destination_scopes'] : [],
            static fn (mixed $scope): bool => is_string($scope) && in_array($scope, ['hr_mainland', 'hr_islands'], true),
        ));

        if ($methodScopes !== []) {
            return $destinationScope === null
                ? (bool) ($settings['allow_incomplete_destination'] ?? false)
                : in_array($destinationScope, $methodScopes, true);
        }

        if (! in_array($methodScope, ['hr_mainland', 'hr_islands'], true)) {
            return true;
        }

        return $destinationScope !== null && $methodScope === $destinationScope;
    }

    private function paymentMethodMatchesShippingMethod(
        PaymentMethod $paymentMethod,
        ?ShippingMethod $shippingMethod,
    ): bool {
        $paymentCode = strtolower(trim((string) $paymentMethod->code));
        $paymentProvider = strtolower(trim((string) $paymentMethod->provider));
        $isQuotePayment = in_array($paymentCode, ['quote_request', 'manual_quote'], true)
            || in_array($paymentProvider, ['quote_request', 'manual_quote'], true);
        $isQuoteShipping = $shippingMethod !== null
            && strtolower(trim((string) $shippingMethod->pricing_type)) === 'quote';
        $isPickupPayment = $paymentCode === 'pickup' || $paymentProvider === 'pickup';
        $isCashOnDelivery = in_array($paymentCode, ['cod', 'cash_on_delivery', 'cash-on-delivery'], true)
            || in_array($paymentProvider, ['cod', 'cash_on_delivery', 'cash-on-delivery'], true);
        $isPickupShipping = $shippingMethod !== null
            && strtolower(trim((string) $shippingMethod->service_type)) === 'pickup';

        if ($isQuoteShipping) {
            return $isQuotePayment;
        }

        if ($isQuotePayment) {
            return false;
        }

        if ($isPickupPayment) {
            return $isPickupShipping;
        }

        if ($isCashOnDelivery) {
            return $shippingMethod !== null && ! $isPickupShipping;
        }

        return true;
    }

    private function shippingMethodIsConfigured(ShippingMethod $method): bool
    {
        if (! $this->isBoxNowCode((string) $method->code)) {
            return true;
        }

        $settings = is_array($method->settings) ? $method->settings : [];

        return trim((string) ($settings['boxnow_partner_id'] ?? '')) !== ''
            && (string) $method->service_type === 'parcel_locker'
            && (string) $method->pricing_type === 'flat'
            && (string) $method->missing_measurements_policy === 'block'
            && (float) $method->max_weight_kg > 0
            && (float) $method->max_length_cm > 0
            && (float) $method->max_width_cm > 0
            && (float) $method->max_height_cm > 0
            && ! (bool) $method->allows_fragile
            && ! (bool) $method->allows_oversized
            && ! (bool) $method->allows_heavy;
    }

    private function nextOrderNumber(): string
    {
        $datePrefix = now()->format('Ymd');

        for ($attempt = 0; $attempt < 15; $attempt++) {
            $candidate = sprintf('AG-%s-%04d', $datePrefix, random_int(1, 9999));
            $exists = Order::query()->where('order_number', $candidate)->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        return sprintf('AG-%s-%s', $datePrefix, strtoupper((string) str()->random(6)));
    }

    private function isBoxNowCode(string $code): bool
    {
        return in_array(strtolower(trim($code)), ['boxnow', 'box_now'], true);
    }

    private function resolveOrderStatusForPayment(PaymentMethod $paymentMethod): ?OrderStatus
    {
        $settings = is_array($paymentMethod->settings) ? $paymentMethod->settings : [];
        $customStatusId = (int) ($settings['default_order_status_id'] ?? 0);

        if ($customStatusId > 0) {
            $customStatus = OrderStatus::query()
                ->where('id', $customStatusId)
                ->where('is_active', true)
                ->first();

            if ($customStatus) {
                return $customStatus;
            }
        }

        return OrderStatus::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }
}
