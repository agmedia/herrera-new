<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Front\Concerns\ResolvesFrontendView;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Sales\Order\Order;
use App\Services\Analytics\InvoicedBuyerPurchases;
use App\Models\User\B2BAccount;
use App\Services\Front\B2BQuickOrderFileReader;
use App\Services\Front\B2BQuickOrderSearchService;
use App\Services\Front\CartService;
use App\Services\Front\CartStaffAccess;
use App\Services\Pricing\B2BAccessService;
use App\Services\Pricing\ProductPricePresentationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class B2BController extends Controller
{
    use ResolvesFrontendView;

    public function __construct(
        private readonly CartService $cart,
        private readonly ProductPricePresentationService $prices,
        private readonly B2BQuickOrderSearchService $quickOrderSearch,
    ) {}

    public function quickOrder(Request $request): View
    {
        $account = $this->approvedAccount($request);
        $user = $request->user();

        $initialItems = collect((array) $request->old(
            'items',
            $account->quick_order_draft ?? [],
        ));
        $requestedCode = trim((string) $request->query('code', ''));
        $initialQuickOrderQuery = '';
        if ($requestedCode !== '') {
            $resolved = $this->quickOrderSearch->resolve($requestedCode, $user);
            if (isset($resolved['item'])) {
                $requestedItem = $resolved['item'];
                if (! $initialItems->contains(fn (array $item) => (int) ($item['product_id'] ?? 0) === $requestedItem['product_id']
                    && (int) ($item['product_option_value_id'] ?? 0) === (int) $requestedItem['product_option_value_id'])) {
                    $initialItems->push($requestedItem);
                }
            } else {
                $initialQuickOrderQuery = $requestedCode;
            }
        }

        $initialQuickOrderItems = $initialItems
            ->map(function (array $item) use ($user): ?array {
                $product = null;
                $option = null;
                $productId = (int) ($item['product_id'] ?? 0);
                $optionId = (int) ($item['product_option_value_id'] ?? 0);

                if ($productId > 0) {
                    $product = Product::query()->where('is_active', true)->find($productId);
                    $option = $optionId > 0
                        ? ProductOptionValue::query()
                            ->where('product_id', $productId)
                            ->where('is_active', true)
                            ->find($optionId)
                        : null;
                } else {
                    [$product, $resolvedOptionId] = $this->resolveIdentifier((string) ($item['identifier'] ?? ''));
                    $option = $resolvedOptionId
                        ? ProductOptionValue::query()->find($resolvedOptionId)
                        : null;
                }

                if (! $product || ($optionId > 0 && ! $option) || ! $this->quickOrderSearch->canSelect($product, $option)) {
                    return null;
                }

                return $this->quickOrderSearch->present(
                    $product,
                    $option,
                    $user,
                    max(1, (int) ($item['quantity'] ?? 1)),
                );
            })
            ->filter()
            ->values();

        return view($this->frontendView($request, 'account.b2b-quick-order'), [
            'b2bAccount' => $account,
            'initialQuickOrderItems' => $initialQuickOrderItems,
            'initialQuickOrderQuery' => $initialQuickOrderQuery,
            'unavailableDraftCount' => max(0, $initialItems->count() - $initialQuickOrderItems->count()),
            'quickOrderSuggestions' => $this->quickOrderSearch->suggestions($user),
        ]);
    }

    public function frequentProducts(Request $request, InvoicedBuyerPurchases $invoices): View
    {
        $account = $this->approvedAccount($request);
        $user = $request->user();
        $snapshot = $invoices->latestSnapshot();
        $rows = $snapshot ? $invoices->matching($account, $snapshot) : null;
        $purchases = $rows?->select('product_id')->selectRaw('SUM(quantity_365) AS purchased_qty')
            ->groupBy('product_id')->havingRaw('SUM(quantity_365) > 0')
            ->orderByDesc('purchased_qty')->orderBy('product_id')->paginate(40)->withQueryString();
        $quantities = $purchases?->getCollection()->pluck('purchased_qty', 'product_id') ?? collect();

        return view($this->frontendView($request, 'account.b2b-product-list'), [
            'b2bAccount' => $account,
            'current' => 'b2b_frequent_products',
            'title' => __('Često naručivani artikli'),
            'subtitle' => $snapshot
                ? __('Fakturirane količine iz e-Računa od :od do :do, uz obračun storna i odobrenja.', [
                    'od' => \Illuminate\Support\Carbon::parse($snapshot->coverage_from)->format('d.m.Y.'),
                    'do' => \Illuminate\Support\Carbon::parse($snapshot->as_of)->format('d.m.Y.'),
                ])
                : __('Fakturirane količine u zadnjih 12 mjeseci prema podacima iz e-Računa.'),
            'emptyMessage' => ! $snapshot ? __('Čekamo prvi dovršeni prijenos iz e-Računa.')
                : ($rows ? __('Za ovo razdoblje nema povezanih artikala s pozitivnom fakturiranom količinom.') : __('Za prikaz kupnje potrebno je povezati B2B račun s kupcem u e-Računima.')),
            'products' => $this->productSuggestions($quantities->keys()->all(), $user, true),
            'quantities' => $quantities, 'purchases' => $purchases,
        ]);
    }

    public function favoriteProducts(Request $request): View
    {
        $account = $this->approvedAccount($request);
        $user = $request->user();
        $productIds = $user->wishlistItems()
            ->latest('id')
            ->limit(12)
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return view($this->frontendView($request, 'account.b2b-product-list'), [
            'b2bAccount' => $account,
            'current' => 'b2b_favorite_products',
            'title' => __('Favoriti'),
            'subtitle' => __('Artikli spremljeni na listu želja.'),
            'products' => $this->productSuggestions($productIds, $user),
        ]);
    }

    public function searchQuickOrder(Request $request): JsonResponse
    {
        $this->approvedAccount($request);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($validated['q'] ?? ''));
        $items = $this->quickOrderSearch->search($search, $request->user());

        return response()->json([
            'query' => $search,
            'items' => $items->all(),
        ]);
    }

    public function resolveQuickOrder(Request $request): JsonResponse
    {
        $this->approvedAccount($request);
        $validated = $request->validate([
            'lines' => ['required_without:order_file', 'prohibits:order_file', 'nullable', 'string', 'max:20000'],
            'order_file' => ['required_without:lines', 'prohibits:lines', 'nullable', 'file', 'max:2048', 'mimes:csv,txt,xlsx', 'extensions:csv,xlsx'],
        ]);
        if ($request->hasFile('order_file')) {
            $rows = app(B2BQuickOrderFileReader::class)->read($request->file('order_file'));
        } else {
            $lines = array_values(array_filter(preg_split('/\R/u', $validated['lines']), fn ($line) => trim($line) !== ''));
            if (count($lines) > 100) {
                throw ValidationException::withMessages(['lines' => __('Unesite najviše 100 redaka odjednom.')]);
            }
            $rows = array_map(fn ($line, $index) => ['line' => $index + 1, 'parts' => preg_split('/[;\t,]/', trim($line))], $lines, array_keys($lines));
        }

        $items = [];
        $errors = [];
        $warnings = [];
        foreach ($rows as $row) {
            $parts = $row['parts'];
            $lineNumber = $row['line'];
            $identifier = trim($parts[0] ?? '');
            $quantityText = trim($parts[1] ?? '1');
            if ($identifier === '' || mb_strlen($identifier) > 191 || count($parts) > 2 || ! preg_match('/^[0-9]{1,3}$/', $quantityText) || (int) $quantityText < 1) {
                $errors[] = ['line' => $lineNumber, 'identifier' => $identifier, 'quantity' => $quantityText, 'message' => __('Koristite format šifra; količina, s količinom od 1 do 999.')];

                continue;
            }

            $resolved = $this->quickOrderSearch->resolve($identifier, $request->user(), (int) $quantityText);
            if (isset($resolved['error'])) {
                $errors[] = ['line' => $lineNumber, 'identifier' => $identifier, 'quantity' => $quantityText, 'message' => $resolved['error']];

                continue;
            }
            $item = $resolved['item'];
            if (isset($items[$item['key']])) {
                $combined = $items[$item['key']]['quantity'] + $item['quantity'];
                $combinedResolved = $this->quickOrderSearch->resolve($identifier, $request->user(), $combined);
                $item = $combinedResolved['item'];
                $resolved['warning'] = $combinedResolved['warning'];
                if ($combined > $item['maximum_quantity']) {
                    $resolved['warning'] = app(CartStaffAccess::class)->canViewStock($request)
                        ? __('Ukupna količina ograničena je raspoloživom zalihom: :quantity.', ['quantity' => $item['quantity']])
                        : __('stock.order_quantity_adjusted');
                }
            }
            $items[$item['key']] = $item;
            if ($resolved['warning']) {
                $warnings[] = ['line' => $lineNumber, 'identifier' => $identifier, 'message' => $resolved['warning']];
            }
        }

        return response()->json(['items' => array_values($items), 'errors' => $errors, 'warnings' => $warnings]);
    }

    public function syncQuickOrder(Request $request): JsonResponse
    {
        $account = $this->approvedAccount($request);

        $validated = $request->validate([
            'items' => ['present', 'array', 'max:100'],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            'items.*.product_option_value_id' => [
                'nullable',
                'integer',
                Rule::exists('catalog_product_option_values', 'id')->where('is_active', true),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $items = collect($validated['items'])
            ->map(static fn (array $item): array => [
                'product_id' => (int) $item['product_id'],
                'product_option_value_id' => (int) ($item['product_option_value_id'] ?? 0),
                'quantity' => (int) $item['quantity'],
            ]);

        $optionProductIds = ProductOptionValue::query()
            ->whereIn(
                'id',
                $items->pluck('product_option_value_id')->filter()->unique()->all(),
            )
            ->pluck('product_id', 'id');

        $items = $items
            ->filter(static function (array $item) use ($optionProductIds): bool {
                $optionId = $item['product_option_value_id'];

                return $optionId === 0
                    || (int) $optionProductIds->get($optionId) === $item['product_id'];
            })
            ->unique(static fn (array $item): string => $item['product_id'].':'.$item['product_option_value_id'])
            ->map(static fn (array $item): array => [
                ...$item,
                'product_option_value_id' => $item['product_option_value_id'] ?: null,
            ])
            ->values();

        $account->update([
            'quick_order_draft' => $items->isEmpty() ? null : $items->all(),
        ]);

        return response()->json([
            'saved' => true,
            'count' => $items->count(),
        ]);
    }

    public function storeQuickOrder(Request $request): RedirectResponse
    {
        $account = $this->approvedAccount($request);

        $validated = $request->validate([
            'items' => ['required', 'array', 'max:100'],
            'items.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'items.*.product_option_value_id' => ['nullable', 'integer', Rule::exists('catalog_product_option_values', 'id')],
            'items.*.identifier' => ['nullable', 'string', 'max:191'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        $items = collect($validated['items'])
            ->map(static fn (array $item): array => [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'product_option_value_id' => (int) ($item['product_option_value_id'] ?? 0),
                'identifier' => trim((string) ($item['identifier'] ?? '')),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            ])
            ->filter(static fn (array $item): bool => $item['product_id'] > 0 || $item['identifier'] !== '')
            ->values();

        if ($items->isEmpty()) {
            return back()
                ->withErrors(['items' => __('Unesite barem jednu šifru, SKU ili barkod.')])
                ->withInput();
        }

        $added = 0;
        $skipped = [];
        $remainingItems = [];

        foreach ($items as $item) {
            if ($item['product_id'] > 0) {
                $product = Product::query()
                    ->where('is_active', true)
                    ->find($item['product_id']);
                $optionValueId = $item['product_option_value_id'] > 0
                    ? $item['product_option_value_id']
                    : null;

                if ($optionValueId !== null) {
                    $validOption = ProductOptionValue::query()
                        ->where('id', $optionValueId)
                        ->where('product_id', $item['product_id'])
                        ->where('is_active', true)
                        ->exists();

                    if (! $validOption) {
                        $product = null;
                    }
                }
            } else {
                [$product, $optionValueId] = $this->resolveIdentifier($item['identifier']);
            }

            if (! $product) {
                $remainingItems[] = $item;
                $skipped[] = ($item['identifier'] ?: '#'.$item['product_id']).' — '.__('artikl nije pronađen');

                continue;
            }

            if (! $this->cart->add($product, $item['quantity'], $optionValueId)) {
                $remainingItems[] = $item;
                $skipped[] = ($item['identifier'] ?: $product->code).' — '.__('nije dostupan ili zahtijeva odabir varijante');

                continue;
            }

            $added++;
        }

        if ($added === 0) {
            return back()
                ->with('warning', __('Nijedan artikl nije dodan.').' '.implode('; ', $skipped))
                ->withInput();
        }

        $account->update(['quick_order_draft' => $remainingItems === [] ? null : $remainingItems]);

        $response = redirect()
            ->route('cart.index')
            ->with('status', trans_choice(
                ':count stavka dodana je u košaricu.|:count stavke dodane su u košaricu.|:count stavki dodano je u košaricu.',
                $added,
                ['count' => $added],
            ));

        if ($skipped !== []) {
            $response->with('warning', __('Preskočeno:').' '.implode('; ', $skipped));
        }

        return $response;
    }

    public function reorder(Request $request, string $orderNumber): RedirectResponse
    {
        $this->approvedAccount($request);

        $order = Order::query()
            ->where('user_id', $request->user()->getKey())
            ->where('order_number', $orderNumber)
            ->with([
                'items.product',
                'items.productOptionValue',
            ])
            ->firstOrFail();

        $added = 0;
        $skipped = [];

        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product || ! $product->is_active) {
                $skipped[] = $item->name;

                continue;
            }

            $optionValueId = $item->productOptionValue?->is_active
                ? (int) $item->product_option_value_id
                : null;

            if (! $this->cart->add($product, max(1, (int) $item->quantity), $optionValueId)) {
                $skipped[] = $item->name;

                continue;
            }

            $added++;
        }

        if ($added === 0) {
            return back()->with('warning', __('Artikle iz ove narudžbe trenutno nije moguće ponovno dodati.'));
        }

        $response = redirect()
            ->route('cart.index')
            ->with('status', __('Dostupni artikli iz narudžbe :number dodani su u košaricu.', ['number' => $order->order_number]));

        if ($skipped !== []) {
            $response->with('warning', __('Nedostupni artikli su preskočeni:').' '.implode(', ', $skipped));
        }

        return $response;
    }

    private function approvedAccount(Request $request): B2BAccount
    {
        if (app(B2BAccessService::class)->requiresApprovedAccount()) {
            $account = app(B2BAccessService::class)->approvedAccount($request->user());
            abort_unless($account, 403, __('ui.b2b.pricing.access_required'));

            return $account;
        }

        $request->user()->loadMissing('b2bAccount');
        $account = $request->user()->b2bAccount;

        abort_unless($account && $account->contractIsActive(), 403, __('B2B pristup nije aktivan.'));

        return $account;
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, array{product:Product,name:string,identifier:string,price:array<string,mixed>}>
     */
    private function productSuggestions(array $ids, $user, bool $includeInactive = false): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $locale = (string) app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $positions = array_flip($ids);

        return Product::query()
            ->whereIn('id', $ids)
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->with([
                'taxRate', 'media',
                'categories:id',
                'packages',
                'translations' => fn ($query) => $query->whereIn('locale', [$locale, $fallbackLocale]),
            ])
            ->get()
            ->sortBy(static fn (Product $product): int => $positions[(int) $product->getKey()] ?? 9999)
            ->map(function (Product $product) use ($locale, $fallbackLocale, $user): array {
                $translation = $product->translations->firstWhere('locale', $locale)
                    ?? $product->translations->firstWhere('locale', $fallbackLocale)
                    ?? $product->translations->first();

                return [
                    'product' => $product,
                    'name' => (string) ($translation?->name ?: $product->code),
                    'identifier' => (string) ($product->sku ?: $product->code),
                    'barcode' => trim((string) $product->barcode) ?: trim((string) data_get($product->payload, 'opencart.ean')),
                    'price' => $this->prices->forProduct($product, $user),
                ];
            })
            ->values();
    }

    /**
     * @return array{0:Product|null,1:int|null}
     */
    private function resolveIdentifier(string $identifier): array
    {
        $identifier = trim($identifier);

        $product = Product::query()
            ->where('is_active', true)
            ->where(function ($query) use ($identifier): void {
                $query
                    ->where('code', $identifier)
                    ->orWhere('sku', $identifier)
                    ->orWhere('barcode', $identifier);
            })
            ->first();

        if ($product) {
            return [$product, null];
        }

        $option = ProductOptionValue::query()
            ->where('is_active', true)
            ->where('sku', $identifier)
            ->with('product')
            ->first();

        if ($option?->product?->is_active) {
            return [$option->product, (int) $option->getKey()];
        }

        return [null, null];
    }
}
