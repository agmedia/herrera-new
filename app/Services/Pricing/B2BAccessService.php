<?php

namespace App\Services\Pricing;

use App\Models\User;
use App\Models\User\B2BAccount;
use Illuminate\Auth\Access\AuthorizationException;

class B2BAccessService
{
    public function requiresApprovedAccount(): bool
    {
        return (bool) config('commerce.b2b_only', true);
    }

    public function b2cEnabled(): bool
    {
        return (bool) app(\App\Services\Settings\SystemSettingsService::class)->get('store_pricing_b2c_enabled', false);
    }

    public function allowsPrivateCustomers(): bool
    {
        return $this->b2cEnabled() || ! $this->requiresApprovedAccount();
    }

    public function displaysNet(?User $user = null): bool
    {
        return $this->requiresApprovedAccount()
            && (! $this->b2cEnabled() || $this->approvedAccount($user) !== null)
            && (bool) config('commerce.b2b_display_net', true);
    }

    public function approvedAccount(?User $user): ?B2BAccount
    {
        if (! $user) {
            return null;
        }

        $user->loadMissing('b2bAccount.customerGroup');
        $account = $user->b2bAccount;

        if (! $account || ! $account->contractIsActive() || ! $account->customerGroup?->is_active) {
            return null;
        }

        return $account;
    }

    public function canViewPrices(?User $user): bool
    {
        return $this->allowsPrivateCustomers() || $this->approvedAccount($user) !== null;
    }

    public function ensureCanPurchase(?User $user): void
    {
        if (! $this->canViewPrices($user)) {
            throw new AuthorizationException(__('ui.b2b.pricing.access_required'));
        }
    }
}
