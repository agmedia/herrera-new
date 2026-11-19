<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Analytics\CustomerPurchaseStatistics;
use App\Services\Analytics\RepresentativeStatistics;
use App\Services\Analytics\SalesOpportunities;
use App\Services\Settings\SystemSettingsService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RepresentativeStatisticsController extends Controller
{
    public function __invoke(Request $request, OrderManagerAccess $access): View|BinaryFileResponse
    {
        $actor = $request->user();
        app(SalesOpportunities::class)->authorize($actor);
        $export = $request->routeIs('admin.users.rep-statistics.export');
        $input = $request->validate([
            'rep' => [$export ? 'required' : 'nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:191'], 'buyer' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date_format:Y-m'], 'to' => ['nullable', 'date_format:Y-m'],
            'channel' => ['nullable', Rule::in(['all', 'B2B', 'B2C'])], 'currency' => ['nullable', 'string', 'size:3'],
            'section' => ['nullable', 'string', 'max:20'], 'chart' => ['nullable', Rule::in(['sales', 'activity'])],
        ]);
        $reps = User::query()->where('account_type', 'staff')->whereIs('order_manager')
            ->when($access->isRestricted($actor), fn ($q) => $q->whereKey($actor->id));
        if (empty($input['rep'])) {
            $search = trim($input['q'] ?? '');
            $list = $reps->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereLike('name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%')))
                ->orderBy('name')->orderBy('id')->paginate(18)->withQueryString();

            return view('admin.users.representative-picker', ['reps' => $list, 'search' => $search]);
        }
        $rep = $reps->whereKey($input['rep'])->firstOrFail();
        $today = CarbonImmutable::today();
        $from = CarbonImmutable::createFromFormat('!Y-m', $input['from'] ?? $today->subMonthsNoOverflow(11)->format('Y-m'));
        $to = CarbonImmutable::createFromFormat('!Y-m', $input['to'] ?? $today->format('Y-m'))->endOfMonth();
        if ($from->gt($to)) {
            throw ValidationException::withMessages(['from' => 'Početni mjesec mora prethoditi završnom mjesecu ili biti isti.']);
        }
        $currency = strtoupper((string) app(SystemSettingsService::class)->get('store_schema_product_currency', 'EUR'));
        $currencies = collect();
        if ($actor->isA('superadmin') || $actor->can('sales.orders.view')) {
            $currencies = app(CustomerPurchaseStatistics::class)->currencies()->push($currency)->unique()->values();
            $currency = $input['currency'] ?? $currency;
            abort_unless($currencies->containsStrict($currency), 422);
        }
        $assigned = RepresentativeStatistics::assignedCustomers($actor, $rep);
        $identity = ['b2bAccount:id,user_id,company_name', 'profile:id,user_id,company', 'addresses' => fn ($q) => $q->where('type', 'billing')->select('id', 'user_id', 'type', 'company')];
        $buyer = isset($input['buyer']) ? (clone $assigned)->with($identity)->whereKey($input['buyer'])->firstOrFail() : null;
        $report = new RepresentativeStatistics($actor, $rep, $from, $to, $currency, $input['channel'] ?? 'all', $buyer);
        $overview = $report->overview();
        if ($export) {
            $filename = sprintf('komercijalist-%d_%s_%s.xlsx', $rep->id, $from->format('Y-m'), $to->format('Y-m'));

            return response()->download($report->workbook($overview), $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])->deleteFileAfterSend(true);
        }
        $sections = $report->sections();
        $section = $input['section'] ?? array_key_first($sections);
        abort_unless(isset($sections[$section]), 403);
        $page = $report->detailQuery($section)->paginate(20)->withQueryString()->fragment('detalji');
        $rows = $report->rows($section, $page->getCollection());
        $filters = ['rep' => $rep->id, 'from' => $from->format('Y-m'), 'to' => $to->format('Y-m')];
        if ($report->sales) {
            $filters += ['currency' => $currency, 'channel' => $report->channel];
        }
        if ($buyer) {
            $filters['buyer'] = $buyer->id;
        }
        $buyers = $assigned->with($identity)->get(['users.id', 'users.name', 'users.account_type'])
            ->map(fn ($user) => ['id' => $user->id, 'name' => $user->customerDisplayName()])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE);
        $chart = $report->sales ? ($input['chart'] ?? 'sales') : 'activity';

        return view('admin.users.representative-statistics', compact('report', 'overview', 'sections', 'section', 'page', 'rows', 'filters', 'currencies', 'chart', 'buyers'));
    }
}
