<?php

namespace App\Mail;

use App\Models\Sales\Offer\CustomerOffer;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CustomerOfferMail extends Mailable
{
    public function __construct(public CustomerOffer $offer, private readonly string $pdf) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('customer_offers.mail_subject', ['number' => $this->offer->number]));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.customer-offers.offer');
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->pdf, $this->offer->number.'.pdf')->withMime('application/pdf')];
    }
}
