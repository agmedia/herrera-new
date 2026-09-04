<?php

namespace App\Services\B2B;

use App\Models\User;
use App\Models\User\B2BAccount;
use Illuminate\Support\Facades\DB;

class B2BAccountService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function review(B2BAccount $account, array $data, User $reviewer): B2BAccount
    {
        return DB::transaction(function () use ($account, $data, $reviewer): B2BAccount {
            $previousGroupId = $account->customer_group_id
                ? (int) $account->customer_group_id
                : null;
            $status = (string) $data['status'];
            $approvedGroupId = $status === B2BAccount::STATUS_APPROVED
                ? (int) $data['customer_group_id']
                : null;

            $account->fill([
                'status' => $status,
                'customer_group_id' => $approvedGroupId,
                'erp_customer_id' => $this->nullableString($data['erp_customer_id'] ?? null),
                'erp_company_code' => $this->nullableString($data['erp_company_code'] ?? null),
                'contract_number' => $this->nullableString($data['contract_number'] ?? null),
                'contract_starts_at' => $data['contract_starts_at'] ?: null,
                'contract_ends_at' => $data['contract_ends_at'] ?: null,
                'payment_terms_days' => ($data['payment_terms_days'] ?? '') !== ''
                    ? (int) $data['payment_terms_days']
                    : null,
                'purchase_order_required' => (bool) ($data['purchase_order_required'] ?? false),
                'status_reason' => $this->nullableString($data['status_reason'] ?? null),
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->getKey(),
            ])->save();

            if ($previousGroupId && $previousGroupId !== $approvedGroupId) {
                $account->user->customerGroups()->detach($previousGroupId);
            }

            if ($approvedGroupId) {
                $account->user->customerGroups()->syncWithoutDetaching([$approvedGroupId]);
            }

            activity('admin_users')
                ->performedOn($account->user)
                ->causedBy($reviewer)
                ->event('b2b_reviewed')
                ->withProperties([
                    'b2b_account_id' => $account->getKey(),
                    'status' => $status,
                    'customer_group_id' => $approvedGroupId,
                    'erp_customer_id' => $account->erp_customer_id,
                ])
                ->log('B2B account reviewed');

            return $account->refresh();
        });
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
