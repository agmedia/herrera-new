<?php

namespace App\Services\Pricing;

use App\Data\Pricing\ErpCashPrice;
use App\Models\Catalog\Product\Product;
use InvalidArgumentException;

class ErpCashPricingService
{
    public function __construct(
        private readonly TaxPricingService $taxPricing,
    ) {}

    public function calculate(float $grossListPrice, ?float $cashDiscountPercent): ErpCashPrice
    {
        if ($grossListPrice < 0) {
            throw new InvalidArgumentException('ERP gross list price cannot be negative.');
        }

        if ($cashDiscountPercent !== null && ($cashDiscountPercent < 0 || $cashDiscountPercent > 100)) {
            throw new InvalidArgumentException('ERP cash discount percent must be between 0 and 100.');
        }

        $grossListPrice = round($grossListPrice, 4);
        $normalizedDiscount = $cashDiscountPercent === null
            ? null
            : round($cashDiscountPercent, 4);
        $effectiveDiscount = $normalizedDiscount ?? 0.0;
        $cashSellingPrice = round(
            $grossListPrice * (1 - ($effectiveDiscount / 100)),
            2,
        );

        return new ErpCashPrice(
            gross_list_price: $grossListPrice,
            cash_discount_percent: $normalizedDiscount,
            cash_selling_price: $cashSellingPrice,
        );
    }

    /**
     * Build the attributes an importer can pass to Product::update().
     *
     * ERP values are always stored as gross EUR amounts. The existing base_price
     * remains the canonical storefront price and follows the shop tax-storage setting.
     *
     * @return array{
     *   erp_gross_list_price: float,
     *   erp_cash_discount_percent: float|null,
     *   erp_cash_selling_price: float,
     *   base_price: float
     * }
     */
    public function attributesForProduct(
        Product $product,
        float $grossListPrice,
        ?float $cashDiscountPercent,
    ): array {
        $price = $this->calculate($grossListPrice, $cashDiscountPercent);
        $storedBasePrice = $this->taxPricing->pricesIncludeTax()
            ? $price->cash_selling_price
            : $this->taxPricing->netFromGross($price->cash_selling_price, $product);

        return [
            ...$price->productAttributes(),
            'base_price' => round($storedBasePrice, 2),
        ];
    }
}
