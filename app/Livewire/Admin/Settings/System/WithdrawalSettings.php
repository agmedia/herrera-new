<?php

namespace App\Livewire\Admin\Settings\System;

use App\Services\Sales\ReturnWorkflowSettings;
use App\Services\Settings\SystemSettingsService;
use Livewire\Component;
use Silber\Bouncer\BouncerFacade as Bouncer;

class WithdrawalSettings extends Component
{
    public string $adminEmail = '';

    public string $returnAddress = '';

    public string $instructions = '';

    public string $notificationEmails = '';

    public string $approverEmail = '';

    public function mount(SystemSettingsService $settings): void
    {
        $this->authorizeAccess();
        $this->adminEmail = trim((string) $settings->get('store_withdrawal_admin_email', ''));
        $this->returnAddress = trim((string) $settings->get('store_withdrawal_return_address', ''));
        $this->instructions = trim((string) $settings->get('store_withdrawal_instructions', ''));
        $workflow = app(ReturnWorkflowSettings::class);
        $this->notificationEmails = implode("\n", $workflow->notificationEmails());
        $this->approverEmail = $workflow->approverEmail();
    }

    public function save(SystemSettingsService $settings): void
    {
        $this->authorizeAccess();
        $validated = $this->validate([
            'adminEmail' => ['required', 'email', 'max:191'],
            'returnAddress' => ['required', 'string', 'max:500'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'notificationEmails' => ['nullable', 'string', 'max:5000'],
            'approverEmail' => ['nullable', 'email', 'max:191'],
        ]);
        $candidates = preg_split('/[\s,;]+/', trim($this->notificationEmails), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($candidates as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->addError('notificationEmails', 'Unesite valjane e-mail adrese, svaku u novi red.');

                return;
            }
        }

        $settings->putMany([
            'store_withdrawal_admin_email' => trim((string) $validated['adminEmail']),
            'store_withdrawal_return_address' => trim((string) $validated['returnAddress']),
            'store_withdrawal_instructions' => trim((string) ($validated['instructions'] ?? '')),
            'store_withdrawal_notification_emails' => ReturnWorkflowSettings::parseEmails($this->notificationEmails),
            'store_withdrawal_approver_email' => strtolower(trim($this->approverEmail)),
        ]);

        $this->dispatch('notify', type: 'success', message: 'Postavke raskida ugovora su spremljene.');
    }

    public function render()
    {
        return view('livewire.admin.settings.system.withdrawal-settings');
    }

    private function authorizeAccess(): void
    {
        $user = auth()->user();

        abort_unless(
            $user && (Bouncer::is($user)->an('superadmin') || $user->can('settings.system.store.manage')),
            403,
        );
    }
}
