<?php

namespace App\Data\Pricing;

final readonly class ErpCashPrice
{
    public function __construct(
        public float $gross_list_price,
        public ?float $cash_discount_percent,
        public float $cash_selling_price,
    ) {}

    /**
     * @return array{
     *   erp_gross_list_price: float,
     *   erp_cash_discount_percent: float|null,
     *   erp_cash_selling_price: float
     * }
     */
    public function productAttributes(): array
    {
        return [
            'erp_gross_list_price' => $this->gross_list_price,
            'erp_cash_discount_percent' => $this->cash_discount_percent,
            'erp_cash_selling_price' => $this->cash_selling_price,
        ];
    }
}
