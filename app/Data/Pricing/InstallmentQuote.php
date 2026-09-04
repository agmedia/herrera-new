<?php

namespace App\Data\Pricing;

final readonly class InstallmentQuote
{
    /**
     * @param  list<float>  $payment_schedule
     */
    public function __construct(
        public int $installment_count,
        public bool $eligible,
        public float $eligibility_threshold,
        public float $gross_list_total,
        public float $cash_discount_percent,
        public float $cash_total,
        public float $discount_reduction_points,
        public float $installment_discount_percent,
        public float $installment_total,
        public float $installment_amount,
        public array $payment_schedule,
        public string $currency_code = 'EUR',
    ) {}
}
