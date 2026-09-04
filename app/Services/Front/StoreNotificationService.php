<?php

namespace App\Services\Front;

use App\Mail\NewsletterCouponMail;
use App\Models\Content\Support\ContactMessage;
use App\Models\Sales\Order\Order;
use App\Models\Sales\Order\OrderItem;
use App\Services\Payments\BankTransferUpiService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StoreNotificationService
{
    public function __construct(
        private readonly StoreSettingsService $storeSettings
    ) {
    }

    public function sendContactNotification(ContactMessage $message): void
    {
        $emailSettings = $this->storeSettings->email();
        if (! (bool) ($emailSettings['enabled'] ?? false)) {
            return;
        }

        $to = trim((string) ($emailSettings['contact_to'] ?? ''));
        if ($to === '') {
            $to = trim((string) ($emailSettings['orders_to'] ?? ''));
        }
        if ($to === '') {
            return;
        }

        $subject = '[Contact] '.($message->subject ?: 'New contact message');
        $body = implode("\n", [
            'Name: '.(string) $message->name,
            'Email: '.(string) $message->email,
            'Phone: '.(string) ($message->phone ?? ''),
            'Subject: '.(string) $message->subject,
            'Message:',
            (string) $message->message,
        ]);

        try {
            Mail::raw($body, static function ($mail) use ($to, $subject, $message): void {
                $mail->to($to)->subject($subject);
                if (filter_var($message->email, FILTER_VALIDATE_EMAIL)) {
                    $mail->replyTo($message->email, (string) $message->name);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Store contact notification failed: '.$e->getMessage());
        }
    }

    public function sendReturnRequestNotification(ContactMessage $message): void
    {
        $emailSettings = $this->storeSettings->email();
        if (! (bool) ($emailSettings['enabled'] ?? false)) {
            return;
        }

        $to = trim((string) ($emailSettings['orders_to'] ?? ''));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $subject = (string) ($message->subject ?: __('return_request.mail.subject_fallback'));
        $body = (string) $message->message;

        try {
            Mail::raw($body, static function ($mail) use ($to, $subject, $message): void {
                $mail->to($to)->subject($subject);
                if (filter_var($message->email, FILTER_VALIDATE_EMAIL)) {
                    $mail->replyTo($message->email);
                }
            });
        } catch (\Throwable $e) {
            Log::warning('Store return request notification failed: '.$e->getMessage());
        }
    }

    public function sendNewsletterCoupon(string $email, string $locale = '', string $couponCode = 'BALI10'): void
    {
        $email = trim($email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $emailSettings = $this->storeSettings->email();
        if (! (bool) ($emailSettings['enabled'] ?? false)) {
            return;
        }

        $mailLocale = trim($locale) ?: (string) app()->getLocale();
        $settings = $this->storeSettings->all();
        $brand = $settings['branding'] ?? [];
        $storeName = trim((string) ($brand['store_name'] ?? config('app.name', 'Store'))) ?: (string) config('app.name', 'Store');
        $logoUrl = $this->absoluteUrl((string) ($brand['logo_url'] ?? ''));

        try {
            $this->withLocale($mailLocale, function () use ($email, $couponCode, $storeName, $logoUrl): void {
                Mail::to($email)->send(new NewsletterCouponMail(
                    couponCode: $couponCode,
                    storeName: $storeName,
                    shopUrl: url('/'),
                    logoUrl: $logoUrl,
                ));
            });
        } catch (\Throwable $e) {
            Log::warning('Store newsletter coupon email failed: '.$e->getMessage());
        }
    }

    public function sendOrderNotification(Order $order): void
    {
        $emailSettings = $this->storeSettings->email();
        if (! (bool) ($emailSettings['enabled'] ?? false)) {
            return;
        }

        $adminTo = trim((string) ($emailSettings['orders_to'] ?? ''));
        $customerTo = trim((string) ($order->customer_email ?? ''));
        if ($adminTo === '' && $customerTo === '') {
            return;
        }

        $mailLocale = trim((string) ($order->locale ?? ''));
        if ($mailLocale === '') {
            $mailLocale = (string) config('app.locale');
        }

        $data = $this->buildOrderMailData($order);

        if (filter_var($adminTo, FILTER_VALIDATE_EMAIL)) {
            try {
                $this->withLocale($mailLocale, function () use ($data, $adminTo, $order): void {
                    $subject = __('mail.orders.subject_admin', ['order' => $order->order_number]);
                    Mail::send('emails.orders.notification', array_merge($data, [
                        'variant' => 'admin',
                    ]), static function ($mail) use ($adminTo, $order, $subject): void {
                        $mail->to($adminTo)->subject($subject);
                        if (filter_var($order->customer_email, FILTER_VALIDATE_EMAIL)) {
                            $mail->replyTo($order->customer_email, (string) $order->customer_name);
                        }
                    });
                });
            } catch (\Throwable $e) {
                Log::warning('Store order admin notification failed: '.$e->getMessage());
            }
        }

        if (filter_var($customerTo, FILTER_VALIDATE_EMAIL)) {
            try {
                $this->withLocale($mailLocale, function () use ($data, $customerTo, $adminTo, $order): void {
                    $subject = __('mail.orders.subject_customer', ['order' => $order->order_number]);
                    Mail::send('emails.orders.notification', array_merge($data, [
                        'variant' => 'customer',
                    ]), static function ($mail) use ($customerTo, $adminTo, $subject): void {
                        $mail->to($customerTo)->subject($subject);
                        if (filter_var($adminTo, FILTER_VALIDATE_EMAIL)) {
                            $mail->replyTo($adminTo);
                        }
                    });
                });
            } catch (\Throwable $e) {
                Log::warning('Store order customer confirmation failed: '.$e->getMessage());
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderMailData(Order $order): array
    {
        $order->loadMissing([
            'items.product.media',
            'items.productOptionValue.optionValue.translations',
            'items.productOptionValue.optionValue.option.translations',
            'items.productOptionValue.parentOptionValue.translations',
            'items.productOptionValue.parentOptionValue.option.translations',
        ]);
        $bankTransfer = app(BankTransferUpiService::class)->ensureForOrder($order);

        $settings = $this->storeSettings->all();
        $brand = $settings['branding'] ?? [];
        $storeName = trim((string) ($brand['store_name'] ?? config('app.name', 'Store')));
        if ($storeName === '') {
            $storeName = (string) config('app.name', 'Store');
        }

        $currency = strtoupper((string) ($order->currency_code ?: 'EUR'));

        $items = $order->items->map(function (OrderItem $item) use ($currency): array {
            $slug = trim((string) ($item->payload['product_slug'] ?? ''));
            $productUrl = $slug !== '' ? route('products.show', ['slug' => $slug]) : '';

            $imageUrl = '';
            if ($item->relationLoaded('product') && $item->product) {
                $imageUrl = (string) ($item->product->getFirstMediaUrl('product_main')
                    ?: $item->product->getFirstMediaUrl('product_gallery'));
            }

            $optionLabel = $this->buildOrderItemOptionLabel($item);

            return [
                'name' => (string) $item->name,
                'sku' => (string) $item->sku,
                'option_label' => $optionLabel,
                'quantity' => (int) $item->quantity,
                'unit_price' => $this->formatMoney((float) $item->unit_price, $currency),
                'line_total' => $this->formatMoney((float) $item->line_total, $currency),
                'product_url' => $productUrl,
                'image_url' => $this->absoluteUrl($imageUrl),
            ];
        })->values()->all();
        $boxNow = is_array($order->payload['shipping']['boxnow'] ?? null)
            ? $order->payload['shipping']['boxnow']
            : null;

        return [
            'store_name' => $storeName,
            'logo_url' => $this->absoluteUrl((string) ($brand['logo_url'] ?? '')),
            'order_number' => (string) $order->order_number,
            'placed_at' => optional($order->placed_at)->format('d.m.Y H:i') ?: '',
            'payment_method' => (string) ($order->payment_method_name ?? ''),
            'shipping_method' => (string) ($order->shipping_method_name ?? ''),
            'customer_name' => (string) ($order->customer_name ?? ''),
            'customer_email' => (string) ($order->customer_email ?? ''),
            'customer_phone' => (string) ($order->customer_phone ?? ''),
            'billing_address' => trim(implode(', ', array_filter([
                trim((string) $order->billing_address_line_1),
                trim((string) $order->billing_address_line_2),
                trim((string) $order->billing_postal_code.' '.$order->billing_city),
                trim((string) $order->billing_country_code),
            ]))),
            'shipping_address' => trim(implode(', ', array_filter([
                trim((string) $order->shipping_address_line_1),
                trim((string) $order->shipping_address_line_2),
                trim((string) $order->shipping_postal_code.' '.$order->shipping_city),
                trim((string) $order->shipping_country_code),
            ]))),
            'customer_note' => trim((string) ($order->customer_note ?? '')),
            'currency' => $currency,
            'items' => $items,
            'totals' => [
                'subtotal' => $this->formatMoney((float) $order->subtotal, $currency),
                'discount' => $this->formatMoney((float) $order->discount_total, $currency),
                'shipping' => $this->formatMoney((float) $order->shipping_total, $currency),
                'payment_fee' => $this->formatMoney((float) $order->payment_fee_total, $currency),
                'tax' => $this->formatMoney((float) $order->tax_total, $currency),
                'grand_total' => $this->formatMoney((float) $order->grand_total, $currency),
            ],
            'totals_raw' => [
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount_total,
                'shipping' => (float) $order->shipping_total,
                'payment_fee' => (float) $order->payment_fee_total,
                'tax' => (float) $order->tax_total,
                'grand_total' => (float) $order->grand_total,
            ],
            'bank_transfer' => is_array($bankTransfer) ? $bankTransfer : null,
            'box_now' => is_array($boxNow) ? [
                'locker_id' => trim((string) ($boxNow['locker_id'] ?? '')),
                'locker_name' => trim((string) ($boxNow['locker_name'] ?? '')),
                'address_line_1' => trim((string) ($boxNow['address_line_1'] ?? '')),
                'postal_code' => trim((string) ($boxNow['postal_code'] ?? '')),
                'city' => trim((string) ($boxNow['city'] ?? '')),
            ] : null,
        ];
    }

    private function buildOrderItemOptionLabel(OrderItem $item): string
    {
        $locale = app()->getLocale();
        $fallbackLocale = (string) config('app.locale');

        $childValueTranslation = $item->productOptionValue?->optionValue?->translations?->firstWhere('locale', $locale)
            ?? $item->productOptionValue?->optionValue?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $item->productOptionValue?->optionValue?->translations?->first();
        $childOptionTranslation = $item->productOptionValue?->optionValue?->option?->translations?->firstWhere('locale', $locale)
            ?? $item->productOptionValue?->optionValue?->option?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $item->productOptionValue?->optionValue?->option?->translations?->first();

        $parentValueTranslation = $item->productOptionValue?->parentOptionValue?->translations?->firstWhere('locale', $locale)
            ?? $item->productOptionValue?->parentOptionValue?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $item->productOptionValue?->parentOptionValue?->translations?->first();
        $parentOptionTranslation = $item->productOptionValue?->parentOptionValue?->option?->translations?->firstWhere('locale', $locale)
            ?? $item->productOptionValue?->parentOptionValue?->option?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $item->productOptionValue?->parentOptionValue?->option?->translations?->first();

        $parts = [];
        $parentOptionName = trim((string) ($parentOptionTranslation?->name ?? ''));
        $parentValueName = trim((string) ($parentValueTranslation?->name ?? ''));
        $childOptionName = trim((string) ($childOptionTranslation?->name ?? ''));
        $childValueName = trim((string) ($childValueTranslation?->name ?? ''));

        if ($parentOptionName !== '' && $parentValueName !== '') {
            $parts[] = $parentOptionName.': '.$parentValueName;
        }
        if ($childOptionName !== '' && $childValueName !== '') {
            $parts[] = $childOptionName.': '.$childValueName;
        }

        if ($parts !== []) {
            return implode(' / ', $parts);
        }

        return $childValueName !== '' ? $childValueName : $parentValueName;
    }

    private function formatMoney(float $value, string $currencyCode): string
    {
        $symbol = $currencyCode === 'EUR' ? 'EUR' : $currencyCode;

        return number_format($value, 2, '.', ',').' '.$symbol;
    }

    private function absoluteUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        return url($url);
    }

    private function withLocale(string $locale, callable $callback): void
    {
        $previousLocale = App::getLocale();
        try {
            App::setLocale($locale);
            $callback();
        } finally {
            App::setLocale($previousLocale);
        }
    }
}
