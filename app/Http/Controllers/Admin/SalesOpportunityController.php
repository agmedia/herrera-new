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
use App\Services\Front\CartService;
use App\Services\Integrations\Stock\StockSyncSettingsService;
use App\Services\Pricing\B2BAccessService;
use App\Services\User\CustomerCrmService;
use App\Services\User\CustomerImpersonationService;
use App\Services\User\CustomerManagementService;
use App\Services\User\DeviceLocation;
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
                ->with('b2bAccount:id,user_id,company_name,city')->orderBy('name')->get(),
            'canPresent' => app(CustomerManagementService::class)->canRecordContact($request->user()),
            'canOrder' => $selectedCustomer && app(CustomerImpersonationService::class)->canStart($request->user(), $selectedCustomer),
            'selectedCustomer' => $selectedCustomer, 'snapshot' => $snapshot, 'latestAttempt' => $latestAttempt,
            'categories' => Category::query()->where('scope', 'catalog')->with('translations')->defaultOrder()->get(),
            'categoryId' => (int) ($validated['category_id'] ?? 0),
            'perPage' => $perPage, 'pageSizes' => self::PAGE_SIZES,
            'products' => $snapshot && $selectedCustomer ? $opportunities->products($selectedCustomer, $snapshot, $validated['category_id'] ?? null, (bool) ($validated['unpresented'] ?? false))
                ->with(['optionValues.optionValue.option', 'optionValues.optionValue.translations', 'optionValues.parentOptionValue.option', 'media' => fn ($query) => $query->whereIn('collection_name', ['product_main', 'product_gallery'])])
                ->paginate($perPage)->withQueryString() : null,
        ]);
    }

    public function workWithProducts(Request $request, SalesOpportunities $opportunities): RedirectResponse
    {
        [$filters, $customer, $snapshot] = $this->selection($request, $opportunities, true);
        abort_unless($snapshot, 422);
        $data = $request->validate([
            'selection' => ['required', 'array', 'min:1', 'max:200'], 'selection.*' => ['required', 'integer', 'distinct'],
            'quantities' => ['nullable', 'array', 'max:200'], 'quantities.*' => ['nullable', 'integer', 'between:1,999'],
            'variants' => ['nullable', 'array', 'max:200'], 'variants.*' => ['nullable', 'integer'],
            'operation' => ['required', Rule::in(['cart', 'presented'])], 'batch' => ['required', 'uuid'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $actor = $request->user();
        $products = $opportunities->products($customer, $snapshot, $filters['category_id'] ?? null, (bool) ($filters['unpresented'] ?? false))
            ->whereIntegerInRaw('products.id', $data['selection'])->with(['translations', 'optionValues.optionValue.option', 'optionValues.parentOptionValue.option'])->get();
        if ($products->count() !== count($data['selection'])) {
            return back()->withErrors(['selection' => 'Odabir se promijenio. Osvježite prikaz prodajnih prilika.']);
        }
        if ($data['operation'] === 'presented') {
            app(CustomerCrmService::class)->recordPresentations($customer, $actor, $products, $data['batch'], $data['note'] ?? null, now(), DeviceLocation::from($request->input('location')));

            return back()->with('status', 'Predstavljeni artikli spremljeni su u izvještaj kupca i komercijalista.');
        }

        $session = app(CustomerImpersonationService::class);
        abort_unless($session->canStart($actor, $customer), 403);
        app(B2BAccessService::class)->ensureCanPurchase($customer);
        $items = [];
        foreach ($products as $product) {
            $minimum = max(1, (int) $product->minimum_order_quantity);
            $quantity = (int) ($data['quantities'][$product->id] ?? $minimum);
            $variantId = (int) ($data['variants'][$product->id] ?? 0);
            $variant = $variantId ? $product->availableOptionRows()->firstWhere('id', $variantId) : null;
            $stock = $variant ? $variant->stock_qty : $product->availableStockQuantity();
            if (($product->hasVisibleOptionRows() && ! $variant) || ($variantId && ! $variant) || $quantity > $stock
                || $quantity < $minimum || ($quantity - $minimum) % max(1, (int) $product->order_quantity_step) !== 0) {
                return back()->withErrors(['selection' => 'Provjerite varijantu, količinu i raspoloživost artikla '.($product->sku ?: $product->code).'.'])->withInput();
            }
            $items[] = [$product, $quantity, $variant?->id];
        }
        $session->start($request, $customer);
        $cart = app(CartService::class);
        foreach ($items as [$product, $quantity, $variantId]) {
            if (! $cart->add($product, $quantity, $variantId, exact: true)) {
                $session->stop($request);

                return redirect()->route('admin.users.opportunities', $filters)->withErrors(['selection' => 'Raspoloživost se promijenila. Ponovite odabir.']);
            }
        }

        return redirect()->route('cart.index')->with('status', 'Odabrani artikli dodani su u košaricu kupca.');
    }

    public function export(Request $request, SalesOpportunities $opportunities, SalesOpportunityExport $export): BinaryFileResponse
    {
        [$validated, $customer, $snapshot] = $this->selection($request, $opportunities, true);
        abort_unless($snapshot, 422, 'Najprije dovršite potpuni ERP pregled prodaje.');
        $path = $export->create($opportunities->products($customer, $snapshot, $validated['category_id'] ?? null, (bool) ($validated['unpresented'] ?? false)));

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
            'unpresented' => ['nullable', 'boolean'],
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
