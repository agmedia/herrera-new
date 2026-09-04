<?php

namespace App\Services\Pricing;

use App\Data\Pricing\InstallmentQuote;
use InvalidArgumentException;

class InstallmentPricingService
{
    /**
     * The PDF says the cart value must be "veće od" the threshold, so all
     * comparisons are deliberately strict (>) rather than inclusive (>=).
     *
     * @var list<array{min:int,max:int,threshold:float,reduction:float}>
     */
    private const RULES = [
        ['min' => 2, 'max' => 6, 'threshold' => 70.0, 'reduction' => 9.0],
        ['min' => 7, 'max' => 12, 'threshold' => 135.0, 'reduction' => 9.0],
        ['min' => 13, 'max' => 24, 'threshold' => 270.0, 'reduction' => 11.0],
        ['min' => 25, 'max' => 36, 'threshold' => 400.0, 'reduction' => 14.0],
    ];

    public function quote(
        float $grossListTotal,
        float $cashDiscountPercent,
        int $installmentCount,
        float $cartTotal,
    ): InstallmentQuote {
        if ($grossListTotal < 0) {
            throw new InvalidArgumentException('Gross list total cannot be negative.');
        }

        if ($cartTotal < 0) {
            throw new InvalidArgumentException('Cart total cannot be negative.');
        }

        if ($cashDiscountPercent < 0 || $cashDiscountPercent > 100) {
            throw new InvalidArgumentException('Cash discount percent must be between 0 and 100.');
        }

        $rule = $this->ruleFor($installmentCount);
        $cashDiscountPercent = round($cashDiscountPercent, 4);
        $installmentDiscountPercent = max(0.0, $cashDiscountPercent - $rule['reduction']);
        // Konto can provide MPC with four decimal places even though the CMS
        // displays two. Keep that source precision during the calculation and
        // round only the monetary results shown to the customer.
        $grossListTotal = round($grossListTotal, 4);
        $cashTotal = round($grossListTotal * (1 - ($cashDiscountPercent / 100)), 2);
        $installmentTotal = round($grossListTotal * (1 - ($installmentDiscountPercent / 100)), 2);
        $paymentSchedule = $this->paymentSchedule($installmentTotal, $installmentCount);

        return new InstallmentQuote(
            installment_count: $installmentCount,
            eligible: $cartTotal > $rule['threshold'],
            eligibility_threshold: $rule['threshold'],
            gross_list_total: $grossListTotal,
            cash_discount_percent: $cashDiscountPercent,
            cash_total: $cashTotal,
            discount_reduction_points: $rule['reduction'],
            installment_discount_percent: $installmentDiscountPercent,
            installment_total: $installmentTotal,
            installment_amount: round($installmentTotal / $installmentCount, 2),
            payment_schedule: $paymentSchedule,
        );
    }

    /** @return list<int> */
    public function availableInstallmentCounts(float $cartTotal): array
    {
        if ($cartTotal < 0) {
            throw new InvalidArgumentException('Cart total cannot be negative.');
        }

        $counts = [];
        foreach (range(2, 36) as $installmentCount) {
            $rule = $this->ruleFor($installmentCount);
            if ($cartTotal > $rule['threshold']) {
                $counts[] = $installmentCount;
            }
        }

        return $counts;
    }

    public function maximumEligibleInstallments(float $cartTotal): ?int
    {
        $counts = $this->availableInstallmentCounts($cartTotal);

        return $counts === [] ? null : max($counts);
    }

    /** @return array{min:int,max:int,threshold:float,reduction:float} */
    private function ruleFor(int $installmentCount): array
    {
        foreach (self::RULES as $rule) {
            if ($installmentCount >= $rule['min'] && $installmentCount <= $rule['max']) {
                return $rule;
            }
        }

        throw new InvalidArgumentException('Installment count must be between 2 and 36.');
    }

    /** @return list<float> */
    private function paymentSchedule(float $total, int $installmentCount): array
    {
        $totalCents = (int) round($total * 100);
        $baseCents = intdiv($totalCents, $installmentCount);
        $remainder = $totalCents % $installmentCount;

        return array_map(
            static fn (int $index): float => ($baseCents + ($index < $remainder ? 1 : 0)) / 100,
            range(0, $installmentCount - 1),
        );
    }
}
