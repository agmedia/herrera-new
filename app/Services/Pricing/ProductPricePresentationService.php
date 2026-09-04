<?php

namespace App\Services\Pricing;

use App\Data\Pricing\ResolvedB2BPrice;
use App\Models\Catalog\Action\CatalogAction;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductPackage;
use App\Models\Catalog\Product\ProductPriceHistory;
use App\Models\User;
use App\Services\Catalog\ActionResolverService;
use Illuminate\Database\Eloquent\Builder;

class ProductPricePresentationService
{
    public function __construct(
        private readonly ActionResolverService $actionResolver,
        private readonly TaxPricingService $taxPricing,
        private readonly ProductGroupPriceResolver $groupPriceResolver,
    ) {}

    /**
     * @return array{
     *   current_gross: float,
     *   current_net: float,
     *   base_gross: float,
     *   catalog_gross: float,
     *   old_gross: float|null,
     *   has_discount: bool,
     *   has_promotional_discount: bool,
     *   is_b2b_price: bool,
     *   discount_percent: int|null,
     *   lowest_30_days_gross: float|null
     * }
     */
    public function forProduct(
        Product $product,
        ?User $user = null,
        int $quantity = 1,
        ?ProductPackage $package = null,
    ): array {
        return $this->forStoredBase(
            $product,
            (float) $product->base_price,
            $user,
            $quantity,
            $package,
        );
    }

    /**
     * @return array{
     *   current_gross: float,
     *   current_net: float,
     *   base_gross: float,
     *   catalog_gross: float,
     *   old_gross: float|null,
     *   has_discount: bool,
     *   has_promotional_discount: bool,
     *   is_b2b_price: bool,
     *   discount_percent: int|null,
     *   lowest_30_days_gross: float|null
     * }
     */
    public function forStoredBase(
        Product $product,
        float $storedBase,
        ?User $user = null,
        int $quantity = 1,
        ?ProductPackage $package = null,
    ): array {
        $groupPrice = $this->groupPriceResolver->resolve(
            $product,
            $user,
            $quantity,
            $package,
            $storedBase,
        );
        $audienceStoredBase = (float) ($groupPrice?->price ?? $storedBase);
        $resolvedAction = $this->actionResolver->resolveProductAction($product, $user);
        $storedCurrent = $resolvedAction
            ? $this->actionResolver->applyToPrice($audienceStoredBase, $resolvedAction)
            : $audienceStoredBase;
        $catalogGross = (float) $this->taxPricing->grossFromStored($storedBase, $product);
        $audienceBaseGross = (float) $this->taxPricing->grossFromStored($audienceStoredBase, $product);
        $currentGross = (float) $this->taxPricing->grossFromStored($storedCurrent, $product);
        $hasPromotionalDiscount = $resolvedAction !== null
            && $currentGross < ($audienceBaseGross - 0.0001);

        $lowest30DaysGross = null;
        if ($hasPromotionalDiscount) {
            $lowest30DaysStored = $this->lowestStoredPriceInLast30Days(
                $product,
                $audienceStoredBase,
                $user,
                $groupPrice,
            );
            $lowest30DaysGross = (float) $this->taxPricing->grossFromStored($lowest30DaysStored, $product);
        }

        return [
            'current_gross' => $currentGross,
            'current_net' => (float) $this->taxPricing->netFromGross($currentGross, $product),
            'base_gross' => $audienceBaseGross,
            'catalog_gross' => $catalogGross,
            'old_gross' => $hasPromotionalDiscount ? $audienceBaseGross : null,
            'has_discount' => $hasPromotionalDiscount,
            'has_promotional_discount' => $hasPromotionalDiscount,
            'is_b2b_price' => $groupPrice !== null,
            'discount_percent' => $hasPromotionalDiscount && $audienceBaseGross > 0
                ? (int) round((($audienceBaseGross - $currentGross) / $audienceBaseGross) * 100)
                : null,
            'lowest_30_days_gross' => $lowest30DaysGross,
            'price_source' => match (true) {
                $groupPrice !== null && $resolvedAction !== null => 'b2b_action',
                $groupPrice !== null => 'b2b',
                $resolvedAction !== null => 'action',
                default => 'base',
            },
            'group_price_id' => $groupPrice?->group_price_id,
            'b2b_rule_id' => $groupPrice?->rule_id,
            'b2b_source_type' => $groupPrice?->source_type,
        ];
    }

