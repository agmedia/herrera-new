<?php

namespace App\Services\Admin;

use App\Models\Sales\Order\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class OrderManagerAccess
{
    public function isRestricted(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return $actor && $actor->isA('order_manager') && ! $actor->isA('superadmin', 'super-admin', 'admin');
    }

    public function scopeCustomers(Builder $query, ?User $actor = null): Builder
    {
        $actor ??= auth()->user();
        if (! $this->isRestricted($actor)) {
            return $query;
        }

        return $query
            ->where('users.account_type', 'customer')
            ->whereIn('users.id', DB::table('admin_customer_assignments')
                ->where('admin_user_id', $actor->id)->select('customer_user_id'))
            ->whereDoesntHave('roles', fn (Builder $roles) => $roles->whereIn('name', ['superadmin', 'super-admin', 'admin', 'editor', 'order_manager']))
            ->whereDoesntHave('roles.abilities', fn (Builder $abilities) => $this->staffAbilities($abilities))
            ->whereDoesntHave('abilities', fn (Builder $abilities) => $this->staffAbilities($abilities));
    }

    public function scopeOrders(Builder|QueryBuilder $query, ?User $actor = null): Builder|QueryBuilder
    {
        $actor ??= auth()->user();
        if (! $this->isRestricted($actor)) {
            return $query;
        }

        return $query->whereIn('orders.user_id', $this->scopeCustomers(User::query(), $actor)->select('users.id'));
    }

    public function canAccessCustomer(User $customer, ?User $actor = null): bool
    {
        if (! $this->isRestricted($actor)) {
            return true;
        }

        return $this->scopeCustomers(User::query(), $actor)->whereKey($customer->id)->exists();
    }

    public function assertCustomer(User $customer, ?User $actor = null): void
    {
        abort_unless($this->canAccessCustomer($customer, $actor), 403);
    }

    public function assertOrder(Order $order, ?User $actor = null): void
    {
        if ($this->isRestricted($actor)) {
            abort_unless($this->scopeOrders(Order::query(), $actor)->whereKey($order->id)->exists(), 403);
        }
    }

    private function staffAbilities(Builder $query): void
    {
        $query->whereIn('abilities.name', ['admin.access', '*'])->where('permissions.forbidden', false);
    }
}
