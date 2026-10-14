<?php

namespace App\Services\User;

use App\Models\User;
use App\Models\User\CustomerGroup;
use App\Services\Admin\OrderManagerAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CustomerManagementService
{
    public function canManageAssignments(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return (bool) ($actor && ($actor->account_type !== 'staff' || $actor->admin_login_enabled) && $actor->isA('superadmin', 'admin'));
    }

    public function representatives(): Builder
    {
        return User::query()->whereHas('roles', fn (Builder $query) => $query->whereIn('name', ['order_manager', 'admin', 'superadmin']))
            ->where(fn (Builder $query) => $query->where('account_type', '!=', 'staff')->orWhere('admin_login_enabled', true));
    }

    public function assertCustomer(User $customer, User $actor): void
    {
        abort_unless($actor->isA('superadmin', 'admin') || ($actor->can('admin.access') && $actor->can('users.profile.update')), 403);
        abort_unless($customer->account_type === 'customer' && ! $customer->isA('superadmin', 'admin', 'editor', 'order_manager') && ! $customer->can('admin.access'), 403);
        app(OrderManagerAccess::class)->assertCustomer($customer, $actor);
    }

    public function canRecordContact(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return (bool) ($actor && ($actor->account_type !== 'staff' || $actor->admin_login_enabled) && ($actor->isA('superadmin', 'admin') || ($actor->can('admin.access') && $actor->can('users.list.view') && ($actor->isA('order_manager') || $actor->can('users.profile.update')))));
    }

    public function assertContactCustomer(User $customer, User $actor): void
    {
        abort_unless($this->canRecordContact($actor), 403);
        abort_unless($customer->account_type === 'customer' && ! $customer->isA('superadmin', 'admin', 'editor', 'order_manager') && ! $customer->can('admin.access'), 403);
        app(OrderManagerAccess::class)->assertCustomer($customer, $actor);
    }

    /** Replace representatives only for explicitly selected customers. An empty list clears assignment. */
    public function assignRepresentatives(array $customerIds, array $representativeIds, User $actor): void
    {
        abort_unless($this->canManageAssignments($actor), 403);
        $customers = $this->customers($customerIds, $actor);
        $representativeIds = array_values(array_unique(array_map('intval', $representativeIds)));
        abort_unless($this->representatives()->whereIn('id', $representativeIds)->count() === count($representativeIds), 422);
        DB::transaction(function () use ($customers, $representativeIds, $actor): void {
            foreach ($customers as $customer) {
                $before = $customer->salesRepresentatives()->pluck('users.id')->all();
                $account = $customer->b2bAccount()->lockForUpdate()->first();
                if ($account && $account->status === \App\Models\User\B2BAccount::STATUS_PENDING) {
                    if (count($representativeIds) > 1 && ! in_array((int) $account->assigned_sales_rep_id, $representativeIds, true)) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['representativeIds' => 'Za novi zahtjev odaberite jednog odgovornog komercijalista.']);
                    }
                    $primaryId = count($representativeIds) <= 1 ? ($representativeIds[0] ?? null) : $account->assigned_sales_rep_id;
                    if ((int) $account->assigned_sales_rep_id !== (int) $primaryId) {
                        $account->update([
                            'assigned_sales_rep_id' => $primaryId, 'contacted_at' => null, 'sales_comment' => null,
                            'proposed_terms' => null, 'proposed_by' => null, 'proposed_at' => null,
                            'payload' => array_replace($account->payload ?? [], ['approval_workflow_required' => true]),
                        ]);
                    }
                }
                $customer->salesRepresentatives()->sync($representativeIds);
                activity('customer-crm')->performedOn($customer)->causedBy($actor)->event('representatives_assigned')
                    ->withProperties(['previous_ids' => $before, 'representative_ids' => $representativeIds])->log('Dodjela komercijalista kupcu.');
            }
        });
    }

    /** Membership edits never replace pricing groups or unrelated segments. */
    public function assignBusinessSegments(array $customerIds, array $segmentIds, string $mode, User $actor): void
    {
        abort_unless($actor->isA('superadmin') || $actor->can('users.groups.manage'), 403);
        $customers = $this->customers($customerIds, $actor, requireProfileEdit: false);
        $segmentIds = array_values(array_unique(array_map('intval', $segmentIds)));
        Validator::make(['mode' => $mode, 'segments' => $segmentIds], [
            'mode' => ['required', Rule::in(['add', 'remove'])], 'segments' => ['required', 'array', 'min:1'],
        ])->validate();
        abort_unless(CustomerGroup::query()->businessSegments()->where('is_active', true)->whereIn('id', $segmentIds)->count() === count($segmentIds), 422);
        DB::transaction(function () use ($customers, $segmentIds, $mode, $actor): void {
            foreach ($customers as $customer) {
                if ($mode === 'add') {
                    $customer->customerGroups()->syncWithoutDetaching($segmentIds);
                } else {
                    $customer->customerGroups()->detach($segmentIds);
                }
                activity('customer-crm')->performedOn($customer)->causedBy($actor)->event('business_segments_updated')
                    ->withProperties(['mode' => $mode, 'segment_ids' => $segmentIds])->log('Izmjena djelatnosti i divizija kupca.');
            }
        });
    }

    private function customers(array $ids, User $actor, bool $requireProfileEdit = true)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        Validator::make(['customers' => $ids], ['customers' => ['required', 'array', 'min:1', 'max:200'], 'customers.*' => ['integer', 'min:1']])->validate();
        $customers = User::query()->whereIn('id', $ids)->get();
        abort_unless($customers->count() === count($ids), 422);
        foreach ($customers as $customer) {
            if ($requireProfileEdit) {
                $this->assertCustomer($customer, $actor);
            } else {
                abort_unless($actor->isA('superadmin', 'admin') || ($actor->can('admin.access') && $actor->can('users.list.view') && $actor->can('users.groups.manage')), 403);
                abort_unless($customer->account_type === 'customer' && ! $customer->isA('superadmin', 'admin', 'editor', 'order_manager') && ! $customer->can('admin.access'), 403);
                app(OrderManagerAccess::class)->assertCustomer($customer, $actor);
            }
        }

        return $customers;
    }
}
