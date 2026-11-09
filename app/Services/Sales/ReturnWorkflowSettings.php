<?php

namespace App\Services\Sales;

use App\Models\User;
use App\Services\Settings\SystemSettingsService;

class ReturnWorkflowSettings
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    public function approverEmail(): string
    {
        return strtolower(trim((string) $this->settings->get(
            'store_withdrawal_approver_email',
            $this->isHerrera() ? 'klara@herrera.hr' : $this->settings->get('store_withdrawal_admin_email', ''),
        )));
    }

    /** @return list<string> */
    public function notificationEmails(): array
    {
        $value = $this->settings->get('store_withdrawal_notification_emails', $this->isHerrera()
            ? ['klara@herrera.hr', 'warehouse@herrera.hr', 'judita@herrera.hr'] : []);

        return self::parseEmails($value);
    }

    public function canApprove(?User $user): bool
    {
        return $user && ($user->isA('superadmin') || (
            $user->can('sales.withdrawals.manage')
            && $this->approverEmail() !== ''
            && strtolower(trim($user->email)) === $this->approverEmail()
        ));
    }

    /** @return list<string> */
    public static function parseEmails(mixed $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value);

        return array_values(array_unique(array_filter(array_map(
            static fn ($email): string => strtolower(trim((string) $email)), $values ?: [],
        ), static fn ($email): bool => (bool) filter_var($email, FILTER_VALIDATE_EMAIL))));
    }

    private function isHerrera(): bool
    {
        return str_contains(strtolower((string) $this->settings->get('store_brand_name', config('app.name', ''))), 'herrera');
    }
}
