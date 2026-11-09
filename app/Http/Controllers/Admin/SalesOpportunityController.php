<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Integrations\SyncSalesOpportunities;
use App\Models\Catalog\Category\Category;
use App\Models\User;
use App\Models\User\B2BAccount;
use App\Services\Admin\OrderManagerAccess;
use App\Services\Analytics\SalesOpportunities;
use App\Services\Analytics\SalesOpportunityExport;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesOpportunityController extends Controller
{
    public const PAGE_SIZES = [20, 50, 100, 200];

    public function index(Request $request, SalesOpportunities $opportunities): View
    {
        [$validated, $selectedCustomer, $snapshot] = $this->selection($request, $opportunities);
        $latestAttempt = DB::table('sales_opportunity_snapshots')->latest('id')->first();
        $perPage = (int) ($validated['per_page'] ?? 20);

        return view('admin.users.opportunities', [
            'customers' => app(OrderManagerAccess::class)->scopeCustomers(User::query()->select(['users.id', 'users.name', 'users.account_type']), $request->user())
                ->where('account_type', 'customer')->whereHas('b2bAccount', fn ($query) => $query->where('status', B2BAccount::STATUS_APPROVED))
                ->with('b2bAccount:id,user_id,company_name')->orderBy('name')->get(),
            'selectedCustomer' => $selectedCustomer, 'snapshot' => $snapshot, 'latestAttempt' => $latestAttempt,
            'categories' => Category::query()->where('scope', 'catalog')->with('translations')->defaultOrder()->get(),
            'categoryId' => (int) ($validated['category_id'] ?? 0),
            'perPage' => $perPage, 'pageSizes' => self::PAGE_SIZES,
            'products' => $snapshot && $selectedCustomer ? $opportunities->products($selectedCustomer, $snapshot, $validated['category_id'] ?? null)
                ->with(['media' => fn ($query) => $query->whereIn('collection_name', ['product_main', 'product_gallery'])])
                ->paginate($perPage)->withQueryString() : null,
        ]);
    }

    public function export(Request $request, SalesOpportunities $opportunities, SalesOpportunityExport $export): BinaryFileResponse
    {
        [$validated, $customer, $snapshot] = $this->selection($request, $opportunities, true);
        abort_unless($snapshot, 422, 'Najprije dovršite potpuni ERP pregled prodaje.');
        $path = $export->create($opportunities->products($customer, $snapshot, $validated['category_id'] ?? null));

        return response()->download($path, 'prodajne-prilike-kupac-'.$customer->id.'-'.$snapshot->as_of.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, max-age=0',
        ])->deleteFileAfterSend(true);
    }

    private function selection(Request $request, SalesOpportunities $opportunities, bool $requireCustomer = false): array
    {
        $opportunities->authorize($request->user());
        $validated = $request->validate([
            'user_id' => [$requireCustomer ? 'required' : 'nullable', 'integer', 'exists:users,id'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('scope', 'catalog')],
            'per_page' => ['nullable', 'integer', Rule::in(self::PAGE_SIZES)],
        ]);
        $customer = isset($validated['user_id']) ? User::query()->with('b2bAccount')->findOrFail($validated['user_id']) : null;
        if ($customer) {
            app(OrderManagerAccess::class)->assertCustomer($customer, $request->user());
            abort_unless($customer->b2bAccount?->status === B2BAccount::STATUS_APPROVED, 422);
        }

        return [$validated, $customer, DB::table('sales_opportunity_snapshots')->where('status', 'completed')->latest('id')->first()];
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
