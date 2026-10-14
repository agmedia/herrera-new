<?php

namespace App\Services\B2B;

use App\Models\User;
use App\Models\User\B2BAccount;
use App\Models\User\CustomerGroup;
use App\Services\User\CustomerManagementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class B2BApprovalWorkflow
{
    public function canFinalize(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return (bool) ($actor && ($actor->account_type !== 'staff' || $actor->admin_login_enabled) && $actor->isA('superadmin', 'admin'));
    }

    public function assign(B2BAccount $account, int $representativeId, User $actor): void
    {
        abort_unless($this->canFinalize($actor), 403);
        DB::transaction(function () use ($account, $representativeId, $actor): void {
            $account = B2BAccount::query()->lockForUpdate()->findOrFail($account->id);
            abort_unless($account->status === B2BAccount::STATUS_PENDING, 422);
            app(CustomerManagementService::class)->assignRepresentatives([$account->user_id], [$representativeId], $actor);
            $account->forceFill([
                'assigned_sales_rep_id' => $representativeId,
                'contacted_at' => null, 'sales_comment' => null, 'proposed_terms' => null, 'proposed_by' => null, 'proposed_at' => null,
                'payload' => array_replace($account->payload ?? [], ['approval_workflow_required' => true]),
            ])->save();
        });
    }

    public function propose(B2BAccount $account, array $data, User $actor): void
    {
        app(CustomerManagementService::class)->assertContactCustomer($account->user, $actor);
        abort_unless((int) $account->assigned_sales_rep_id === (int) $actor->id && $account->status === B2BAccount::STATUS_PENDING, 403);
        $data['sales_comment'] = trim((string) ($data['sales_comment'] ?? ''));
        $data['terms_note'] = trim((string) ($data['terms_note'] ?? ''));
        $validated = Validator::make($data, [
            'contacted_at' => ['required', 'date', 'before_or_equal:now'],
            'sales_comment' => ['required', 'string', 'min:5', 'max:5000'],
            'terms_note' => ['required', 'string', 'max:5000'],
            'customer_group_id' => ['required', 'integer', Rule::exists('customer_groups', 'id')->where(fn ($query) => $query->where('is_active', true)->where(fn ($query) => $query
                ->whereNull('payload->classification_type')->orWhereNotIn('payload->classification_type', [CustomerGroup::CLASSIFICATION_INDUSTRY, CustomerGroup::CLASSIFICATION_DIVISION])))],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'purchase_order_required' => ['boolean'],
        ])->validate();
        DB::transaction(function () use ($account, $validated, $actor): void {
            $locked = B2BAccount::query()->lockForUpdate()->findOrFail($account->id);
            abort_unless($locked->status === B2BAccount::STATUS_PENDING && (int) $locked->assigned_sales_rep_id === (int) $actor->id, 403);
            $locked->update([
                'contacted_at' => $validated['contacted_at'], 'sales_comment' => $validated['sales_comment'],
                'proposed_terms' => [
                    'customer_group_id' => (int) $validated['customer_group_id'],
                    'payment_terms_days' => (int) $validated['payment_terms_days'],
                    'purchase_order_required' => (bool) ($validated['purchase_order_required'] ?? false),
                    'terms_note' => $validated['terms_note'],
                ],
                'proposed_by' => $actor->id, 'proposed_at' => now(),
            ]);
            activity('customer-crm')->performedOn($locked->user)->causedBy($actor)->event('b2b_terms_proposed')
                ->withProperties(['b2b_account_id' => $locked->id, 'proposed_terms' => $locked->proposed_terms, 'sales_comment' => $locked->sales_comment])
                ->log('Komercijalist je predložio uvjete nakon razgovora s kupcem.');
        });
    }

    public function assertReview(B2BAccount $account, string $status, User $actor): void
    {
        if ($status !== $account->status && in_array($status, [B2BAccount::STATUS_APPROVED, B2BAccount::STATUS_REJECTED, B2BAccount::STATUS_SUSPENDED], true)) {
            abort_unless($this->canFinalize($actor), 403);
        }
        if ($status === B2BAccount::STATUS_APPROVED && $account->status !== B2BAccount::STATUS_APPROVED && $account->requiresSalesProposal()) {
            if (! $account->assigned_sales_rep_id || ! $account->contacted_at || ! trim((string) $account->sales_comment) || ! $account->proposed_at || ! $account->proposed_terms
                || (int) $account->proposed_by !== (int) $account->assigned_sales_rep_id) {
                throw ValidationException::withMessages(['status' => 'Prije konačnog odobrenja dodijelite komercijalista i pričekajte njegov komentar i prijedlog uvjeta.']);
            }
        }
    }
}