    private function lowestStoredPriceInLast30Days(
        Product $product,
        float $storedBasePrice,
        ?User $user,
        ?ResolvedB2BPrice $groupPrice = null,
    ): float {
        $periodStart = now()->subDays(30);
        $periodEnd = now();

        $categoryIds = $product->relationLoaded('categories')
            ? $product->categories->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            : $product->categories()->pluck('categories.id')->map(static fn ($id): int => (int) $id)->all();
        $manufacturerId = $product->manufacturer_id ? (int) $product->manufacturer_id : null;

        $actions = CatalogAction::query()
            ->where('scope', CatalogAction::SCOPE_PRODUCT)
            ->whereIn('type', [CatalogAction::TYPE_PERCENTAGE, CatalogAction::TYPE_FIXED])
            ->availableForAudience($user)
            ->where(function (Builder $q): void {
                $q->whereNull('coupon_code')
                    ->orWhere('coupon_code', '');
            })
            ->where(function (Builder $q) use ($periodEnd): void {
                $q->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $periodEnd);
            })
            ->where(function (Builder $q) use ($periodStart): void {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $periodStart);
            })
            ->where(function (Builder $q) use ($product, $categoryIds, $manufacturerId): void {
                $q->where('target_type', CatalogAction::TARGET_ALL)
                    ->orWhere(function (Builder $qq) use ($product): void {
                        $qq->where('target_type', CatalogAction::TARGET_PRODUCT)
                            ->whereHas('targets', function (Builder $tq) use ($product): void {
                                $tq->where('target_type', CatalogAction::TARGET_PRODUCT)
                                    ->where('target_id', $product->id);
                            });
                    })
                    ->orWhere(function (Builder $qq) use ($categoryIds): void {
                        if ($categoryIds === []) {
                            return;
                        }

                        $qq->where('target_type', CatalogAction::TARGET_CATEGORY)
                            ->whereHas('targets', function (Builder $tq) use ($categoryIds): void {
                                $tq->where('target_type', CatalogAction::TARGET_CATEGORY)
                                    ->whereIn('target_id', $categoryIds);
                            });
                    })
                    ->orWhere(function (Builder $qq) use ($manufacturerId): void {
                        if (! $manufacturerId) {
                            return;
                        }

                        $qq->where('target_type', CatalogAction::TARGET_MANUFACTURER)
                            ->whereHas('targets', function (Builder $tq) use ($manufacturerId): void {
                                $tq->where('target_type', CatalogAction::TARGET_MANUFACTURER)
                                    ->where('target_id', $manufacturerId);
                            });
                    });
            })
            ->get();

        $historyQuery = ProductPriceHistory::query()
            ->where('product_id', $product->getKey())
            ->whereBetween('effective_at', [$periodStart, $periodEnd])
            ->where(function (Builder $query) use ($groupPrice): void {
                $query->where('price_type', 'base');

                if ($groupPrice) {
                    $query->orWhere(function (Builder $query) use ($groupPrice): void {
                        $query
                            ->where('price_type', 'b2b')
                            ->where('customer_group_id', $groupPrice->customer_group_id)
                            ->where(function (Builder $query) use ($groupPrice): void {
                                $query
                                    ->where('product_package_id', $groupPrice->product_package_id)
                                    ->orWhereNull('product_package_id');
                            });
                    });
                }
            });

        $priceCandidates = $historyQuery
            ->get(['old_price', 'new_price'])
            ->flatMap(static fn (ProductPriceHistory $history): array => [
                $history->old_price,
                $history->new_price,
            ])
            ->filter(static fn ($price): bool => $price !== null)
            ->map(static fn ($price): float => (float) $price)
            ->push($storedBasePrice)
            ->unique()
            ->values();

        $best = $storedBasePrice;
        foreach ($priceCandidates as $historicalPrice) {
            $best = min($best, $historicalPrice);

            foreach ($actions as $action) {
                $candidate = $this->actionResolver->applyToPrice($historicalPrice, $action);
                if ($candidate < $best) {
                    $best = $candidate;
                }
            }
        }

        return max(0.0, $best);
    }
}
