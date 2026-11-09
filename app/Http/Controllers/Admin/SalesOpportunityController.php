<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Integrations\SyncSalesOpportunities;
use App\Models\Catalog\Category\Category;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Analytics\SalesOpportunities;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SalesOpportunityController extends Controller
{
    public function index(Request $request, SalesOpportunities $opportunities): View
    {
        $opportunities->authorize($request->user());
        $validated = $request->validate(['user_id' => 'nullable|integer|exists:users,id', 'category_id' => 'nullable|integer|exists:categories,id']);
        $selectedCustomer = isset($validated['user_id']) ? User::query()->with('b2bAccount')->findOrFail($validated['user_id']) : null;
        if ($selectedCustomer) {
            app(OrderManagerAccess::class)->assertCustomer($selectedCustomer, $request->user());
            abort_unless($selectedCustomer->b2bAccount?->status === B2BAccount::STATUS_APPROVED, 422);
        }
        $snapshot = DB::table('sales_opportunity_snapshots')->where('status', 'completed')->latest('id')->first();
        $latestAttempt = DB::table('sales_opportunity_snapshots')->latest('id')->first();

        return view('admin.users.opportunities', [
            'customers' => app(OrderManagerAccess::class)->scopeCustomers(User::query(), $request->user())
                ->where('account_type', 'customer')->whereHas('b2bAccount', fn ($query) => $query->where('status', B2BAccount::STATUS_APPROVED))
                ->with('b2bAccount')->orderBy('name')->limit(1000)->get(),
            'selectedCustomer' => $selectedCustomer, 'snapshot' => $snapshot, 'latestAttempt' => $latestAttempt,
            'categories' => Category::query()->where('scope', 'catalog')->with('translations')->defaultOrder()->get(),
            'categoryId' => (int) ($validated['category_id'] ?? 0),
            'products' => $snapshot && $selectedCustomer ? $opportunities->products($selectedCustomer, $snapshot, $validated['category_id'] ?? null)->paginate(20)->withQueryString() : null,
        ]);
    }

    public function refresh(Request $request, SalesOpportunities $opportunities): RedirectResponse
    {
        $opportunities->authorize($request->user());
        abort_unless($request->user()->isA('superadmin') || $request->user()->can('integrations.eracuni.manage'), 403);
        abort_unless(app(StockSyncSettingsService::class)->configured('eracuni'), 422, 'Postojeća ERP veza nije konfigurirana.');
        SyncSalesOpportunities::dispatch();

        return redirect()->route('admin.users.opportunities')->with('status', 'Potpuni ERP pregled prodaje pokrenut je u pozadini. Osvježite stranicu nakon dovršetka.');
    }
}
