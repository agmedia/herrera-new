<?php

namespace App\Services\Payments;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderHistory;
use App\Models\Sales\Order\OrderTransaction;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use Illuminate\Support\Facades\DB;

class CorvusPayFormService
{
    public const PAYLOAD_KEY = 'corvuspay';

    public const MODE_TEST = 'test';

    public const MODE_LIVE = 'live';

    public const FORM_URL_TEST = 'https://wallet.test.corvuspay.com/checkout/';

    public const FORM_URL_LIVE = 'https://wallet.corvuspay.com/checkout/';

    public const API_VERSION = '1.6';

    public function isCorvusCode(string $code): bool
    {
        return in_array(strtolower(trim($code)), ['corvus', 'corvuspay', 'corvus_pay'], true);
    }

    public function isCorvusOrder(Order $order): bool
    {
        return $this->isCorvusCode((string) $order->payment_method_code);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function hasRequiredSettings(array $settings): bool
    {
        return trim((string) ($settings['corvus_store_id'] ?? '')) !== ''
            && trim((string) ($settings['corvus_secret_key'] ?? '')) !== '';
    }

    public function canBeOffered(PaymentMethod $method): bool
    {
        if (! $this->isCorvusCode((string) $method->code)) {
            return true;
        }

        $settings = is_array($method->settings) ? $method->settings : [];

        return $this->hasRequiredSettings($settings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildFormData(Order $order): ?array
    {
        if (! $this->isCorvusOrder($order)) {
            return null;
        }

        $settings = $this->resolveMethodSettings((string) $order->payment_method_code);
        $mode = strtolower(trim((string) ($settings['corvus_mode'] ?? self::MODE_TEST)));
        $formUrl = $this->resolveFormUrl($mode);
        $storeId = trim((string) ($settings['corvus_store_id'] ?? ''));
        $secret = trim((string) ($settings['corvus_secret_key'] ?? ''));

        if (! $this->hasRequiredSettings($settings)) {
            return null;
        }

        $language = strtolower(trim((string) ($settings['corvus_language'] ?? app()->getLocale() ?? 'hr')));
        if (! in_array($language, ['hr', 'en', 'it', 'de', 'rs', 'sl', 'mk', 'sq'], true)) {
            $language = 'hr';
        }

        $currency = strtoupper(trim((string) ($settings['corvus_currency'] ?? $order->currency_code ?? 'EUR')));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $requireComplete = filter_var((string) ($settings['corvus_require_complete'] ?? 'false'), FILTER_VALIDATE_BOOL)
            ? 'true'
            : 'false';

        $payload = [
            'amount' => number_format(max(0, (float) $order->grand_total), 2, '.', ''),
            'cardholder_address' => $this->limit((string) ($order->billing_address_line_1 ?: $order->shipping_address_line_1 ?: ''), 100),
            'cardholder_city' => $this->limit((string) ($order->billing_city ?: $order->shipping_city ?: ''), 20),
            'cardholder_country' => $this->limit((string) ($order->billing_country_code ?: $order->shipping_country_code ?: 'HR'), 30),
            'cardholder_country_code' => strtoupper($this->limit((string) ($order->billing_country_code ?: $order->shipping_country_code ?: 'HR'), 2)),
            'cardholder_email' => $this->limit((string) ($order->customer_email ?: ''), 100),
            'cardholder_name' => $this->limit((string) ($order->billing_first_name ?: $order->shipping_first_name ?: ''), 40),
            'cardholder_surname' => $this->limit((string) ($order->billing_last_name ?: $order->shipping_last_name ?: ''), 40),
            'cardholder_zip_code' => $this->limit((string) ($order->billing_postal_code ?: $order->shipping_postal_code ?: ''), 9),
            'cancel_url' => route('checkout.corvus.cancel.static'),
            'cart' => $this->limit($this->buildCartDescription($order), 255),
            'currency' => $currency,
            'language' => $language,
            'order_number' => (string) $order->order_number,
            'require_complete' => $requireComplete,
            'store_id' => $storeId,
            'success_url' => route('checkout.corvus.success.static'),
            'version' => self::API_VERSION,
        ];

        $payload['signature'] = $this->signatureForPost($payload, $secret);

        $metaPayload = is_array($order->payload) ? $order->payload : [];
        $existing = is_array($metaPayload[self::PAYLOAD_KEY] ?? null) ? $metaPayload[self::PAYLOAD_KEY] : [];
        $metaPayload[self::PAYLOAD_KEY] = array_merge($existing, [
            'request' => [
                'mode' => $mode,
                'form_url' => $formUrl,
                'store_id' => $storeId,
                'order_number' => (string) $order->order_number,
                'amount' => $payload['amount'],
                'currency' => $currency,
                'language' => $language,
                'require_complete' => $requireComplete,
                'prepared_at' => now()->toIso8601String(),
            ],
        ]);
        $order->forceFill(['payload' => $metaPayload])->save();

        return [
            'action_url' => $formUrl,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{paid:bool,newly_paid:bool,status:string,signature_valid:bool,callback_authorized:bool,cancellation_applied:bool,message:string}
     */
    public function handleCallback(Order $order, array $input, string $context): array
    {
        if (! $this->isCorvusOrder($order)) {
            return [
                'paid' => false,
                'newly_paid' => false,
                'status' => 'ignored',
                'signature_valid' => false,
                'callback_authorized' => false,
                'cancellation_applied' => false,
                'message' => 'Order is not CorvusPay.',
            ];
        }

        $settings = $this->resolveMethodSettings((string) $order->payment_method_code);
        $secret = trim((string) ($settings['corvus_secret_key'] ?? ''));
        $approvalCode = trim((string) ($input['approval_code'] ?? $input['approvalCode'] ?? ''));
        $language = trim((string) ($input['language'] ?? ''));
        $orderNumber = trim((string) ($input['order_number'] ?? ''));
        $signature = trim((string) ($input['signature'] ?? ''));

        $signatureValid = false;
        if ($secret !== '' && $signature !== '' && $orderNumber !== '') {
            $signedFields = [
                'approval_code' => $approvalCode,
                'language' => $language,
                'order_number' => $orderNumber,
            ];
            $expected = $this->signatureForPost($signedFields, $secret);
            $signatureValid = hash_equals(strtolower($expected), strtolower($signature));
        }

        $normalizedContext = strtolower($context);
        $callbackAuthorized = $signatureValid
            && $orderNumber !== ''
            && hash_equals((string) $order->order_number, $orderNumber);

        if (! $callbackAuthorized) {
            return [
                'paid' => false,
                'newly_paid' => false,
                'status' => 'invalid_signature',
                'signature_valid' => $signatureValid,
                'callback_authorized' => false,
                'cancellation_applied' => false,
                'message' => 'Payment callback signature is invalid.',
            ];
        }

        $result = DB::transaction(function () use (
            $order,
            $normalizedContext,
            $signatureValid,
            $input,
            $approvalCode,
            $context,
        ): array {
            /** @var Order $locked */
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();
            $payload = is_array($locked->payload) ? $locked->payload : [];
            $existing = is_array($payload[self::PAYLOAD_KEY] ?? null) ? $payload[self::PAYLOAD_KEY] : [];
            $wasRestocked = ! empty($existing['cancel_restocked_at']);
            $isPaid = $normalizedContext === 'success'
                && $approvalCode !== ''
                && ! $wasRestocked;
            $newlyPaid = $isPaid && $locked->paid_at === null;
            $status = match (true) {
                $normalizedContext === 'success' && $wasRestocked => 'late_success_after_cancel',
                $isPaid => 'approved',
                $normalizedContext === 'cancel' && $locked->paid_at !== null => 'already_paid',
                $normalizedContext === 'cancel' => 'cancelled',
                default => 'declined',
            };

            $transactionRef = $approvalCode !== '' ? $approvalCode : (string) ($input['order_number'] ?? '');
            OrderTransaction::query()->firstOrCreate([
                'order_id' => $locked->id,
                'provider' => 'corvuspay',
                'transaction_ref' => $transactionRef,
                'status' => $status,
            ], [
                'amount' => (float) $locked->grand_total,
                'currency_code' => (string) ($locked->currency_code ?: 'EUR'),
                'processed_at' => now(),
                'payload' => [
                    'context' => $context,
                    'signature_valid' => $signatureValid,
                    'callback_authorized' => true,
                    'raw' => $input,
                ],
                'created_by' => $locked->user_id,
            ]);

            $callbacks = is_array($existing['callbacks'] ?? null) ? $existing['callbacks'] : [];
            $callbacks[] = [
                'at' => now()->toIso8601String(),
                'context' => $context,
                'status' => $status,
                'signature_valid' => $signatureValid,
                'callback_authorized' => true,
                'approval_code' => $approvalCode,
            ];
            $callbacks = array_slice($callbacks, -50);

            $payload[self::PAYLOAD_KEY] = array_merge($existing, [
                'status' => in_array($status, ['late_success_after_cancel', 'already_paid'], true)
                    ? (string) ($existing['status'] ?? $status)
                    : $status,
                'latest_callback_status' => $status,
                'signature_valid' => $signatureValid,
                'callback_authorized' => true,
                'approval_code' => $approvalCode,
                'callbacks' => $callbacks,
            ]);

            $beforeStatusId = (int) $locked->status_id;
            if ($newlyPaid) {
                $paidStatus = $this->resolvePaidStatus();
                if ($paidStatus && (int) $locked->status_id !== (int) $paidStatus->id) {
                    $locked->status_id = (int) $paidStatus->id;
                    OrderHistory::query()->create([
                        'order_id' => $locked->id,
                        'from_status_id' => $beforeStatusId > 0 ? $beforeStatusId : null,
                        'to_status_id' => (int) $paidStatus->id,
                        'changed_by' => $locked->user_id,
                        'comment' => 'CorvusPay callback: payment approved.',
                    ]);
                }
                if (! $locked->paid_at) {
                    $locked->paid_at = now();
                }
            }

            $locked->payload = $payload;
            $cancellationApplied = $status === 'cancelled'
                ? $this->applyCancellationEffectsToLockedOrder($locked)
                : false;
            $locked->save();

            return [
                'paid' => $isPaid,
                'newly_paid' => $newlyPaid,
                'status' => $status,
                'cancellation_applied' => $cancellationApplied,
            ];
        });

        return [
            'paid' => (bool) $result['paid'],
            'newly_paid' => (bool) $result['newly_paid'],
            'status' => (string) $result['status'],
            'signature_valid' => $signatureValid,
            'callback_authorized' => true,
            'cancellation_applied' => (bool) $result['cancellation_applied'],
            'message' => (bool) $result['paid'] ? 'Payment approved.' : 'Payment was not approved.',
        ];
    }

    public function handleCancellationEffects(Order $order): void
    {
        if (! $this->isCorvusOrder($order)) {
            return;
        }

        DB::transaction(function () use ($order): void {
            /** @var Order $locked */
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->with(['items'])
                ->firstOrFail();

            if ($this->applyCancellationEffectsToLockedOrder($locked)) {
                $locked->save();
            }
        });
    }

    private function applyCancellationEffectsToLockedOrder(Order $locked): bool
    {
        $payload = is_array($locked->payload) ? $locked->payload : [];
        $existing = is_array($payload[self::PAYLOAD_KEY] ?? null) ? $payload[self::PAYLOAD_KEY] : [];
        if (
            $locked->paid_at !== null
            || ($existing['status'] ?? null) !== 'cancelled'
            || ! ($existing['callback_authorized'] ?? false)
            || ! empty($existing['cancel_restocked_at'])
        ) {
            return false;
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
                'comment' => 'CorvusPay callback: payment cancelled, stock restored.',
            ]);
        }

        $payload[self::PAYLOAD_KEY] = array_merge($existing, [
            'cancel_restocked_at' => now()->toIso8601String(),
        ]);
        $locked->paid_at = null;
        $locked->payload = $payload;

        return true;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function signatureForPost(array $fields, string $secret): string
    {
        unset($fields['signature']);
        ksort($fields, SORT_STRING);

        $message = '';
        foreach ($fields as $key => $value) {
            $message .= (string) $key.(string) $value;
        }

        return hash_hmac('sha256', $message, $secret);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveMethodSettings(string $methodCode): array
    {
        $method = PaymentMethod::query()->where('code', $methodCode)->first();
        $settings = is_array($method?->settings) ? $method->settings : [];

        if (! $this->isCorvusCode($methodCode) || $this->hasRequiredSettings($settings)) {
            return $settings;
        }

        $methods = PaymentMethod::query()
            ->whereIn('code', ['corvus', 'corvuspay', 'corvus_pay'])
            ->orderByRaw("CASE WHEN code = 'corvus' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        foreach ($methods as $candidate) {
            $candidateSettings = is_array($candidate->settings) ? $candidate->settings : [];

            if ($this->hasRequiredSettings($candidateSettings)) {
                return $candidateSettings;
            }
        }

        return $settings;
    }

    private function resolveFormUrl(string $mode): string
    {
        return $mode === self::MODE_LIVE ? self::FORM_URL_LIVE : self::FORM_URL_TEST;
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

    private function buildCartDescription(Order $order): string
    {
        $order->loadMissing('items');

        return $order->items
            ->map(static fn ($item): string => trim((string) $item->name).' x'.(int) $item->quantity)
            ->implode(', ');
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
