<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewsletterCouponMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $couponCode,
        public readonly string $storeName,
        public readonly string $shopUrl,
        public readonly string $logoUrl = '',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.newsletter_coupon.subject', ['code' => $this->couponCode]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.newsletter.coupon',
            with: [
                'coupon_code' => $this->couponCode,
                'store_name' => $this->storeName,
                'shop_url' => $this->shopUrl,
                'logo_url' => $this->logoUrl,
            ],
        );
    }
}
