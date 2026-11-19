<?php

namespace App\Services\Analytics;

use App\Models\Sales\Order\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesActivityOrders
{
    public const AUTHOR = 'COALESCE(sales_session.sales_rep_id, audited_rep.id)';

    public static function query(Builder $customerIds, CarbonImmutable $from, CarbonImmutable $to, string $channel, ?int $rep, bool $portfolio, bool $includeGuests = false): Builder
    {
        return Order::query()->withoutCancelled()
            ->leftJoin('customer_sales_sessions as sales_session', 'sales_session.id', '=', 'orders.sales_session_id')
            ->leftJoin('users as audited_rep', function ($join) {
                $join->on('audited_rep.id', '=', 'orders.created_by')
                    ->whereColumn('orders.payload->customer_impersonation->admin_id', 'audited_rep.id')
                    ->whereColumn('orders.payload->customer_impersonation->customer_id', 'orders.user_id');
            })
            ->whereNull('orders.payload->staff_impersonation->admin_id')
            // OpenCart saves an order row before checkout is confirmed (status 0).
            ->where(fn ($q) => $q->whereNotNull('orders.status_id')->orWhereNull('orders.source')
                ->orWhere('orders.source', '!=', 'opencart_import')->orWhereNull('orders.payload->opencart->order_status_id')
                ->orWhereNotIn('orders.payload->opencart->order_status_id', [0, '0']))
            ->where(function ($buyers) use ($customerIds, $includeGuests) {
                $buyers->whereIn('orders.user_id', $customerIds);
                if ($includeGuests) {
                    $buyers->orWhereNull('orders.user_id');
                }
            })
            ->forCustomerType($channel === 'all' ? null : $channel)
            ->whereBetween(DB::raw('COALESCE(orders.placed_at, orders.created_at)'), [$from, $to])
            ->where(fn ($q) => $q->whereNull('sales_session.performed_by_id')->orWhereColumn('sales_session.performed_by_id', 'sales_session.sales_rep_id'))
            ->when($portfolio, function ($q) use ($rep) {
                $assigned = DB::table('admin_customer_assignments')->select('customer_user_id')
                    ->when($rep, fn ($assignments) => $assignments->where('admin_user_id', $rep));
                $q->whereIn('orders.user_id', $assigned);
            })->when(! $portfolio && $rep, fn ($q) => $q->whereRaw(self::AUTHOR.' = ?', [(int) $rep]));
    }
}
