<?php

namespace App\Services\Shipping;

use App\Models\Catalog\Product\Product;
use App\Models\Catalog\Product\ProductPackage;
use App\Models\Settings\Local\ShippingMethod;
use App\Models\Settings\Local\ShippingMethodRate;
use Illuminate\Support\Collection;

class ShippingCalculator
{
    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<string, mixed>|null
     */
    public function quote(ShippingMethod $method, Collection $lines, float $subtotal): ?array
    {
        $profile = $this->profile($lines);

        if (! $this->methodAcceptsProfile($method, $profile)) {
            return null;
        }

        $allFree = (bool) ($profile['all_items_free_shipping'] ?? false);
        $freeOver = is_numeric($method->free_over) ? (float) $method->free_over : null;
        $pricingType = (string) ($method->pricing_type ?: 'flat');
        $base = 0.0;
        $rateId = null;

        if ($allFree || ($freeOver !== null && $freeOver >= 0 && $subtotal >= $freeOver)) {
            $pricingType = 'free';
        } elseif ($pricingType === 'weight_tiers') {
            $rate = $this->matchingRate($method, (float) ($profile['chargeable_weight_kg'] ?? 0));
            if (! $rate) {
                return null;
            }

            $base = (float) $rate->price;
            $rateId = (int) $rate->id;
        } elseif ($pricingType === 'free' || $pricingType === 'quote') {
            $base = 0.0;
        } else {
            $base = max(0, (float) $method->price);
        }

        $surcharges = [
            'fragile' => 0.0,
            'oversized' => 0.0,
            'heavy' => 0.0,
        ];

        if ($pricingType !== 'free' && $pricingType !== 'quote') {
            $labels = $profile['labels'] ?? [];
            if (in_array('fragile', $labels, true)) {
                $surcharges['fragile'] = max(0, (float) $method->fragile_surcharge);
            }
            if (in_array('oversized', $labels, true)) {
                $surcharges['oversized'] = max(0, (float) $method->oversized_surcharge);
            }
            if (in_array('heavy', $labels, true)) {
                $surcharges['heavy'] = max(0, (float) $method->heavy_surcharge);
            }
        }

        return [
            'price' => round($base + array_sum($surcharges), 2),
            'base_price' => round($base, 2),
            'surcharges' => array_map(static fn (float $value): float => round($value, 2), $surcharges),
            'pricing_type' => $pricingType,
            'rate_id' => $rateId,
            'requires_quote' => $pricingType === 'quote',
            'profile' => $profile,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function profile(Collection $lines): array
    {
        $totalWeight = 0.0;
        $chargeableWeight = 0.0;
        $labels = [];
        $items = [];
        $allFree = $lines->isNotEmpty();
        $hasMissingWeight = false;
        $hasMissingDimensions = false;

        foreach ($lines as $line) {
            /** @var Product|null $product */
            $product = $line['product'] ?? null;
            if (! $product) {
                continue;
            }

            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $productLabels = $this->productLabels($product);
            $labels = array_merge($labels, $productLabels);
            $freeItem = in_array('free_shipping', $productLabels, true)
                || in_array('exclude_shipping_calculation', $productLabels, true);
            $allFree = $allFree && $freeItem;

            $measurements = $this->productMeasurements($product);
            $weight = $measurements['weight_kg'];
            if ($weight === null) {
                $hasMissingWeight = true;
            } else {
                $lineWeight = $weight * $quantity;
                $totalWeight += $lineWeight;
                if (! $freeItem) {
                    $chargeableWeight += $lineWeight;
                }
            }

            $dimensions = [
                $measurements['length_cm'],
                $measurements['width_cm'],
                $measurements['height_cm'],
            ];
            if (collect($dimensions)->contains(static fn ($value): bool => $value === null)) {
                $hasMissingDimensions = true;
            }

            $items[] = [
                'product_id' => (int) $product->id,
                'quantity' => $quantity,
                'weight_kg' => $weight,
                'dimensions_cm' => $dimensions,
                'labels' => $productLabels,
                'free_shipping' => $freeItem,
            ];
        }

        return [
            'total_weight_kg' => round($totalWeight, 3),
            'chargeable_weight_kg' => round($chargeableWeight, 3),
            'labels' => array_values(array_unique($labels)),
            'all_items_free_shipping' => $allFree,
            'has_missing_weight' => $hasMissingWeight,
            'has_missing_dimensions' => $hasMissingDimensions,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function methodAcceptsProfile(ShippingMethod $method, array $profile): bool
    {
        if ((string) $method->service_type === 'pickup') {
            return true;
        }

        $labels = $profile['labels'] ?? [];
        $settings = is_array($method->settings) ? $method->settings : [];
        $isQuoteMethod = (string) $method->pricing_type === 'quote';
        $hasQuoteLabel = in_array('quote_shipping', $labels, true);
        $isMissingWeightFallback = $isQuoteMethod
            && (bool) ($settings['fallback_for_missing_weight'] ?? false);

        if ($hasQuoteLabel && ! $isQuoteMethod) {
            return false;
        }

        if ($isMissingWeightFallback && ($hasQuoteLabel || ! (bool) ($profile['has_missing_weight'] ?? false))) {
            return false;
        }

        if ($isQuoteMethod && ! $hasQuoteLabel && ! $isMissingWeightFallback) {
            return false;
        }

        if (in_array('fragile', $labels, true) && ! (bool) $method->allows_fragile) {
            return false;
        }

        if (in_array('oversized', $labels, true) && ! (bool) $method->allows_oversized) {
            return false;
        }

        if (in_array('heavy', $labels, true) && ! (bool) $method->allows_heavy) {
            return false;
        }

        if ((string) $method->service_type === 'parcel_locker') {
            if (array_intersect($labels, [
                'no_parcel_locker',
                'hazardous',
                'refrigerated',
                'ships_separately',
            ]) !== []) {
                return false;
            }
        }

        $policy = (string) ($method->missing_measurements_policy ?: 'allow');
        if ($policy === 'block') {
            if (
                ($method->min_weight_kg !== null || $method->max_weight_kg !== null)
                && (bool) ($profile['has_missing_weight'] ?? false)
            ) {
                return false;
            }

            if (
                ($method->max_length_cm !== null || $method->max_width_cm !== null || $method->max_height_cm !== null)
                && (bool) ($profile['has_missing_dimensions'] ?? false)
            ) {
                return false;
            }
        }

        $totalWeight = (float) ($profile['total_weight_kg'] ?? 0);
        if ($method->min_weight_kg !== null && $totalWeight < (float) $method->min_weight_kg) {
            return false;
        }
        if ($method->max_weight_kg !== null && $totalWeight > (float) $method->max_weight_kg) {
            return false;
        }

        foreach ($profile['items'] ?? [] as $item) {
            if (! $this->dimensionsFit($method, $item['dimensions_cm'] ?? [])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, float|null>  $dimensions
     */
    private function dimensionsFit(ShippingMethod $method, array $dimensions): bool
    {
        $limits = [
            $method->max_length_cm !== null ? (float) $method->max_length_cm : null,
            $method->max_width_cm !== null ? (float) $method->max_width_cm : null,
            $method->max_height_cm !== null ? (float) $method->max_height_cm : null,
        ];

        if (collect($limits)->every(static fn ($value): bool => $value === null)) {
            return true;
        }

        if (collect($dimensions)->contains(static fn ($value): bool => $value === null)) {
            return (string) $method->missing_measurements_policy !== 'block';
        }

        $item = array_map(static fn ($value): float => (float) $value, $dimensions);
        $configured = array_map(static fn ($value): float => $value === null ? INF : (float) $value, $limits);

        rsort($item, SORT_NUMERIC);
        rsort($configured, SORT_NUMERIC);

        foreach ($item as $index => $value) {
            if ($value > ($configured[$index] ?? INF)) {
                return false;
            }
        }

        return true;
    }

    private function matchingRate(ShippingMethod $method, float $weight): ?ShippingMethodRate
    {
        $rates = $method->relationLoaded('rates')
            ? $method->rates
            : $method->rates()->get();

        return $rates->first(function (ShippingMethodRate $rate) use ($weight): bool {
            $min = (float) $rate->min_weight_kg;
            $max = $rate->max_weight_kg !== null ? (float) $rate->max_weight_kg : null;

            return $weight >= $min && ($max === null || $weight <= $max);
        });
    }

    /**
     * @return array<int, string>
     */
    private function productLabels(Product $product): array
    {
        $labels = is_array($product->shipping_labels) ? $product->shipping_labels : [];

        if ($product->relationLoaded('categories')) {
            foreach ($product->categories as $category) {
                $payload = is_array($category->payload) ? $category->payload : [];
                $categoryLabels = $payload['shipping_labels'] ?? [];
                if (is_array($categoryLabels)) {
                    $labels = array_merge($labels, $categoryLabels);
                }
            }
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($label): string => trim((string) $label), $labels)
        )));
    }

    /**
     * @return array{weight_kg:float|null,length_cm:float|null,width_cm:float|null,height_cm:float|null}
     */
    private function productMeasurements(Product $product): array
    {
        /** @var ProductPackage|null $package */
        $package = null;
        if ($product->relationLoaded('packages')) {
            $package = $product->packages
                ->first(fn (ProductPackage $row): bool => $row->is_active && $row->is_default)
                ?? $product->packages->first(fn (ProductPackage $row): bool => $row->is_active);
        }

        return [
            'weight_kg' => $this->measurement($product->weight_kg, $package?->weight_kg),
            'length_cm' => $this->measurement($product->length_cm, $package?->length_cm),
            'width_cm' => $this->measurement($product->width_cm, $package?->width_cm),
            'height_cm' => $this->measurement($product->height_cm, $package?->height_cm),
        ];
    }

    private function measurement(mixed $primary, mixed $fallback): ?float
    {
        if (is_numeric($primary) && (float) $primary > 0) {
            return (float) $primary;
        }

        if (is_numeric($fallback) && (float) $fallback > 0) {
            return (float) $fallback;
        }

        return null;
    }
}
