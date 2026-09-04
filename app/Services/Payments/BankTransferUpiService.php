<?php

namespace App\Services\Payments;

use App\Models\Sales\Order\Order;
use App\Models\Settings\Local\PaymentMethod;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BankTransferUpiService
{
    public const PAYLOAD_KEY = 'bank_transfer';

    /**
     * @return array<string, mixed>|null
     */
    public function ensureForOrder(Order $order): ?array
    {
        if (! $this->isBankTransferCode((string) $order->payment_method_code)) {
            return null;
        }

        $payload = is_array($order->payload) ? $order->payload : [];
        $current = is_array($payload[self::PAYLOAD_KEY] ?? null) ? $payload[self::PAYLOAD_KEY] : [];

        $settings = $this->resolveMethodSettings((string) $order->payment_method_code);
        $next = $this->buildSnapshot($order, $settings, $current);

        if (($next['qr_image_base64'] ?? '') === '' && $this->hasReceiverIdentity($next)) {
            $qrResult = $this->generateQrBase64($next);
            if (($qrResult['base64'] ?? '') !== '') {
                $next['qr_image_base64'] = (string) $qrResult['base64'];
                $next['qr_image_mime'] = (string) ($qrResult['mime'] ?? 'image/png');
                $next['qr_error'] = '';
                $next['generated_at'] = now()->toIso8601String();
            } else {
                $next['qr_error'] = (string) ($qrResult['error'] ?? '');
            }
        }

        if (($current['reference'] ?? '') === '' && ($next['reference'] ?? '') !== '') {
            $next['generated_at'] = now()->toIso8601String();
        }

        if ($current !== $next) {
            $payload[self::PAYLOAD_KEY] = $next;
            $order->forceFill(['payload' => $payload])->save();
        }

        return $next;
    }

    public function isBankTransferCode(string $code): bool
    {
        $normalized = strtolower(trim($code));

        return in_array($normalized, ['bank', 'bank_transfer'], true);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function hasReceiverIdentity(array $snapshot): bool
    {
        return trim((string) ($snapshot['receiver_name'] ?? '')) !== ''
            && trim((string) ($snapshot['receiver_street'] ?? '')) !== ''
            && trim((string) ($snapshot['receiver_place'] ?? '')) !== ''
            && trim((string) ($snapshot['receiver_iban'] ?? '')) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveMethodSettings(string $methodCode): array
    {
        $method = PaymentMethod::query()
            ->where('code', $methodCode)
            ->first();

        return is_array($method?->settings) ? $method->settings : [];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function buildSnapshot(Order $order, array $settings, array $current): array
    {
        $shippingAddress = trim((string) ($order->shipping_address_line_1 ?: $order->billing_address_line_1));
        $shippingPlace = trim((string) (($order->shipping_postal_code ?: $order->billing_postal_code).' '.($order->shipping_city ?: $order->billing_city)));

        $reference = trim((string) ($current['reference'] ?? ''));
        if ($reference === '') {
            $reference = (string) $order->id.now()->format('y');
        }

        $receiverName = trim((string) ($current['receiver_name'] ?? $settings['upi_receiver_name'] ?? ''));
        $receiverStreet = trim((string) ($current['receiver_street'] ?? $settings['upi_receiver_street'] ?? ''));
        $receiverPlace = trim((string) ($current['receiver_place'] ?? $settings['upi_receiver_place'] ?? ''));
        $receiverIban = strtoupper(str_replace(' ', '', trim((string) ($current['receiver_iban'] ?? $settings['upi_receiver_iban'] ?? ''))));

        return [
            'order_number' => (string) $order->order_number,
            'amount' => (float) $order->grand_total,
            'amount_cents' => (int) round(((float) $order->grand_total) * 100),
            'currency' => strtoupper((string) ($order->currency_code ?: 'EUR')),
            'reference' => $reference,
            'model' => trim((string) ($current['model'] ?? $settings['upi_model'] ?? '00')),
            'purpose_code' => trim((string) ($current['purpose_code'] ?? $settings['upi_purpose_code'] ?? 'SUPP')),
            'description' => trim((string) ($current['description'] ?? $settings['upi_description'] ?? 'Web narudzba')),

            'sender_name' => trim((string) ($order->customer_name ?: trim($order->billing_first_name.' '.$order->billing_last_name))),
            'sender_street' => $shippingAddress,
            'sender_place' => $shippingPlace,

            'receiver_name' => $receiverName,
            'receiver_street' => $receiverStreet,
            'receiver_place' => $receiverPlace,
            'receiver_iban' => $receiverIban,

            'qr_image_base64' => trim((string) ($current['qr_image_base64'] ?? '')),
            'qr_image_mime' => trim((string) ($current['qr_image_mime'] ?? 'image/png')),
            'qr_error' => trim((string) ($current['qr_error'] ?? '')),
            'generated_at' => trim((string) ($current['generated_at'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{base64?: string, mime?: string, error?: string}
     */
    private function generateQrBase64(array $snapshot): array
    {
        $hubPayload = [
            'renderer' => 'image',
            'options' => [
                'format' => 'png',
                'scale' => 3,
                'ratio' => 3,
                'color' => '#2c3e50',
                'bgColor' => '#ffffff',
                'padding' => 20,
            ],
            'data' => [
                'amount' => (int) ($snapshot['amount_cents'] ?? 0),
                'currency' => (string) ($snapshot['currency'] ?? 'EUR'),
                'sender' => [
                    'name' => (string) ($snapshot['sender_name'] ?? ''),
                    'street' => (string) ($snapshot['sender_street'] ?? ''),
                    'place' => (string) ($snapshot['sender_place'] ?? ''),
                ],
                'receiver' => [
                    'name' => (string) ($snapshot['receiver_name'] ?? ''),
                    'street' => (string) ($snapshot['receiver_street'] ?? ''),
                    'place' => (string) ($snapshot['receiver_place'] ?? ''),
                    'iban' => (string) ($snapshot['receiver_iban'] ?? ''),
                    'model' => (string) ($snapshot['model'] ?? '00'),
                    'reference' => (string) ($snapshot['reference'] ?? ''),
                ],
                'purpose' => (string) ($snapshot['purpose_code'] ?? 'SUPP'),
                'description' => (string) ($snapshot['description'] ?? 'Web narudzba'),
            ],
        ];

        try {
            $response = Http::timeout(20)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('https://hub3.bigfish.software/api/v2/barcode', $hubPayload);
        } catch (\Throwable $e) {
            Log::warning('UPI QR generation failed: '.$e->getMessage());

            return ['error' => 'UPI service unavailable.'];
        }

        if (! $response->successful()) {
            return ['error' => 'UPI service responded with HTTP '.$response->status().'.'];
        }

        $body = (string) $response->body();
        if ($body === '') {
            return ['error' => 'UPI service returned empty response.'];
        }

        $contentType = strtolower(trim((string) $response->header('Content-Type', '')));
        if (str_starts_with($contentType, 'application/json')) {
            $decoded = json_decode($body, true);
            $error = (string) ($decoded['errors'][0] ?? $decoded['message'] ?? 'UPI service returned error payload.');

            return ['error' => $error];
        }

        return [
            'base64' => base64_encode($body),
            'mime' => $contentType !== '' ? $contentType : 'image/png',
        ];
    }
}
