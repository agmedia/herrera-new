<?php

namespace App\Services\Pricing;

use App\Data\Pricing\ResolvedB2BPrice;
use App\Models\Catalog\Pricing\B2BPriceRule;
use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductGroupPrice;
use App\Models\Catalog\Product\ProductPackage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ProductGroupPriceResolver
{
    public function resolve(
        Product $product,
        ?User $user,
        int $quantity = 1,
        ?ProductPackage $package = null,
        ?float $fallback = null,
    ): ?ResolvedB2BPrice {
        if (! $user) {
            return null;
        }

        if ($user->relationLoaded('b2bAccount')) {
            $b2bAccount = $user->b2bAccount;
        } else {
            $b2bAccount = $user->b2bAccount()->first();
            $user->setRelation('b2bAccount', $b2bAccount);
        }

        if ($b2bAccount && ! $b2bAccount->contractIsActive()) {
            return null;
        }

        $groupIds = $user->relationLoaded('customerGroups')
            ? $user->customerGroups
                ->where('is_active', true)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
            : $user->customerGroups()
                ->where('customer_groups.is_active', true)
                ->pluck('customer_groups.id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

        $categoryIds = $product->relationLoaded('categories')
            ? $product->categories->pluck('id')->map(static fn ($id): int => (int) $id)->all()
            : $product->categories()->pluck('categories.id')->map(static fn ($id): int => (int) $id)->all();
        $manufacturerId = $product->manufacturer_id ? (int) $product->manufacturer_id : null;
        $storedBase = $fallback ?? (float) $product->base_price;

        $individualRule = $b2bAccount
            ? $this->bestRule(
                B2BPriceRule::query()->where('user_id', $user->getKey()),
                $product,
                $categoryIds,
                $manufacturerId,
                $quantity,
                $storedBase,
            )
            : null;

        if ($individualRule) {
            return $this->resolvedRule($individualRule, (int) $user->getKey());
        }

        if ($groupIds !== []) {
            $query = ProductGroupPrice::query()
                ->where('product_id', $product->getKey())
                ->whereIn('customer_group_id', $groupIds)
                ->activeAt()
                ->forQuantity($quantity);

            if ($package) {
                $query
                    ->where(function (Builder $query) use ($package): void {
                        $query
                            ->where('product_package_id', $package->getKey())
                            ->orWhereNull('product_package_id');
                    })
                    ->orderByRaw('CASE WHEN product_package_id = ? THEN 0 ELSE 1 END', [$package->getKey()]);
            } else {
                $query->whereNull('product_package_id');
            }

            $directPrice = $query
                ->orderBy('price')
                ->orderByDesc('minimum_quantity')
                ->orderByDesc('id')
                ->first();

            if ($directPrice) {
                return new ResolvedB2BPrice(
                    id: (int) $directPrice->getKey(),
                    price: (float) $directPrice->price,
                    source_type: 'product_group_price',
                    customer_group_id: (int) $directPrice->customer_group_id,
                    product_package_id: $directPrice->product_package_id
                        ? (int) $directPrice->product_package_id
                        : null,
                    group_price_id: (int) $directPrice->getKey(),
                );
            }
        }

        if ($groupIds === []) {
            return null;
        }

        $groupRule = $this->bestRule(
            B2BPriceRule::query()->whereNull('user_id')->whereIn('customer_group_id', $groupIds),
            $product,
            $categoryIds,
            $manufacturerId,
            $quantity,
            $storedBase,
        );

        return $groupRule ? $this->resolvedRule($groupRule) : null;
    }

    /**
     * @param  array<int, int>  $categoryIds
     * @return array{rule:B2BPriceRule,price:float,specificity:int}|null
     */
    private function bestRule(
        Builder $query,
        Product $product,
        array $categoryIds,
        ?int $manufacturerId,
        int $quantity,
        float $storedBase,
    ): ?array {
        return $query
            ->activeAt()
            ->where('minimum_quantity', '<=', max(1, $quantity))
            ->with('targets:id,rule_id,target_type,target_id')
            ->get()
            ->filter(fn (B2BPriceRule $rule): bool => $this->matchesProduct(
                $rule,
                $product,
                $categoryIds,
                $manufacturerId,
            ))
            ->map(fn (B2BPriceRule $rule): array => [
                'rule' => $rule,
                'price' => $this->applyRule($storedBase, $rule),
                'specificity' => $this->specificity($rule->target_type),
            ])
            ->sort(function (array $left, array $right): int {
                return [
                    $right['specificity'],
                    (int) $right['rule']->priority,
                    (int) $right['rule']->minimum_quantity,
                    -1 * (float) $right['price'],
                    (int) $right['rule']->id,
                ] <=> [
                    $left['specificity'],
                    (int) $left['rule']->priority,
                    (int) $left['rule']->minimum_quantity,
                    -1 * (float) $left['price'],
                    (int) $left['rule']->id,
                ];
            })
            ->first();
    }

    /**
     * @param  array{rule:B2BPriceRule,price:float,specificity:int}  $candidate
     */
    private function resolvedRule(array $candidate, ?int $userId = null): ResolvedB2BPrice
    {
        /** @var B2BPriceRule $rule */
        $rule = $candidate['rule'];

        return new ResolvedB2BPrice(
            id: (int) $rule->getKey(),
            price: (float) $candidate['price'],
            source_type: 'b2b_rule',
            customer_group_id: $rule->customer_group_id ? (int) $rule->customer_group_id : null,
            user_id: $userId ?? ($rule->user_id ? (int) $rule->user_id : null),
            rule_id: (int) $rule->getKey(),
        );
    }

    public function storedPrice(
        Product $product,
        ?User $user,
        int $quantity = 1,
        ?ProductPackage $package = null,
        ?float $fallback = null,
    ): float {
        return (float) ($this->resolve($product, $user, $quantity, $package, $fallback)?->price
            ?? $fallback
            ?? $product->base_price);
    }

    /**
     * @param  array<int, int>  $categoryIds
     */
    private function matchesProduct(
        B2BPriceRule $rule,
        Product $product,
        array $categoryIds,
        ?int $manufacturerId,
    ): bool {
        if ($rule->target_type === B2BPriceRule::TARGET_ALL) {
            return true;
        }

        $targetIds = $rule->targets
            ->where('target_type', $rule->target_type)
            ->pluck('target_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return match ($rule->target_type) {
            B2BPriceRule::TARGET_PRODUCT => in_array((int) $product->getKey(), $targetIds, true),
            B2BPriceRule::TARGET_CATEGORY => array_intersect($categoryIds, $targetIds) !== [],
            B2BPriceRule::TARGET_MANUFACTURER => $manufacturerId !== null
                && in_array($manufacturerId, $targetIds, true),
            default => false,
        };
    }

    private function applyRule(float $basePrice, B2BPriceRule $rule): float
    {
        $value = max(0.0, (float) $rule->value);

        return match ($rule->calculation_type) {
            B2BPriceRule::TYPE_PERCENTAGE_DISCOUNT => max(0.0, $basePrice * (1 - min(100.0, $value) / 100)),
            B2BPriceRule::TYPE_FIXED_DISCOUNT => max(0.0, $basePrice - $value),
            B2BPriceRule::TYPE_FIXED_PRICE => $value,
            default => $basePrice,
        };
    }

    private function specificity(string $targetType): int
    {
        return match ($targetType) {
            B2BPriceRule::TARGET_PRODUCT => 400,
            B2BPriceRule::TARGET_CATEGORY => 300,
            B2BPriceRule::TARGET_MANUFACTURER => 200,
            default => 100,
        };
    }
}
