<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Analytics\CustomerPurchaseStatistics;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerStatisticsController extends Controller
{
    public function __invoke(Request $request, CustomerPurchaseStatistics $statistics): View
    {
        $admin = $request->user();
        abort_unless($admin && ($admin->isA('superadmin') || ($admin->can('users.list.view') && $admin->can('sales.orders.view'))), 403);

        $currencies = $statistics->currencies();
        $defaultCurrency = strtoupper((string) app(SystemSettingsService::class)->get('store_schema_product_currency', 'EUR'));
        if (! $currencies->contains($defaultCurrency)) {
            $currencies->prepend($defaultCurrency);
        }

        $validated = $request->validate([
            'dateFrom' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('dateTo'), 'before_or_equal:dateTo')],
            'dateTo' => ['nullable', 'date_format:Y-m-d'],
            'currency' => ['nullable', 'string', Rule::in($currencies->all())],
            'search' => ['nullable', 'string', 'max:191'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'guest_email' => ['nullable', 'string', 'max:191'],
        ]);
        if (! empty($validated['user_id'])) {
            app(OrderManagerAccess::class)->assertCustomer(User::query()->findOrFail($validated['user_id']), $admin);
        }
        if (empty($validated['currency']) && (! empty($validated['user_id']) || ! empty($validated['guest_email']))) {
            $customerCurrencies = $statistics->currencies(
                isset($validated['user_id']) ? (int) $validated['user_id'] : null,
                $validated['guest_email'] ?? null,
            );
            if ($customerCurrencies->isNotEmpty() && ! $customerCurrencies->contains($defaultCurrency)) {
                $defaultCurrency = $customerCurrencies->first();
            }
        }
        $filters = [
            'dateFrom' => $request->exists('dateFrom') ? ($validated['dateFrom'] ?? '') : now()->startOfYear()->toDateString(),
            'dateTo' => $request->exists('dateTo') ? ($validated['dateTo'] ?? '') : now()->toDateString(),
            'currency' => $validated['currency'] ?? $defaultCurrency,
            'search' => trim($validated['search'] ?? ''),
            'user_id' => isset($validated['user_id']) ? (int) $validated['user_id'] : null,
            'guest_email' => trim($validated['guest_email'] ?? ''),
        ];
        $selectedCustomer = $filters['user_id'] ? User::query()->findOrFail($filters['user_id']) : null;

        return view('admin.users.statistics', [
            'filters' => $filters,
            'currencies' => $currencies,
            'selectedCustomer' => $selectedCustomer,
            'summary' => $statistics->summary($filters),
            'customers' => $statistics->customers($filters)->paginate(15, ['*'], 'customers_page')->withQueryString(),
            'products' => $statistics->products($filters)->paginate(15, ['*'], 'products_page')->withQueryString(),
            'trend' => $statistics->monthlyTrend($filters),
        ]);
    }
}
