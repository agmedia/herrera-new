<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sales\Order\Order;
use App\Models\User;
use App\Models\User\CustomerSalesSession;
use App\Models\User\CustomerVisit;
use App\Models\User\UserTrackingEvent;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Analytics\SalesOpportunities;
use App\Services\User\CustomerManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalesActivityReportController extends Controller
{
    public function __invoke(Request $request, OrderManagerAccess $access, SalesOpportunities $opportunities): View
    {
        $actor = $request->user();
        $opportunities->authorize($actor);
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['sales', 'sessions', 'visits', 'products', 'logins'])],
            'scope' => ['nullable', Rule::in(['actor', 'portfolio'])],
            'channel' => ['nullable', Rule::in(['B2B', 'B2C', 'all'])],
            'customer' => ['nullable', 'integer'], 'rep' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $canSeeSales = $actor->isA('superadmin') || $actor->can('sales.orders.view');
        $canSeeLogins = $actor->isA('superadmin') || $actor->can('users.activity.view');
        $tab = $filters['tab'] ?? ($canSeeSales ? 'sales' : 'sessions');
        $from = CarbonImmutable::parse($filters['from'] ?? today()->startOfMonth())->startOfDay();
        $to = CarbonImmutable::parse($filters['to'] ?? today())->endOfDay();
        abort_if($to->lt($from), 422, 'Završetak razdoblja prethodi početku.');
        $restricted = $access->isRestricted($actor);
        $rep = $restricted ? $actor->id : ($filters['rep'] ?? null);
        $customer = $filters['customer'] ?? null;
        $channel = $filters['channel'] ?? 'B2B';
        $portfolio = ($filters['scope'] ?? 'actor') === 'portfolio' && $tab === 'sales';
        $customers = $access->scopeCustomers(User::query(), $actor)->where('account_type', 'customer');
        if ($customer) {
            abort_unless((clone $customers)->whereKey($customer)->exists(), 403);
        }
        $identityRelations = ['b2bAccount:id,user_id,company_name,city', 'profile:id,user_id,company',
            'addresses' => fn ($query) => $query->where('type', 'billing')->select('id', 'user_id', 'type', 'company')];
        $customerIds = (clone $customers)->when($customer, fn ($q) => $q->whereKey($customer))->select('users.id');
        $totals = collect();
        $names = collect();
        if ($tab === 'sales') {
            abort_unless($canSeeSales, 403);
            $representative = $portfolio ? 'NULL' : 'COALESCE(sales_session.sales_rep_id, audited_rep.id)';
            $orders = Order::query()->withoutCancelled()
                ->leftJoin('customer_sales_sessions as sales_session', 'sales_session.id', '=', 'orders.sales_session_id')
                ->leftJoin('users as audited_rep', function ($join) {
                    $join->on('audited_rep.id', '=', 'orders.created_by')
                        ->whereColumn('orders.payload->customer_impersonation->admin_id', 'audited_rep.id')
                        ->whereColumn('orders.payload->customer_impersonation->customer_id', 'orders.user_id');
                })
                ->whereNull('orders.payload->staff_impersonation->admin_id')
                ->where(function ($buyers) use ($customerIds, $restricted, $customer) {
                    $buyers->whereIn('orders.user_id', $customerIds);
                    if (! $restricted && ! $customer) {
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
                })->when(! $portfolio && $rep, fn ($q) => $q->whereRaw($representative.' = ?', [(int) $rep]));
            $totals = (clone $orders)->toBase()->select('orders.currency_code')->selectRaw('COUNT(*) AS orders_count, SUM(orders.grand_total - orders.tax_total) AS net')
                ->groupBy('orders.currency_code')->orderBy('orders.currency_code')->get();
            $guestIdentity = 'CASE WHEN orders.user_id IS NULL THEN LOWER(TRIM(orders.customer_email)) ELSE NULL END';
            $rows = $orders->toBase()->select('orders.user_id as customer_user_id', 'orders.currency_code', 'orders.customer_type')
                ->selectRaw($representative.' AS sales_rep_id')
                ->selectRaw("$guestIdentity AS guest_email")
                ->selectRaw('COUNT(*) AS orders_count, SUM(orders.grand_total - orders.tax_total) AS net')
                ->groupBy('orders.user_id', 'orders.currency_code', 'orders.customer_type')->groupByRaw($representative)->groupByRaw($guestIdentity)->orderByDesc('net')
                ->orderBy('orders.user_id')->orderBy('sales_rep_id')->orderBy('orders.currency_code')->orderBy('orders.customer_type')->orderBy('guest_email')->paginate(40);
        } elseif ($tab === 'logins') {
            abort_unless($canSeeLogins, 403);
            $staffIds = app(CustomerManagementService::class)->representatives(includeDisabled: true)->select('users.id')
                ->when($rep, fn ($q) => $q->whereKey($rep));
            $loginCustomers = (clone $customerIds)->when($rep, fn ($q) => $q->whereIn('users.id',
                DB::table('admin_customer_assignments')->where('admin_user_id', $rep)->select('customer_user_id')));
            $events = UserTrackingEvent::query()->whereBetween('occurred_at', [$from, $to])
                ->where(function ($q) use ($loginCustomers, $customer, $staffIds) {
                    $q->where(fn ($events) => $events->whereIn('event', ['customer_login', 'customer_registration'])->whereIn('user_id', $loginCustomers));
                    if (! $customer) {
                        $q->orWhere(fn ($events) => $events->where('event', 'staff_login')->whereIn('user_id', $staffIds));
                    }
                });
            $totals = (clone $events)->toBase()->select('event')->selectRaw('COUNT(*) AS total')->groupBy('event')->pluck('total', 'event');
            $rows = $events->with(['user' => fn ($q) => $q->with($identityRelations)])->orderByDesc('occurred_at')->orderByDesc('id')->paginate(40);
        } else {
            [$query, $date] = match ($tab) {
                'sessions' => [CustomerSalesSession::query(), 'started_at'],
                'visits' => [CustomerVisit::query(), 'visited_at'],
                'products' => [DB::table('customer_product_presentations'), 'presented_at'],
            };
            $rows = $query->whereIn('customer_user_id', $customerIds)->whereBetween($date, [$from, $to])
                ->where(fn ($q) => $q->whereNull('performed_by_id')->orWhereColumn('performed_by_id', 'sales_rep_id'))
                ->when($rep, fn ($q) => $q->where('sales_rep_id', $rep))->orderByDesc($date)->orderByDesc('id')->paginate(40);
        }
        $rows->withQueryString();
        if ($tab !== 'logins') {
            $ids = $rows->getCollection()->pluck('customer_user_id')->merge($rows->getCollection()->pluck('sales_rep_id'))->filter()->unique();
            $names = User::with($identityRelations)->when($portfolio, fn ($q) => $q->with(['salesRepresentatives' => fn ($reps) => $reps
                ->when($rep, fn ($assigned) => $assigned->where('users.id', $rep))->orderBy('users.name')]))->whereKey($ids)->get()->keyBy('id');
        }

        return view('admin.users.sales-activity-report', [
            'tab' => $tab, 'channel' => $channel, 'portfolio' => $portfolio, 'from' => $from, 'to' => $to, 'customer' => $customer, 'rep' => $rep,
            'rows' => $rows, 'totals' => $totals, 'names' => $names, 'canSeeSales' => $canSeeSales, 'canSeeLogins' => $canSeeLogins,
            'customers' => $customers->with($identityRelations)->orderBy('name')->get(['users.id', 'users.name', 'users.account_type']),
            'reps' => app(CustomerManagementService::class)->representatives(includeDisabled: true)->when($restricted, fn ($q) => $q->whereKey($actor->id))->orderBy('name')->get(),
        ]);
    }
}
