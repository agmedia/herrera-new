<?php

namespace App\Mail;

use App\Models\Sales\ContractWithdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractWithdrawalReceiptMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ContractWithdrawal $withdrawal,
        public readonly string $storeName,
        public readonly string $returnAddress = '',
        public readonly string $instructions = '',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('return_request.mail.customer_subject', [
                'reference' => $this->withdrawal->reference,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.withdrawals.receipt',
        );
    }
}
