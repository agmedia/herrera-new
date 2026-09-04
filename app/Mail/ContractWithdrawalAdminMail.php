<?php

namespace App\Mail;

use App\Models\Sales\ContractWithdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractWithdrawalAdminMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ContractWithdrawal $withdrawal,
        public readonly string $adminUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('return_request.mail.admin_subject', [
                'order' => $this->withdrawal->order_number,
                'reference' => $this->withdrawal->reference,
            ]),
            replyTo: [
                new \Illuminate\Mail\Mailables\Address(
                    $this->withdrawal->email,
                    $this->withdrawal->full_name,
                ),
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.withdrawals.admin',
        );
    }
}
