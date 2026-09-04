<?php

namespace App\Services\Payments;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderHistory;
use App\Models\Sales\Order\OrderTransaction;
use App\Models\Settings\Local\OrderStatus;
use App\Models\Settings\Local\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KeksPayService
{
    public const PAYLOAD_KEY = 'kekspay';
    private const SELL_URL_TEST = 'https://kekspayuat.erstebank.hr/galebpay';
    private const SELL_URL_LIVE = 'https://kekspay.hr/galebpay';

    public function isKeksCode(string $code): bool
    {
        return in_array(strtolower(trim($code)), ['keks', 'keks_pay', 'kekspay'], true);
    }

    public function isKeksOrder(Order $order): bool
    {
        return $this->isKeksCode((string) $order->payment_method_code);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildSellData(Order $order): ?array
    {
        if (! $this->isKeksOrder($order)) {
            return null;
        }

        $settings = $this->resolveMethodSettings((string) $order->payment_method_code);
        $mode = strtolower(trim((string) ($settings['keks_mode'] ?? 'test')));
        $cid = trim((string) ($settings['keks_cid'] ?? ''));
        $tid = trim((string) ($settings['keks_tid'] ?? ''));
        $desKey = trim((string) ($settings['keks_des_key'] ?? ''));
        $qrType = (int) ($settings['keks_qr_type'] ?? 1);

        if ($cid === '' || $tid === '' || $desKey === '') {
            return null;
        }

        $epochtime = (string) now()->timestamp;
        $amount = number_format(max(0, (float) $order->grand_total), 2, '.', '');
        $billId = (string) $order->order_number;
        $successUrl = route('checkout.keks.success');
        $failUrl = route('checkout.keks.fail');

        $payload = [
            'qr_type' => $qrType,
            'cid' => $cid,
            'tid' => $tid,
            'bill_id' => $billId,
            'amount' => (float) $amount,
            'success_url' => $successUrl,
            'fail_url' => $failUrl,
            'epochtime' => (int) $epochtime,
        ];
        $payload['hash'] = $this->calculateHash($tid, $epochtime, $amount, $billId, $desKey);
        if ($payload['hash'] === '') {
            return null;
        }

        $sellBaseUrl = trim((string) ($settings['keks_sell_base_url'] ?? ''));
        if ($sellBaseUrl === '') {
            $sellBaseUrl = $mode === 'live' ? self::SELL_URL_LIVE : self::SELL_URL_TEST;
        }

        $query = http_build_query([
            'cid' => $cid,
            'tid' => $tid,
            'bill_id' => $billId,
            'amount' => $amount,
            'success_url' => $successUrl,
            'fail_url' => $failUrl,
            'epochtime' => $epochtime,
            'hash' => $payload['hash'],
            'qr_type' => $qrType,
        ]);
        $deeplink = rtrim($sellBaseUrl, '?').'?'.$query;
        $qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=360x360&data='.rawurlencode($deeplink);

        $metaPayload = is_array($order->payload) ? $order->payload : [];
        $existing = is_array($metaPayload[self::PAYLOAD_KEY] ?? null) ? $metaPayload[self::PAYLOAD_KEY] : [];
        $metaPayload[self::PAYLOAD_KEY] = array_merge($existing, [
            'request' => [
                'cid' => $cid,
                'tid' => $tid,
                'bill_id' => $billId,
                'amount' => $amount,
                'success_url' => $successUrl,
                'fail_url' => $failUrl,
                'epochtime' => (int) $epochtime,
                'hash' => $payload['hash'],
                'prepared_at' => now()->toIso8601String(),
            ],
        ]);
        $order->forceFill(['payload' => $metaPayload])->save();

        return [
            'payload' => $payload,
            'deeplink' => $deeplink,
            'qr_image_url' => $qrImageUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status:int,message:string,order:Order|null}
     */
    public function handleAdvice(array $input, Request $request): array
    {
        $billId = trim((string) ($input['bill_id'] ?? ''));
        $status = (int) ($input['status'] ?? -1);
        $message = trim((string) ($input['message'] ?? ''));

        if ($billId === '') {
            return ['status' => -1, 'message' => 'Missing bill_id.', 'order' => null];
        }

        $order = Order::query()
            ->where('order_number', $billId)
            ->with('items')
            ->first();
        if (! $order || ! $this->isKeksOrder($order)) {
            return ['status' => -2, 'message' => 'Order not found.', 'order' => null];
        }

        if (! $this->isAdviceAuthorized($order, $request)) {
            return ['status' => -3, 'message' => 'Unauthorized.', 'order' => null];
        }

        if ($status === 0) {
            $this->applyApprovedAdvice($order, $input);
            return ['status' => 0, 'message' => 'Accepted', 'order' => $order];
        }

        $this->applyDeclinedAdvice($order, $input, $message !== '' ? $message : 'Declined');

        return ['status' => 0, 'message' => 'Accepted', 'order' => $order];
    }

    public function handleFailureEffects(Order $order): void
    {
        if (! $this->isKeksOrder($order)) {
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
                    'comment' => 'KEKS Pay: transaction not completed, stock restored.',
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

    private function applyApprovedAdvice(Order $order, array $input): void
    {
        DB::transaction(function () use ($order, $input): void {
            $fresh = Order::query()->lockForUpdate()->findOrFail($order->id);
            $payload = is_array($fresh->payload) ? $fresh->payload : [];
            $existing = is_array($payload[self::PAYLOAD_KEY] ?? null) ? $payload[self::PAYLOAD_KEY] : [];

            OrderTransaction::query()->create([
                'order_id' => $fresh->id,
                'provider' => 'kekspay',
                'transaction_ref' => (string) ($input['keks_id'] ?? ''),
                'status' => 'approved',
                'amount' => (float) ($input['amount'] ?? $fresh->grand_total),
                'currency_code' => (string) ($fresh->currency_code ?: 'EUR'),
                'processed_at' => now(),
                'payload' => ['raw' => $input, 'source' => 'advice'],
                'created_by' => $fresh->user_id,
            ]);

            $beforeStatusId = (int) $fresh->status_id;
            $paidStatus = $this->resolvePaidStatus();
            if ($paidStatus && (int) $fresh->status_id !== (int) $paidStatus->id) {
                $fresh->status_id = (int) $paidStatus->id;
                OrderHistory::query()->create([
                    'order_id' => $fresh->id,
                    'from_status_id' => $beforeStatusId > 0 ? $beforeStatusId : null,
                    'to_status_id' => (int) $paidStatus->id,
                    'changed_by' => $fresh->user_id,
                    'comment' => 'KEKS Pay advice: payment approved.',
                ]);
            }

            if (! $fresh->paid_at) {
                $fresh->paid_at = now();
            }

            $payload[self::PAYLOAD_KEY] = array_merge($existing, [
                'status' => 'approved',
                'keks_id' => (string) ($input['keks_id'] ?? ''),
                'advice' => $input,
                'approved_at' => now()->toIso8601String(),
            ]);

            $fresh->payload = $payload;
            $fresh->save();
        });
    }

    private function applyDeclinedAdvice(Order $order, array $input, string $message): void
    {
        DB::transaction(function () use ($order, $input, $message): void {
            $fresh = Order::query()->lockForUpdate()->findOrFail($order->id);
            $payload = is_array($fresh->payload) ? $fresh->payload : [];
            $existing = is_array($payload[self::PAYLOAD_KEY] ?? null) ? $payload[self::PAYLOAD_KEY] : [];

            OrderTransaction::query()->create([
                'order_id' => $fresh->id,
                'provider' => 'kekspay',
                'transaction_ref' => (string) ($input['keks_id'] ?? ''),
                'status' => 'declined',
                'amount' => (float) ($input['amount'] ?? $fresh->grand_total),
                'currency_code' => (string) ($fresh->currency_code ?: 'EUR'),
                'processed_at' => now(),
                'payload' => ['raw' => $input, 'source' => 'advice', 'message' => $message],
                'created_by' => $fresh->user_id,
            ]);

            $payload[self::PAYLOAD_KEY] = array_merge($existing, [
                'status' => 'declined',
                'keks_id' => (string) ($input['keks_id'] ?? ''),
                'advice' => $input,
                'declined_at' => now()->toIso8601String(),
            ]);
            $fresh->payload = $payload;
            $fresh->save();
        });
    }

    private function isAdviceAuthorized(Order $order, Request $request): bool
    {
        $settings = $this->resolveMethodSettings((string) $order->payment_method_code);
        $mode = strtolower(trim((string) ($settings['keks_advice_auth_mode'] ?? 'none')));

        if ($mode === 'none' || $mode === '') {
            return true;
        }

        $header = trim((string) $request->header('Authorization', ''));
        if ($mode === 'token') {
            $token = trim((string) ($settings['keks_advice_token'] ?? ''));
            return $token !== '' && hash_equals('Token '.$token, $header);
        }

        if ($mode === 'basic') {
            $username = trim((string) ($settings['keks_advice_username'] ?? ''));
            $password = trim((string) ($settings['keks_advice_password'] ?? ''));
            if ($username === '' || $password === '') {
                return false;
            }
            $expected = 'Basic '.base64_encode($username.':'.$password);
            return hash_equals($expected, $header);
        }

        if ($mode === 'url_token') {
            $token = trim((string) ($settings['keks_advice_token'] ?? ''));
            $incoming = trim((string) $request->query('token', ''));
            return $token !== '' && hash_equals($token, $incoming);
        }

        return false;
    }

    private function calculateHash(string $tid, string $epochtime, string $amount, string $billId, string $desKey): string
    {
        $hashInput = $epochtime.$tid.$amount.$billId;
        $md5Hex = strtoupper(md5($hashInput));
        $md5Bytes = hex2bin($md5Hex);
        if ($md5Bytes === false) {
            return '';
        }

        $cipherRaw = openssl_encrypt(
            $md5Bytes,
            'des-ede3-cbc',
            $desKey,
            OPENSSL_RAW_DATA,
            hex2bin('0000000000000000')
        );
        if ($cipherRaw === false) {
            return '';
        }

        return strtoupper(bin2hex((string) $cipherRaw));
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
}
