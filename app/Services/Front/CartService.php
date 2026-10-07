<?php

namespace App\Services\Front;

use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductOptionValue;
use App\Models\Settings\Local\TaxRate;
use App\Services\Catalog\ActionResolverService;
use App\Services\Pricing\B2BAccessService;
use App\Services\Pricing\ProductGroupPriceResolver;
use App\Services\Pricing\TaxPricingService;
use App\Support\ProductEnergyLabelPresenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

class CartService
{
    private const SESSION_KEY = 'front.cart.items';

    private const COUPON_SESSION_KEY = 'front.cart.coupon_code';

    private const RESOLVED_LINES_ATTRIBUTE = 'front.cart.resolved_lines';

    public function __construct(
        private readonly ActionResolverService $actionResolver,
        private readonly TaxPricingService $taxPricing,
        private readonly ProductGroupPriceResolver $groupPriceResolver,
        private readonly ProductEnergyLabelPresenter $energyLabelPresenter,
    ) {}

    /**
     * @return array<string, array{product_id:int,product_option_value_id:int|null,quantity:int}>
     */
    public function raw(): array
    {
        $items = Session::get(self::SESSION_KEY, []);

        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $key => $item) {
            if (is_array($item) && isset($item['product_id'])) {
                $productId = (int) ($item['product_id'] ?? 0);
                $optionValueId = (int) ($item['product_option_value_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 0);
            } else {
                // Backward compatibility with previous storage format: [product_id => quantity]
                $productId = (int) $key;
                $optionValueId = 0;
                $quantity = (int) $item;
            }

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $resolvedKey = $this->lineKey($productId, $optionValueId > 0 ? $optionValueId : null);
            $normalized[$resolvedKey] = [
                'product_id' => $productId,
                'product_option_value_id' => $optionValueId > 0 ? $optionValueId : null,
                'quantity' => $quantity,
            ];
        }

        return $normalized;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function lines(?string $locale = null, ?string $couponCode = null): Collection
    {
        if (! app(B2BAccessService::class)->canViewPrices(auth()->user())) {
            return collect();
        }

        $items = $this->raw();

        if ($items === []) {
            return collect();
        }

        $locale = $locale ?: app()->getLocale();
        $fallbackLocale = (string) config('app.locale');
        $couponCode = $couponCode === null ? $this->couponCode() : strtoupper(trim($couponCode));
        $user = auth()->user();

        $request = app('request');
        $account = $user?->getRelations()['b2bAccount'] ?? null;
        $group = $account?->getRelations()['customerGroup'] ?? null;
        $snapshotKey = hash('sha256', serialize([
            $items, $locale, $fallbackLocale, $couponCode,
            $user?->getAttributes(),
            $account?->getAttributes(), $group?->getAttributes(),
            $user?->relationLoaded('customerGroups') ? $user->customerGroups->map->getAttributes()->all() : null,
            config('commerce.b2b_only'), config('commerce.b2b_display_net'),
            $this->taxPricing->pricesIncludeTax(),
        ]));
        $snapshots = $request->attributes->get(self::RESOLVED_LINES_ATTRIBUTE, []);
        if (isset($snapshots[$snapshotKey])) {
            return clone $snapshots[$snapshotKey];
        }

        $productIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['product_id'],
            $items
        )));
        $productOptionValueIds = array_values(array_unique(array_filter(array_map(
            static fn (array $row): int => (int) ($row['product_option_value_id'] ?? 0),
            $items
        ))));

        $products = Product::query()
            ->withStorefrontEnergyData()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->with([
                'media',
                'taxRate',
                'categories:id,payload',
                'packages',
                'translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'manufacturer.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
            ])
            ->get()
            ->keyBy('id');

