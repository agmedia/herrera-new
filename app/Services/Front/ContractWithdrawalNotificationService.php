<?php

namespace App\Services\Front;

use App\Mail\ContractWithdrawalAdminMail;
use App\Mail\ContractWithdrawalReceiptMail;
use App\Models\Sales\ContractWithdrawal;
use App\Services\Sales\ReturnWorkflowSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContractWithdrawalNotificationService
{
    public function __construct(
        private readonly StoreSettingsService $storeSettings,
        private readonly ReturnWorkflowSettings $workflowSettings,
    ) {}

    /**
     * Sends the legally required durable-medium receipt and the operational
     * admin notification independently so one failure does not block the other.
     */
    public function send(ContractWithdrawal $withdrawal): void
    {
        $errors = [];

        try {
            $this->sendConsumerReceipt($withdrawal);
        } catch (\Throwable $exception) {
            $errors[] = 'Korisnik: '.$exception->getMessage();
            Log::error('Contract withdrawal consumer receipt failed', [
                'withdrawal_id' => $withdrawal->id,
                'exception' => $exception,
            ]);
        }

        try {
            $this->sendAdminNotification($withdrawal);
        } catch (\Throwable $exception) {
            $errors[] = 'Administrator: '.$exception->getMessage();
            Log::error('Contract withdrawal admin notification failed', [
                'withdrawal_id' => $withdrawal->id,
                'exception' => $exception,
            ]);
        }

        $withdrawal->forceFill([
            'notification_error' => $errors === [] ? null : implode("\n", $errors),
        ])->save();
    }

    public function sendConsumerReceipt(ContractWithdrawal $withdrawal): void
    {
        $settings = $this->storeSettings->withdrawal();
        $branding = $this->storeSettings->branding();

        Mail::to($withdrawal->email)
            ->locale($withdrawal->locale)
            ->send(new ContractWithdrawalReceiptMail(
                withdrawal: $withdrawal,
                storeName: trim((string) ($branding['store_name'] ?? config('app.name'))) ?: (string) config('app.name'),
                returnAddress: (string) ($settings['return_address'] ?? ''),
                instructions: (string) ($settings['instructions'] ?? ''),
            ));

        $withdrawal->forceFill([
            'consumer_notified_at' => now(),
        ])->save();
    }

    public function sendAdminNotification(ContractWithdrawal $withdrawal): void
    {
        $recipients = array_values(array_unique(array_filter(array_merge(
            [$this->adminEmail()], $this->workflowSettings->notificationEmails(),
        ))));
        if ($recipients === []) {
            throw new \RuntimeException('Nije postavljena administratorska e-mail adresa za raskide ugovora.');
        }

        $failures = [];
        foreach ($recipients as $to) {
            try {
                Mail::to($to)
                    ->locale((string) config('app.locale', 'hr'))
                    ->send(new ContractWithdrawalAdminMail(
                        withdrawal: $withdrawal,
                        adminUrl: route('admin.withdrawals.show', $withdrawal),
                    ));
            } catch (\Throwable $exception) {
                $failures[] = $to.': '.$exception->getMessage();
            }
        }
        if ($failures !== []) {
            throw new \RuntimeException(implode("\n", $failures));
        }

        $withdrawal->forceFill([
            'admin_notified_at' => now(),
        ])->save();
    }

    private function adminEmail(): string
    {
        $withdrawal = $this->storeSettings->withdrawal();
        $email = $this->storeSettings->email();

        foreach ([
            $withdrawal['admin_email'] ?? '',
            $email['orders_to'] ?? '',
            $email['contact_to'] ?? '',
            config('mail.from.address'),
        ] as $candidate) {
            $candidate = trim((string) $candidate);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return '';
    }
}
