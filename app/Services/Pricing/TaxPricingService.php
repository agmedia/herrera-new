<?php

namespace App\Services\Pricing;

use App\Models\Catalog\Product\Product;
use App\Models\Settings\Local\TaxRate;
use App\Services\Settings\SystemSettingsService;

class TaxPricingService
{
    public function __construct(
        private readonly SystemSettingsService $settings
    ) {
    }

    public function resolveRateForProduct(?Product $product = null): ?TaxRate
    {
        if ($product && $product->relationLoaded('taxRate')) {
            $taxRate = $product->taxRate;
            if ($taxRate && (bool) $taxRate->is_active) {
                return $taxRate;
            }
        }

        if ($product && $product->tax_rate_id) {
            $taxRate = TaxRate::query()
                ->where('id', (int) $product->tax_rate_id)
                ->where('is_active', true)
                ->first();

            if ($taxRate) {
                return $taxRate;
            }
        }

        return TaxRate::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function grossFromNet(float $netAmount, ?Product $product = null, ?TaxRate $taxRate = null): float
    {
        $rate = $taxRate ?: $this->resolveRateForProduct($product);

        if (! $rate) {
            return round($netAmount, 2);
        }

        $value = (float) ($rate->rate ?? 0);
        if ((string) $rate->rate_type === 'fixed') {
            return round(max(0.0, $netAmount + max(0.0, $value)), 2);
        }

        return round(max(0.0, $netAmount * (1 + (max(0.0, $value) / 100))), 2);
    }

    public function grossFromStored(float $storedAmount, ?Product $product = null, ?TaxRate $taxRate = null): float
    {
        if ($this->pricesIncludeTax()) {
            return round(max(0.0, $storedAmount), 2);
        }

        return $this->grossFromNet($storedAmount, $product, $taxRate);
    }

    public function taxFromNet(float $netAmount, ?Product $product = null, ?TaxRate $taxRate = null): float
    {
        $gross = $this->grossFromNet($netAmount, $product, $taxRate);

        return round(max(0.0, $gross - $netAmount), 2);
    }

    public function netFromGross(float $grossAmount, ?Product $product = null, ?TaxRate $taxRate = null): float
    {
        $rate = $taxRate ?: $this->resolveRateForProduct($product);
        if (! $rate) {
            return round(max(0.0, $grossAmount), 2);
        }

        $value = (float) ($rate->rate ?? 0);
        if ((string) $rate->rate_type === 'fixed') {
            return round(max(0.0, $grossAmount - max(0.0, $value)), 2);
        }

        $percent = max(0.0, $value) / 100;
        if ($percent <= 0.0) {
            return round(max(0.0, $grossAmount), 2);
        }

        return round(max(0.0, $grossAmount / (1 + $percent)), 2);
    }

    public function normalizeNetPrice(float $storedAmount, ?Product $product = null, ?TaxRate $taxRate = null): float
    {
        return $this->pricesIncludeTax()
            ? $this->netFromGross($storedAmount, $product, $taxRate)
            : round(max(0.0, $storedAmount), 2);
    }

    public function taxFromStored(float $storedAmount, ?Product $product = null, ?TaxRate $taxRate = null): float
    {
        if ($this->pricesIncludeTax()) {
            $net = $this->netFromGross($storedAmount, $product, $taxRate);

            return round(max(0.0, $storedAmount - $net), 2);
        }

        return $this->taxFromNet($storedAmount, $product, $taxRate);
    }

    public function pricesIncludeTax(): bool
    {
        return (bool) filter_var(
            $this->settings->get('store_pricing_prices_include_tax', false),
            FILTER_VALIDATE_BOOL
        );
    }
}
