<?php

namespace App\Services\Sales;

use App\Models\Sales\Offer\CustomerOffer;
use App\Models\User;
use App\Services\Admin\OrderManagerAccess;
use Illuminate\Database\Eloquent\Builder;

class CustomerOfferAccess
{
    public function can(?User $actor, string $action = 'view'): bool
    {
        return $actor && ($actor->isA('superadmin', 'super-admin') || $actor->can('sales.offers.'.$action));
    }

    public function assertStaff(?User $actor, string $action = 'view'): void
    {
        abort_unless($this->can($actor, $action), 403);
    }

    public function assertCustomer(User $customer, User $actor): void
    {
        abort_unless($customer->account_type === 'customer', 422);
        app(OrderManagerAccess::class)->assertCustomer($customer, $actor);
    }

    public function scope(Builder $query, User $actor): Builder
    {
        $this->assertStaff($actor);

        return app(OrderManagerAccess::class)->isRestricted($actor)
            ? $query->whereIn('customer_user_id', app(OrderManagerAccess::class)->scopeCustomers(User::query(), $actor)->select('users.id'))
            : $query;
    }

    public function assertOffer(CustomerOffer $offer, ?User $actor, string $action = 'view'): void
    {
        $this->assertStaff($actor, $action);
        if (! $offer->customer) {
            abort_unless($action === 'view' && ! app(OrderManagerAccess::class)->isRestricted($actor), 404);
        } else {
            $this->assertCustomer($offer->customer, $actor);
        }
    }

    public function assertBuyer(CustomerOffer $offer, ?User $actor): void
    {
        abort_unless($actor && (int) $offer->customer_user_id === (int) $actor->id && $offer->status === 'sent', 404);
    }
}
