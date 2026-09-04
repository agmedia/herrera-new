<?php

namespace App\Services\Payments;

use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderHistory;
use App\Models\Sales\Order\OrderTransaction;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use Illuminate\Support\Facades\DB;

class WSPayFormService
{
    public const PAYLOAD_KEY = 'wspay';
    public const MODE_TEST = 'test';
    public const MODE_LIVE = 'live';
    public const FORM_URL_TEST = 'https://formtest.wspay.biz/authorization.aspx';
    public const FORM_URL_LIVE = 'https://form.wspay.biz/authorization.aspx';

    public function isWspayCode(string $code): bool
    {
        return in_array(strtolower(trim($code)), ['wspay', 'ws_pay'], true);
    }

    public function isWspayOrder(Order $order): bool
    {
        return $this->isWspayCode((string) $order->payment_method_code);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildFormData(Order $order): ?array
    {
        if (! $this->isWspayOrder($order)) {
            return null;
        }

        $settings = $this->resolveMethodSettings((string) $order->payment_method_code);
        $mode = strtolower(trim((string) ($settings['wspay_mode'] ?? self::MODE_TEST)));
        $formUrl = $this->resolveFormUrl($mode, $settings);
        $shopId = trim((string) ($settings['wspay_shop_id'] ?? ''));
        $secret = trim((string) ($settings['wspay_secret_key'] ?? ''));

        if ($formUrl === '' || $shopId === '' || $secret === '') {
            return null;
        }

        $returnMethod = strtoupper(trim((string) ($settings['wspay_return_method'] ?? 'GET')));
        if (! in_array($returnMethod, ['GET', 'POST'], true)) {
            $returnMethod = 'GET';
        }

        $shoppingCartId = (string) $order->order_number;
        $totalAmount = $this->formatAmountForRequest((float) $order->grand_total);
        $signature = $this->buildRequestSignature($shopId, $secret, $shoppingCartId, $totalAmount);
        $customer = [
            'first_name' => $this->limit((string) ($order->billing_first_name ?: $order->shipping_first_name ?: ''), 50),
            'last_name' => $this->limit((string) ($order->billing_last_name ?: $order->shipping_last_name ?: ''), 50),
            'address' => $this->limit((string) ($order->billing_address_line_1 ?: $order->shipping_address_line_1 ?: ''), 100),
            'city' => $this->limit((string) ($order->billing_city ?: $order->shipping_city ?: ''), 50),
            'zip' => $this->limit((string) ($order->billing_postal_code ?: $order->shipping_postal_code ?: ''), 20),
            'country' => strtoupper($this->limit((string) ($order->billing_country_code ?: $order->shipping_country_code ?: 'HR'), 2)),
            'phone' => $this->limit((string) ($order->customer_phone ?: ''), 20),
            'email' => $this->limit((string) ($order->customer_email ?: ''), 254),
        ];

        $data = [
            'action_url' => $formUrl,
            'shop_id' => $shopId,
            'shopping_cart_id' => $shoppingCartId,
            'version' => '2.0',
            'total_amount' => $totalAmount,
            'return_url' => route('checkout.wspay.return', ['orderNumber' => $order->order_number]),
            'return_error_url' => route('checkout.wspay.error', ['orderNumber' => $order->order_number]),
            'cancel_url' => route('checkout.wspay.cancel', ['orderNumber' => $order->order_number]),
            'return_method' => $returnMethod,
            'signature' => $signature,
            'customer' => $customer,
        ];

        $payload = is_array($order->payload) ? $order->payload : [];
        $existing = is_array($payload[self::PAYLOAD_KEY] ?? null) ? $payload[self::PAYLOAD_KEY] : [];
        $payload[self::PAYLOAD_KEY] = array_merge($existing, [
            'request' => [
                'shop_id' => $shopId,
                'shopping_cart_id' => $shoppingCartId,
                'version' => '2.0',
                'total_amount' => $totalAmount,
                'mode' => $mode,
                'form_url' => $formUrl,
                'return_method' => $returnMethod,
                'customer' => $customer,
                'prepared_at' => now()->toIso8601String(),
            ],
        ]);
        $order->forceFill(['payload' => $payload])->save();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{paid:bool,status:string,signature_valid:bool,message:string}
     */
    public function handleCallback(Order $order, array $input, string $context): array
    {
        if (! $this->isWspayOrder($order)) {
            return [
                'paid' => false,
                'status' => 'ignored',
                'signature_valid' => false,
                'message' => 'Order is not WSPay.',
            ];
        }

        $settings = $this->resolveMethodSettings((string) $order->payment_method_code);
        $shopId = trim((string) ($settings['wspay_shop_id'] ?? ''));
        $secret = trim((string) ($settings['wspay_secret_key'] ?? ''));

        $shoppingCartId = trim((string) ($input['ShoppingCartID'] ?? $input['shoppingcartid'] ?? ''));
        $approvalCode = trim((string) ($input['ApprovalCode'] ?? $input['approvalcode'] ?? ''));
        $success = trim((string) ($input['Success'] ?? $input['success'] ?? '0'));
        $signature = trim((string) ($input['Signature'] ?? $input['signature'] ?? ''));
        $wsPayOrderId = trim((string) ($input['WsPayOrderId'] ?? $input['WsPayOrderID'] ?? $input['wspayorderid'] ?? ''));
        $errorMessage = trim((string) ($input['ErrorMessage'] ?? $input['errormessage'] ?? ''));

        $signatureValid = false;
        if ($shopId !== '' && $secret !== '' && $shoppingCartId !== '' && $signature !== '') {
            $expected = $this->buildResponseSignature($shopId, $secret, $shoppingCartId, $success, $approvalCode);
            $signatureValid = hash_equals(strtolower($expected), strtolower($signature));
        }

        $isPaid = $signatureValid
            && $shoppingCartId === (string) $order->order_number
            && $success === '1'
            && $approvalCode !== '';

        $status = $isPaid ? 'approved' : match (strtolower($context)) {
            'cancel' => 'cancelled',
            'error' => 'error',
            default => 'declined',
        };
        if (! $signatureValid) {
            $status = 'invalid_signature';
        }

        $rawPayload = $input;
        if (array_key_exists('SecretKey', $rawPayload)) {
            unset($rawPayload['SecretKey']);
        }

        DB::transaction(function () use (
            $order,
            $isPaid,
            $status,
            $signatureValid,
            $wsPayOrderId,
            $approvalCode,
            $errorMessage,
            $context,
            $rawPayload
        ): void {
            OrderTransaction::query()->create([
                'order_id' => $order->id,
                'provider' => 'wspay',
                'transaction_ref' => $wsPayOrderId !== '' ? $wsPayOrderId : $approvalCode,
                'status' => $status,
                'amount' => (float) $order->grand_total,
                'currency_code' => (string) ($order->currency_code ?: 'EUR'),
                'processed_at' => now(),
                'payload' => [
                    'context' => $context,
                    'signature_valid' => $signatureValid,
                    'raw' => $rawPayload,
                ],
                'created_by' => $order->user_id,
            ]);

            $payload = is_array($order->payload) ? $order->payload : [];
            $existing = is_array($payload[self::PAYLOAD_KEY] ?? null) ? $payload[self::PAYLOAD_KEY] : [];
            $history = is_array($existing['callbacks'] ?? null) ? $existing['callbacks'] : [];
            $history[] = [
                'at' => now()->toIso8601String(),
                'context' => $context,
                'status' => $status,
                'signature_valid' => $signatureValid,
                'approval_code' => $approvalCode,
                'wspay_order_id' => $wsPayOrderId,
                'error_message' => $errorMessage,
            ];

            $payload[self::PAYLOAD_KEY] = array_merge($existing, [
                'status' => $status,
                'approval_code' => $approvalCode,
                'wspay_order_id' => $wsPayOrderId,
                'error_message' => $errorMessage,
                'signature_valid' => $signatureValid,
                'callbacks' => $history,
            ]);

            $beforeStatusId = (int) $order->status_id;
            if ($isPaid) {
                $paidStatus = $this->resolvePaidStatus();
                if ($paidStatus && (int) $order->status_id !== (int) $paidStatus->id) {
                    $order->status_id = (int) $paidStatus->id;
                    OrderHistory::query()->create([
                        'order_id' => $order->id,
                        'from_status_id' => $beforeStatusId > 0 ? $beforeStatusId : null,
                        'to_status_id' => (int) $paidStatus->id,
                        'changed_by' => $order->user_id,
                        'comment' => 'WSPay callback: payment approved.',
                    ]);
                }

                if (! $order->paid_at) {
                    $order->paid_at = now();
                }
            }

            $order->payload = $payload;
            $order->save();
        });

        return [
            'paid' => $isPaid,
            'status' => $status,
            'signature_valid' => $signatureValid,
            'message' => $isPaid
                ? 'Payment approved.'
                : ($errorMessage !== '' ? $errorMessage : 'Payment was not approved.'),
        ];
    }

    public function handleCancellationEffects(Order $order): void
    {
        if (! $this->isWspayOrder($order)) {
            return;
        }

        DB::transaction(function () use ($order): void {
            /** @var Order $locked */
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->with(['items'])
                ->firstOrFail();

            $payload = is_array($locked->payload) ? $locked->payload : [];
            $existing = is_array($payload[self::PAYLOAD_KEY] ?? null) ? $payload[self::PAYLOAD_KEY] : [];
            if (! empty($existing['cancel_restocked_at'])) {
                return;
            }

            foreach ($locked->items as $item) {
                $qty = max(0, (int) $item->quantity);
                if ($qty <= 0) {
                    continue;
                }

                $optionValueId = (int) ($item->product_option_value_id ?? 0);
                if ($optionValueId > 0) {
                    $optionRow = ProductOptionValue::query()->lockForUpdate()->find($optionValueId);
                    if ($optionRow) {
                        $optionRow->stock_qty = max(0, (int) $optionRow->stock_qty) + $qty;
                        $optionRow->save();
                    }
                    continue;
                }

                $productId = (int) ($item->product_id ?? 0);
                if ($productId > 0) {
                    $product = Product::query()->lockForUpdate()->find($productId);
                    if ($product) {
                        $product->stock_qty = max(0, (int) $product->stock_qty) + $qty;
                        $product->save();
                    }
                }
            }

            $beforeStatusId = (int) $locked->status_id;
            $cancelledStatus = $this->resolveCancelledStatus();
            if ($cancelledStatus && (int) $locked->status_id !== (int) $cancelledStatus->id) {
                $locked->status_id = (int) $cancelledStatus->id;
                OrderHistory::query()->create([
                    'order_id' => $locked->id,
                    'from_status_id' => $beforeStatusId > 0 ? $beforeStatusId : null,
                    'to_status_id' => (int) $cancelledStatus->id,
                    'changed_by' => $locked->user_id,
                    'comment' => 'WSPay callback: payment cancelled, stock restored.',
                ]);
            }

            $payload[self::PAYLOAD_KEY] = array_merge($existing, [
                'cancel_restocked_at' => now()->toIso8601String(),
            ]);

            $locked->paid_at = null;
            $locked->payload = $payload;
            $locked->save();
        });
    }

    private function formatAmountForRequest(float $amount): string
    {
        return number_format(max(0, $amount), 2, ',', '');
    }

    private function formatAmountForHash(string $amount): string
    {
        return str_replace([',', '.'], '', trim($amount));
    }

    private function buildRequestSignature(string $shopId, string $secret, string $shoppingCartId, string $totalAmount): string
    {
        $raw = $shopId.$secret.$shoppingCartId.$secret.$this->formatAmountForHash($totalAmount).$secret;

        return hash('sha512', $raw);
    }

    private function buildResponseSignature(string $shopId, string $secret, string $shoppingCartId, string $success, string $approvalCode): string
    {
        $raw = $shopId.$secret.$shoppingCartId.$secret.$success.$secret.$approvalCode.$secret;

        return hash('sha512', $raw);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveMethodSettings(string $methodCode): array
    {
        $method = PaymentMethod::query()->where('code', $methodCode)->first();

        return is_array($method?->settings) ? $method->settings : [];
    }

    private function resolvePaidStatus(): ?OrderStatus
    {
        return OrderStatus::query()
            ->where('is_active', true)
            ->where('is_paid', true)
            ->where('is_cancelled', false)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    private function resolveCancelledStatus(): ?OrderStatus
    {
        return OrderStatus::query()
            ->where('is_active', true)
            ->where('is_cancelled', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolveFormUrl(string $mode, array $settings): string
    {
        if ($mode === self::MODE_LIVE) {
            return self::FORM_URL_LIVE;
        }

        if ($mode === self::MODE_TEST) {
            return self::FORM_URL_TEST;
        }

        $legacyUrl = trim((string) ($settings['wspay_form_url'] ?? ''));

        return $legacyUrl !== '' ? $legacyUrl : self::FORM_URL_TEST;
    }

    private function limit(string $value, int $max): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        return mb_substr($trimmed, 0, $max);
    }
}
