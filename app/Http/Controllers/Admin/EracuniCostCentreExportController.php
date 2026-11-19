<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Sales\Order\Order;
use App\Services\Admin\OrderManagerAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EracuniCostCentreExportController extends Controller
{
    /** Accounting reconciliation between existing ERP documents and webshop orders. */
    public function __invoke(Request $request, OrderManagerAccess $access): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor->isA('superadmin') || ($actor->can('integrations.eracuni.manage') && $actor->can('sales.orders.view')), 403);
        $filters = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $orders = $access->scopeOrders(Order::query(), $actor)->where('customer_type', 'B2C')->whereNotNull('erp_cost_centre')
            ->whereBetween(DB::raw('COALESCE(orders.placed_at, orders.created_at)'), [
                CarbonImmutable::parse($filters['from'])->startOfDay(), CarbonImmutable::parse($filters['to'])->endOfDay(),
            ])->with('status:id,code');

        return response()->streamDownload(function () use ($orders) {
            $file = fopen('php://output', 'wb');
            fputcsv($file, ['web_order_number', 'erp_order_id', 'erp_cost_centre', 'order_date', 'currency', 'net_total', 'status'], ',', '"', '');
            foreach ($orders->lazyById(500) as $order) {
                $row = [$order->order_number, $order->erp_order_id, $order->erp_cost_centre,
                    ($order->placed_at ?? $order->created_at)?->toDateString(), $order->currency_code,
                    number_format($order->grand_total - $order->tax_total, 2, '.', ''), $order->status?->code];
                $row = array_map(static fn ($value) => preg_match('/^\s*[=+@\-]/', (string) $value) ? "'".$value : $value, $row);
                fputcsv($file, $row, ',', '"', '');
            }
            fclose($file);
        }, 'b2c-mjesta-troska-'.$filters['from'].'-'.$filters['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
