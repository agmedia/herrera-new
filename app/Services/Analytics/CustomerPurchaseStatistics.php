<?php

namespace App\Services\Analytics;

use App\Models\User;
use App\Services\Admin\OrderManagerAccess;
use App\Support\OrderStatusClassification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerPurchaseStatistics
{
    public function currencies(?int $userId = null, ?string $guestEmail = null): Collection
    {
        return app(OrderManagerAccess::class)->scopeOrders(DB::table('orders'))
            ->when($userId !== null, fn (Builder $query) => $query->where('user_id', $userId))
            ->when($guestEmail !== null && $guestEmail !== '', fn (Builder $query) => $query->whereNull('user_id')->whereRaw('LOWER(TRIM(customer_email)) = ?', [mb_strtolower(trim($guestEmail))]))
            ->selectRaw('UPPER(currency_code) AS currency')
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency');
    }

    /** @param array<string, mixed> $filters */
    public function summary(array $filters): array
    {
        $orders = $this->eligibleOrders($filters);
        $row = (clone $orders)->selectRaw('
            COUNT(*) AS orders,
            COALESCE(SUM(orders.grand_total), 0) AS order_value,
            COALESCE(SUM(orders.grand_total - orders.tax_total), 0) AS net_value,
            COALESCE(SUM(orders.item_qty), 0) AS items_sold,
            COALESCE(SUM(CASE WHEN orders.paid_at IS NOT NULL OR order_statuses.is_paid = 1 THEN orders.grand_total ELSE 0 END), 0) AS paid_value,
            MIN(COALESCE(orders.placed_at, orders.created_at)) AS first_order_at,
            MAX(COALESCE(orders.placed_at, orders.created_at)) AS last_order_at
        ')->first();

        $customers = DB::query()->fromSub($this->customerAggregate($filters), 'buyers')
            ->selectRaw('COUNT(*) AS customers, COALESCE(SUM(CASE WHEN order_count > 1 THEN 1 ELSE 0 END), 0) AS repeat_customers')
            ->first();
        $cancelled = $this->orders($filters)->where(fn (Builder $query) => $this->whereCancelled($query))->count();
        $count = (int) $row->orders;

        return [
            'orders' => $count,
            'order_value' => (float) $row->order_value,
            'net_value' => (float) $row->net_value,
            'paid_value' => (float) $row->paid_value,
            'average_order' => $count > 0 ? (float) $row->order_value / $count : 0.0,
            'items_sold' => (int) $row->items_sold,
            'customers' => (int) $customers->customers,
            'repeat_customers' => (int) $customers->repeat_customers,
            'repeat_rate' => $customers->customers > 0 ? 100.0 * $customers->repeat_customers / $customers->customers : 0.0,
            'cancelled_orders' => $cancelled,
            'first_order_at' => $row->first_order_at ? CarbonImmutable::parse($row->first_order_at) : null,
            'last_order_at' => $row->last_order_at ? CarbonImmutable::parse($row->last_order_at) : null,
        ];
    }

    /** @param array<string, mixed> $filters */
    public function customers(array $filters): Builder
    {
        return $this->customerAggregate($filters)->orderByDesc('order_value')->orderByDesc('order_count');
    }

    /** @param array<string, mixed> $filters */
    public function products(array $filters): Builder
    {
        $identity = "CASE WHEN order_items.product_id IS NULL THEN COALESCE(NULLIF(TRIM(order_items.sku), ''), NULLIF(TRIM(order_items.code), ''), order_items.name) ELSE NULL END";

        return $this->eligibleOrders($filters)
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->select('order_items.product_id', 'order_items.product_option_value_id')
            ->selectRaw($identity.' AS historical_identity')
            ->selectRaw('MAX(order_items.name) AS name, MAX(order_items.sku) AS sku, MAX(order_items.code) AS code')
            ->selectRaw('SUM(order_items.quantity) AS quantity, COUNT(DISTINCT orders.id) AS order_count')
            // Both checkout and the legacy importer store line totals before tax.
            ->selectRaw('SUM(order_items.line_total) AS net_value')
            ->groupBy('order_items.product_id', 'order_items.product_option_value_id')
            ->groupByRaw($identity)
            ->orderByDesc('quantity')
            ->orderByDesc('net_value');
    }

    /** @param array<string, mixed> $filters */
    public function monthlyTrend(array $filters): Collection
    {
        $date = 'COALESCE(orders.placed_at, orders.created_at)';
        $month = match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m', $date)",
            'pgsql' => "TO_CHAR($date, 'YYYY-MM')",
            default => "DATE_FORMAT($date, '%Y-%m')",
        };

        return $this->eligibleOrders($filters)
            ->selectRaw($month.' AS month, COUNT(*) AS orders, SUM(orders.grand_total) AS order_value')
            ->groupByRaw($month)
            ->orderByDesc('month')
            ->limit(12)
            ->get()
            ->reverse()
            ->values();
    }

    public function forUser(User $user): array
    {
        $currencies = $this->currencies($user->id);

        return [
            'currencies' => $currencies->mapWithKeys(fn (string $currency): array => [
                $currency => $this->summary(['user_id' => $user->id, 'currency' => $currency]),
            ]),
        ];
    }

    /** @param array<string, mixed> $filters */
    private function customerAggregate(array $filters): Builder
    {
        $email = 'CASE WHEN orders.user_id IS NULL THEN LOWER(TRIM(orders.customer_email)) ELSE NULL END';
        $anonymous = "CASE WHEN orders.user_id IS NULL AND TRIM(orders.customer_email) = '' THEN orders.id ELSE NULL END";

        return $this->eligibleOrders($filters)
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->select('orders.user_id')
            ->selectRaw($email.' AS guest_email, '.$anonymous.' AS anonymous_order_id')
            ->selectRaw("MAX(COALESCE(NULLIF(users.name, ''), orders.customer_name)) AS name, MAX(COALESCE(NULLIF(users.email, ''), orders.customer_email)) AS email, MAX(orders.billing_company) AS company")
            ->selectRaw('COUNT(*) AS order_count, SUM(orders.grand_total) AS order_value, AVG(orders.grand_total) AS average_order, SUM(orders.item_qty) AS items_sold')
            ->selectRaw('MIN(COALESCE(orders.placed_at, orders.created_at)) AS first_order_at, MAX(COALESCE(orders.placed_at, orders.created_at)) AS last_order_at')
            ->groupBy('orders.user_id')
            ->groupByRaw($email)
            ->groupByRaw($anonymous);
    }

    /** @param array<string, mixed> $filters */
    private function eligibleOrders(array $filters): Builder
    {
        return $this->orders($filters)->whereNot(fn (Builder $query) => $this->whereCancelled($query));
    }

    private function whereCancelled(Builder $query): void
    {
        OrderStatusClassification::applyCancelledQuery($query, 'order_statuses.');
    }

    /** @param array<string, mixed> $filters */
    private function orders(array $filters): Builder
    {
        $query = app(OrderManagerAccess::class)->scopeOrders(DB::table('orders'))
            ->leftJoin('order_statuses', 'order_statuses.id', '=', 'orders.status_id');

        if (! empty($filters['currency'])) {
            $query->whereRaw('UPPER(orders.currency_code) = ?', [strtoupper($filters['currency'])]);
        }
        if (! empty($filters['dateFrom'])) {
            $query->whereRaw('COALESCE(orders.placed_at, orders.created_at) >= ?', [CarbonImmutable::parse($filters['dateFrom'])->startOfDay()->toDateTimeString()]);
        }
        if (! empty($filters['dateTo'])) {
            $query->whereRaw('COALESCE(orders.placed_at, orders.created_at) <= ?', [CarbonImmutable::parse($filters['dateTo'])->endOfDay()->toDateTimeString()]);
        }
        if (! empty($filters['user_id'])) {
            $query->where('orders.user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['guest_email'])) {
            $query->whereNull('orders.user_id')->whereRaw('LOWER(TRIM(orders.customer_email)) = ?', [mb_strtolower(trim($filters['guest_email']))]);
        }
        if (! empty($filters['search'])) {
            $search = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']).'%';
            $query->where(fn (Builder $query) => $query
                ->whereRaw("orders.customer_name LIKE ? ESCAPE '!'", [$search])
                ->orWhereRaw("orders.customer_email LIKE ? ESCAPE '!'", [$search])
                ->orWhereRaw("orders.billing_company LIKE ? ESCAPE '!'", [$search])
                ->orWhereExists(fn (Builder $users) => $users
                    ->selectRaw('1')
                    ->from('users as purchase_customers')
                    ->whereColumn('purchase_customers.id', 'orders.user_id')
                    ->where(fn (Builder $identity) => $identity
                        ->whereRaw("purchase_customers.name LIKE ? ESCAPE '!'", [$search])
                        ->orWhereRaw("purchase_customers.email LIKE ? ESCAPE '!'", [$search]))));
        }

        return $query;
    }
}