        $optionRows = ProductOptionValue::query()
            ->whereIn('id', $productOptionValueIds)
            ->with([
                'optionValue.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'optionValue.option.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'parentOptionValue.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
                'parentOptionValue.option.translations' => fn ($q) => $q->whereIn('locale', [$locale, $fallbackLocale]),
            ])
            ->get()
            ->keyBy('id');

        $lines = collect();

        $pricingQuantities = [];
        if (app(B2BAccessService::class)->requiresApprovedAccount()) {
            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $product = $products->get($productId);
                if (! $product) {
                    continue;
                }
                $option = $optionRows->get((int) ($item['product_option_value_id'] ?? 0));
                if ($option && (int) $option->product_id !== $productId) {
                    $option = null;
                }
                $stock = $option ? (int) $option->stock_qty : $product->availableStockQuantity();
                $pricingQuantities[$productId] = ($pricingQuantities[$productId] ?? 0)
                    + $this->normalizeOrderQuantity($product, (int) $item['quantity'], $stock);
            }
        }

        foreach ($items as $key => $item) {
            $productId = (int) $item['product_id'];
            $quantity = (int) $item['quantity'];
            $optionValueId = (int) ($item['product_option_value_id'] ?? 0);

            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $optionRow = $optionValueId > 0 ? $optionRows->get($optionValueId) : null;
            if ($optionRow && (int) $optionRow->product_id !== $productId) {
                $optionRow = null;
            }

            $maxStock = $optionRow ? (int) $optionRow->stock_qty : $product->availableStockQuantity();
            if ($maxStock <= 0) {
                continue;
            }

            $qty = $this->normalizeOrderQuantity($product, $quantity, $maxStock);
            if ($qty <= 0) {
                continue;
            }

            $storedBaseUnitPrice = $optionRow && $optionRow->price_override !== null
                ? (float) $optionRow->price_override
                : (float) $product->base_price;
            $groupPrice = $this->groupPriceResolver->resolve(
                $product,
                $user,
                $pricingQuantities[$productId] ?? $qty,
                fallback: $storedBaseUnitPrice,
            );
            $storedAudienceUnitPrice = (float) ($groupPrice?->price ?? $storedBaseUnitPrice);
            $preciseSnapshot = app(B2BAccessService::class)->requiresApprovedAccount() && (bool) $groupPrice?->is_final;
            if ($preciseSnapshot && $optionRow && is_numeric(data_get($optionRow->payload, 'opencart.price'))) {
                $delta = (float) data_get($optionRow->payload, 'opencart.price');
                $prefix = (string) data_get($optionRow->payload, 'opencart.price_prefix', '+');
                $storedAudienceUnitPrice = max(0.0, $storedAudienceUnitPrice + ($prefix === '-' ? -$delta : $delta));
            }
            $resolvedAction = $groupPrice?->is_final
                ? null
                : $this->actionResolver->resolveProductAction($product, $user, $couponCode);
            $storedDiscountedUnitPrice = $resolvedAction
                ? $this->actionResolver->applyToPrice($storedAudienceUnitPrice, $resolvedAction)
                : $storedAudienceUnitPrice;
            $resolvedTaxRate = $this->taxPricing->resolveRateForProduct($product, $user);
            $catalogUnitPrice = $preciseSnapshot ? $this->preciseNetPrice($storedBaseUnitPrice, $resolvedTaxRate) : $this->taxPricing->normalizeNetPrice($storedBaseUnitPrice, $product);
            $baseUnitPrice = $preciseSnapshot ? $this->preciseNetPrice($storedAudienceUnitPrice, $resolvedTaxRate) : $this->taxPricing->normalizeNetPrice($storedAudienceUnitPrice, $product);
            $unitPrice = $preciseSnapshot ? $this->preciseNetPrice($storedDiscountedUnitPrice, $resolvedTaxRate) : $this->taxPricing->normalizeNetPrice($storedDiscountedUnitPrice, $product);
            $precision = $preciseSnapshot ? 4 : 2;
            $unitDiscount = round(max(0, $baseUnitPrice - $unitPrice), $precision);
            $lineDiscountTotal = round($unitDiscount * $qty, $precision);
            $lineTotal = round($unitPrice * $qty, $precision);
            $unitTaxAmount = $preciseSnapshot ? $this->rawTaxAmount($unitPrice, $resolvedTaxRate) : $this->taxPricing->taxFromNet($unitPrice, $product);
            $baseUnitTaxAmount = $preciseSnapshot ? $this->rawTaxAmount($baseUnitPrice, $resolvedTaxRate) : $this->taxPricing->taxFromNet($baseUnitPrice, $product);
            $lineTaxTotal = round($unitTaxAmount * $qty, $precision);
            $displayUnitPrice = round($unitPrice + $unitTaxAmount, 2);
            $displayBaseUnitPrice = round($baseUnitPrice + $baseUnitTaxAmount, 2);
            $displayCatalogUnitPrice = (float) $this->taxPricing->grossFromStored($storedBaseUnitPrice, $product);
            $displayLineTotal = round($lineTotal + $lineTaxTotal, 2);
            $taxRateValue = (float) ($resolvedTaxRate?->rate ?? 0);
            $displayNet = app(B2BAccessService::class)->requiresApprovedAccount() && (bool) config('commerce.b2b_display_net', true);
            if ($displayNet) {
                $displayUnitPrice = $unitPrice;
                $displayBaseUnitPrice = $baseUnitPrice;
                $displayCatalogUnitPrice = $catalogUnitPrice;
                $displayLineTotal = $lineTotal;
            }
            $translation = $product->translations->firstWhere('locale', $locale)
                ?? $product->translations->firstWhere('locale', $fallbackLocale);
            $optionMeta = $this->optionMeta($optionRow, $locale, $fallbackLocale);

            $lines->push([
                'key' => $key,
                'product' => $product,
                'translation' => $translation,
                'product_option_value_id' => $optionRow?->id,
                'option_label' => $optionMeta['label'],
                'option_name' => $optionMeta['name'],
                'option_value_label' => $optionMeta['value'],
                'sku' => (string) ($optionRow?->sku ?: $product->sku ?: ''),
                'quantity' => $qty,
                'catalog_unit_price' => $catalogUnitPrice,
                'base_unit_price' => $baseUnitPrice,
                'unit_price' => $unitPrice,
                'display_unit_price' => $displayUnitPrice,
                'display_base_unit_price' => $displayBaseUnitPrice,
                'display_catalog_unit_price' => $displayCatalogUnitPrice,
                'unit_discount' => $unitDiscount,
                'line_discount_total' => $lineDiscountTotal,
                'line_total' => $lineTotal,
                'display_line_total' => $displayLineTotal,
                'line_tax_total' => $lineTaxTotal,
                'unit_tax_amount' => round($unitTaxAmount, $precision),
                'monetary_precision' => $precision,
                'display_includes_tax' => ! $displayNet,
                'tax_rate' => $taxRateValue,
                'action_code' => $resolvedAction?->code,
                'price_source' => match (true) {
                    $groupPrice !== null && $resolvedAction !== null => 'b2b_action',
                    $groupPrice !== null => 'b2b',
                    $resolvedAction !== null => 'action',
                    default => 'base',
                },
                'is_b2b_price' => $groupPrice !== null,
                'energy_declaration' => $this->energyLabelPresenter->primaryDeclaration($product),
                'has_promotional_discount' => $resolvedAction !== null && $unitDiscount > 0,
                'group_price_id' => $groupPrice?->group_price_id,
                'b2b_rule_id' => $groupPrice?->rule_id,
                'b2b_source_type' => $groupPrice?->source_type,
                'price_is_final' => (bool) $groupPrice?->is_final,
                'price_catalog_id' => $groupPrice?->catalog_id,
                'price_catalog_entry_id' => $groupPrice?->catalog_entry_id,
            ]);
        }

        $lines = $lines->values();
        $snapshots[$snapshotKey] = $lines;
        $request->attributes->set(self::RESOLVED_LINES_ATTRIBUTE, $snapshots);

        return clone $lines;
    }

    /** Clear only the current request's product/pricing snapshot after a write. */
    public static function forgetRequestSnapshot(): void
    {
        if (app()->bound('request')) {
            app('request')->attributes->remove(self::RESOLVED_LINES_ATTRIBUTE);
        }
    }

    /**
     * @return array{
     *  line_count:int,
     *  item_qty:int,
     *  subtotal:float,
     *  discount_total:float,
     *  subtotal_after_discount:float,
     *  tax_rate:float|null,
     *  tax_rate_type:string,
     *  tax_total:float,
     *  grand_total:float,
     *  coupon_code:string
     * }
     */
    public function summary(?string $locale = null, ?string $couponCode = null): array
    {
        $couponCode = $couponCode === null ? $this->couponCode() : strtoupper(trim($couponCode));
        $lines = $this->lines($locale, $couponCode);
        $preciseSnapshot = app(B2BAccessService::class)->requiresApprovedAccount()
            && $lines->contains(fn (array $line): bool => (bool) ($line['price_is_final'] ?? false));
        if ($preciseSnapshot) {
            $rawSubtotal = round((float) $lines->sum(static fn (array $line): float => (float) ($line['base_unit_price'] ?? 0) * (int) ($line['quantity'] ?? 0)), 4);
            $rawDiscount = round((float) $lines->sum('line_discount_total'), 4);
            $rawAfterDiscount = round(max(0.0, $rawSubtotal - $rawDiscount), 4);
            $rawTax = round(max(0.0, (float) $lines->sum('line_tax_total')), 4);
            $taxRates = $lines->pluck('tax_rate')->map(static fn ($rate): float => round((float) $rate, 4))->unique()->values();

            return [
                'line_count' => $lines->count(), 'item_qty' => (int) $lines->sum('quantity'),
                'subtotal' => round($rawSubtotal, 2), 'discount_total' => round($rawDiscount, 2),
                'line_discount_total' => round($rawDiscount, 2), 'cart_discount_total' => 0.0,
                'cart_discount_action_code' => null, 'subtotal_after_discount' => round($rawAfterDiscount, 2),
                'tax_rate' => $taxRates->count() === 1 ? (float) $taxRates->first() : null,
                'tax_rate_type' => 'percent', 'tax_total' => round($rawTax, 2),
                'grand_total' => round($rawAfterDiscount + $rawTax, 2), 'coupon_code' => $couponCode,
                'raw_subtotal' => $rawSubtotal, 'raw_discount_total' => $rawDiscount,
                'raw_subtotal_after_discount' => $rawAfterDiscount, 'raw_tax_total' => $rawTax,
                'monetary_precision' => 4,
            ];
        }
        $subtotal = round((float) $lines->sum(static fn (array $line): float => (float) ($line['base_unit_price'] ?? 0) * (int) ($line['quantity'] ?? 0)), 2);
        $lineDiscountTotal = round((float) $lines->sum('line_discount_total'), 2);
        $subtotalAfterLineDiscount = round(max(0.0, $subtotal - $lineDiscountTotal), 2);
        $cartDiscount = $this->resolveCartDiscount($lines, $subtotalAfterLineDiscount, $couponCode);
        $cartDiscountTotal = round((float) $cartDiscount['amount'], 2);
        $discountTotal = round($lineDiscountTotal + $cartDiscountTotal, 2);
        $subtotalAfterDiscount = round(max(0.0, $subtotal - $discountTotal), 2);
        $taxTotal = round(max(0.0, (float) $lines->sum('line_tax_total') - (float) $cartDiscount['tax_discount']), 2);
        $taxRates = $lines->pluck('tax_rate')
            ->map(static fn ($rate): float => round((float) $rate, 4))
            ->unique()
            ->values();
        $taxRateValue = $taxRates->count() === 1 ? (float) $taxRates->first() : null;
        $grandTotal = round($subtotalAfterDiscount + $taxTotal, 2);

        return [
            'line_count' => $lines->count(),
            'item_qty' => (int) $lines->sum('quantity'),
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'line_discount_total' => $lineDiscountTotal,
            'cart_discount_total' => $cartDiscountTotal,
            'cart_discount_action_code' => $cartDiscount['action']?->code,
            'subtotal_after_discount' => $subtotalAfterDiscount,
            'tax_rate' => $taxRateValue,
            'tax_rate_type' => 'percent',
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
            'coupon_code' => $couponCode,
        ];
    }

    public function hasItems(): bool
    {
        return $this->summary()['line_count'] > 0;
    }

    private function preciseNetPrice(float $storedAmount, ?TaxRate $rate): float
    {
        if (! $this->taxPricing->pricesIncludeTax() || ! $rate) {
            return round(max(0.0, $storedAmount), 4);
        }
        $value = max(0.0, (float) $rate->rate);

        return round(max(0.0, (string) $rate->rate_type === 'fixed'
            ? $storedAmount - $value
            : $storedAmount / (1 + $value / 100)), 4);
    }

    private function rawTaxAmount(float $netAmount, ?TaxRate $rate): float
    {
        if (! $rate) {
            return 0.0;
        }
        $value = max(0.0, (float) $rate->rate);

        return (string) $rate->rate_type === 'fixed' ? $value : max(0.0, $netAmount * $value / 100);
    }

    public function add(Product $product, int $quantity = 1, ?int $productOptionValueId = null): bool
    {
        app(B2BAccessService::class)->ensureCanPurchase(auth()->user());

        if (! $product->is_active) {
            return false;
        }

        $optionRow = $this->resolveProductOptionValue($product, $productOptionValueId);
        if ($this->requiresOptionSelection($product) && ! $optionRow) {
            return false;
        }

        $items = $this->raw();
        $lineKey = $this->lineKey($product->id, $optionRow?->id);
        $existing = (int) ($items[$lineKey]['quantity'] ?? 0);
        $requested = max(1, min($quantity, 999));
        $target = $existing + $requested;

        $stock = $optionRow ? (int) $optionRow->stock_qty : $product->availableStockQuantity();
        if ($stock <= 0) {
            return false;
        }

        $normalizedQuantity = $this->normalizeOrderQuantity($product, $target, $stock);
        if ($normalizedQuantity <= 0) {
            return false;
        }

        $items[$lineKey] = [
            'product_id' => (int) $product->id,
            'product_option_value_id' => $optionRow?->id,
            'quantity' => $normalizedQuantity,
        ];
        Session::put(self::SESSION_KEY, $items);
        self::forgetRequestSnapshot();

        return true;
    }

    public function set(Product $product, int $quantity, ?int $productOptionValueId = null): bool
    {
        app(B2BAccessService::class)->ensureCanPurchase(auth()->user());

        $items = $this->raw();
        $lineKey = $this->lineKey((int) $product->id, $productOptionValueId);

        if ($quantity <= 0) {
            unset($items[$lineKey]);
            Session::put(self::SESSION_KEY, $items);
            self::forgetRequestSnapshot();

            return true;
        }

        if (! $product->is_active) {
            return false;
        }

        $optionRow = $this->resolveProductOptionValue($product, $productOptionValueId);
        $stock = $optionRow ? (int) $optionRow->stock_qty : $product->availableStockQuantity();
        if ($stock <= 0) {
            unset($items[$lineKey]);
            Session::put(self::SESSION_KEY, $items);
            self::forgetRequestSnapshot();

            return false;
        }

        $normalizedQuantity = $this->normalizeOrderQuantity($product, $quantity, $stock);
        if ($normalizedQuantity <= 0) {
            unset($items[$lineKey]);
            Session::put(self::SESSION_KEY, $items);
            self::forgetRequestSnapshot();

            return false;
        }

        $items[$lineKey] = [
            'product_id' => (int) $product->id,
            'product_option_value_id' => $optionRow?->id,
            'quantity' => $normalizedQuantity,
        ];
        Session::put(self::SESSION_KEY, $items);
        self::forgetRequestSnapshot();

        return $quantity <= $stock;
    }

    public function remove(int $productId, ?int $productOptionValueId = null): void
    {
        $items = $this->raw();

        if ($productOptionValueId !== null) {
            unset($items[$this->lineKey($productId, $productOptionValueId)]);
        } else {
            foreach ($items as $key => $item) {
                if ((int) ($item['product_id'] ?? 0) === $productId) {
                    unset($items[$key]);
                }
            }
        }

        Session::put(self::SESSION_KEY, $items);
        self::forgetRequestSnapshot();
    }

    public function clear(): void
    {
        Session::forget([self::SESSION_KEY, self::COUPON_SESSION_KEY]);
        self::forgetRequestSnapshot();
    }

    public function couponCode(): string
    {
        return strtoupper(trim((string) Session::get(self::COUPON_SESSION_KEY, '')));
    }

    public function applyCoupon(string $couponCode): bool
    {
        app(B2BAccessService::class)->ensureCanPurchase(auth()->user());

        $couponCode = strtoupper(trim($couponCode));
        if ($couponCode === '') {
            return false;
        }

        $baseDiscount = (float) ($this->summary(null, '')['discount_total'] ?? 0);
        $candidateDiscount = (float) ($this->summary(null, $couponCode)['discount_total'] ?? 0);

        if ($candidateDiscount <= ($baseDiscount + 0.0001)) {
            return false;
        }

        Session::put(self::COUPON_SESSION_KEY, $couponCode);
        self::forgetRequestSnapshot();

        return true;
    }

    public function clearCoupon(): void
    {
        Session::forget(self::COUPON_SESSION_KEY);
        self::forgetRequestSnapshot();
    }

    /**
     * @param  array<int, array{product_id:int,product_option_value_id:int|null,quantity:int}>  $lines
     */
    public function replaceRaw(array $lines, ?string $couponCode = null): void
    {
        app(B2BAccessService::class)->ensureCanPurchase(auth()->user());

        $normalized = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $optionValueId = (int) ($line['product_option_value_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $key = $this->lineKey($productId, $optionValueId > 0 ? $optionValueId : null);
            $current = (int) ($normalized[$key]['quantity'] ?? 0);
            $normalized[$key] = [
                'product_id' => $productId,
                'product_option_value_id' => $optionValueId > 0 ? $optionValueId : null,
                'quantity' => min(999, $current + $quantity),
            ];
        }

        Session::put(self::SESSION_KEY, $normalized);

        $coupon = strtoupper(trim((string) ($couponCode ?? '')));
        if ($coupon !== '') {
            Session::put(self::COUPON_SESSION_KEY, $coupon);
        } else {
            Session::forget(self::COUPON_SESSION_KEY);
        }
        self::forgetRequestSnapshot();
    }

    private function lineKey(int $productId, ?int $productOptionValueId): string
    {
        return $productId.':'.(int) ($productOptionValueId ?? 0);
    }

    private function resolveProductOptionValue(Product $product, ?int $productOptionValueId): ?ProductOptionValue
    {
        $id = (int) ($productOptionValueId ?? 0);
        if ($id <= 0) {
            return null;
        }

        $optionRow = ProductOptionValue::query()
            ->where('id', $id)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->with([
                'optionValue.option:id,payload',
                'parentOptionValue.option:id,payload',
            ])
            ->first();

        if (! $optionRow || ! $optionRow->showsOnProductPage()) {
            return null;
        }

        return $optionRow;
    }

    private function requiresOptionSelection(Product $product): bool
    {
        return $product->hasVisibleOptionRows();
    }

    private function normalizeOrderQuantity(Product $product, int $quantity, int $stock): int
    {
        $minimum = max(1, (int) ($product->minimum_order_quantity ?? 1));
        $step = max(1, (int) ($product->order_quantity_step ?? 1));
        $available = min(999, max(0, $stock));

        if ($available < $minimum) {
            return 0;
        }

        $requested = max($minimum, min(999, $quantity));
        $normalized = $minimum + (int) ceil(($requested - $minimum) / $step) * $step;
        $maximumValid = $minimum + (int) floor(($available - $minimum) / $step) * $step;

        return min($normalized, $maximumValid);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array{action:CatalogAction|null, amount:float, tax_discount:float}
     */
    private function resolveCartDiscount(Collection $lines, float $subtotalAfterLineDiscount, string $couponCode): array
    {
        if ($couponCode === '' || $lines->isEmpty() || $subtotalAfterLineDiscount <= 0.0
            || $lines->contains(fn (array $line): bool => (bool) ($line['price_is_final'] ?? false))) {
            return ['action' => null, 'amount' => 0.0, 'tax_discount' => 0.0];
        }

        $actions = CatalogAction::query()
            ->active()
            ->where('scope', CatalogAction::SCOPE_CART)
            ->whereIn('type', [CatalogAction::TYPE_PERCENTAGE, CatalogAction::TYPE_FIXED])
            ->whereRaw('UPPER(coupon_code) = ?', [$couponCode])
            ->availableForAudience(auth()->user())
            ->where(function ($query): void {
                $query->whereNull('usage_limit')
                    ->orWhereColumn('used_count', '<', 'usage_limit');
            })
            ->where(function ($query) use ($subtotalAfterLineDiscount): void {
                $query->whereNull('min_subtotal')
                    ->orWhere('min_subtotal', '<=', $subtotalAfterLineDiscount);
            })
            ->with('targets')
            ->orderByDesc('is_exclusive')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->get();

        $bestAction = null;
        $bestAmount = 0.0;
        $bestTaxDiscount = 0.0;

        foreach ($actions as $action) {
            $eligibleLines = $this->eligibleCartDiscountLines($action, $lines);
            $eligibleSubtotal = round((float) $eligibleLines->sum('line_total'), 2);

            if ($eligibleSubtotal <= 0.0) {
                continue;
            }

            $amount = $this->cartDiscountAmount($eligibleSubtotal, $action);
            if ($amount <= $bestAmount) {
                continue;
            }

            $eligibleTax = round((float) $eligibleLines->sum('line_tax_total'), 2);
            $taxDiscount = $eligibleSubtotal > 0.0
                ? round(min($eligibleTax, $eligibleTax * ($amount / $eligibleSubtotal)), 2)
                : 0.0;

            $bestAction = $action;
            $bestAmount = $amount;
            $bestTaxDiscount = $taxDiscount;
        }

        return [
            'action' => $bestAction,
            'amount' => round($bestAmount, 2),
            'tax_discount' => round($bestTaxDiscount, 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return Collection<int, array<string, mixed>>
     */
    private function eligibleCartDiscountLines(CatalogAction $action, Collection $lines): Collection
    {
        if ($action->target_type === CatalogAction::TARGET_ALL) {
            return $lines;
        }

        $targetIds = $action->targets
            ->where('target_type', $action->target_type)
            ->pluck('target_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($targetIds === []) {
            return collect();
        }

        return $lines->filter(function (array $line) use ($action, $targetIds): bool {
            $product = $line['product'] ?? null;
            if (! $product instanceof Product) {
                return false;
            }

            if ($action->target_type === CatalogAction::TARGET_PRODUCT) {
                return in_array((int) $product->id, $targetIds, true);
            }

            if ($action->target_type === CatalogAction::TARGET_MANUFACTURER) {
                return $product->manufacturer_id !== null
                    && in_array((int) $product->manufacturer_id, $targetIds, true);
            }

            if ($action->target_type === CatalogAction::TARGET_CATEGORY) {
                $categoryIds = $product->relationLoaded('categories')
                    ? $product->categories->pluck('id')->map(static fn ($id): int => (int) $id)->all()
                    : $product->categories()->pluck('categories.id')->map(static fn ($id): int => (int) $id)->all();

                return array_intersect($categoryIds, $targetIds) !== [];
            }

            return false;
        })->values();
    }

    private function cartDiscountAmount(float $eligibleSubtotal, CatalogAction $action): float
    {
        $value = max(0.0, (float) ($action->discount_value ?? 0));

        if ($action->type === CatalogAction::TYPE_PERCENTAGE) {
            return round(min($eligibleSubtotal, ($eligibleSubtotal * min(100.0, $value)) / 100), 2);
        }

        if ($action->type === CatalogAction::TYPE_FIXED) {
            return round(min($eligibleSubtotal, $value), 2);
        }

        return 0.0;
    }

    /**
     * @return array{name:?string,value:?string,label:?string}
     */
    private function optionMeta(?ProductOptionValue $optionRow, string $locale, string $fallbackLocale): array
    {
        if (! $optionRow) {
            return ['name' => null, 'value' => null, 'label' => null];
        }

        $child = $optionRow->optionValue?->translations?->firstWhere('locale', $locale)
            ?? $optionRow->optionValue?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $optionRow->optionValue?->translations?->first();
        $parent = $optionRow->parentOptionValue?->translations?->firstWhere('locale', $locale)
            ?? $optionRow->parentOptionValue?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $optionRow->parentOptionValue?->translations?->first();

        $childLabel = trim((string) ($child?->name ?? $optionRow->optionValue?->code ?? ''));
        $parentLabel = trim((string) ($parent?->name ?? $optionRow->parentOptionValue?->code ?? ''));
        $childOptionTranslation = $optionRow->optionValue?->option?->translations?->firstWhere('locale', $locale)
            ?? $optionRow->optionValue?->option?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $optionRow->optionValue?->option?->translations?->first();
        $parentOptionTranslation = $optionRow->parentOptionValue?->option?->translations?->firstWhere('locale', $locale)
            ?? $optionRow->parentOptionValue?->option?->translations?->firstWhere('locale', $fallbackLocale)
            ?? $optionRow->parentOptionValue?->option?->translations?->first();
        $childOptionName = trim((string) ($childOptionTranslation?->name ?? ''));
        $parentOptionName = trim((string) ($parentOptionTranslation?->name ?? ''));

        if ($parentOptionName !== '' && $parentLabel !== '' && $childOptionName !== '' && $childLabel !== '') {
            return [
                'name' => $childOptionName,
                'value' => $childLabel,
                'label' => $parentOptionName.': '.$parentLabel.' / '.$childOptionName.': '.$childLabel,
            ];
        }

        $optionName = $childOptionName;
        $valueLabel = $childLabel !== '' ? $childLabel : $parentLabel;

        if ($optionName !== '' && $valueLabel !== '') {
            return [
                'name' => $optionName,
                'value' => $valueLabel,
                'label' => $optionName.': '.$valueLabel,
            ];
        }

        if ($parentLabel !== '' && $childLabel !== '') {
            return [
                'name' => null,
                'value' => null,
                'label' => $parentLabel.' / '.$childLabel,
            ];
        }

        if ($childLabel !== '') {
            return [
                'name' => null,
                'value' => $childLabel,
                'label' => $childLabel,
            ];
        }

        return [
            'name' => null,
            'value' => $parentLabel !== '' ? $parentLabel : null,
            'label' => $parentLabel !== '' ? $parentLabel : null,
        ];
    }
}
