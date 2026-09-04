<?php

namespace Tests\Unit\Pricing;

use App\Services\Pricing\InstallmentPricingService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InstallmentPricingServiceTest extends TestCase
{
    #[DataProvider('discountBandProvider')]
    public function test_it_reduces_the_cash_discount_by_the_configured_percentage_points(
        int $installments,
        float $expectedReduction,
        float $expectedDiscount,
        float $expectedTotal,
    ): void {
        $quote = (new InstallmentPricingService)->quote(
            grossListTotal: 1000,
            cashDiscountPercent: 20,
            installmentCount: $installments,
            cartTotal: 1000,
        );

        $this->assertSame($expectedReduction, $quote->discount_reduction_points);
        $this->assertSame($expectedDiscount, $quote->installment_discount_percent);
        $this->assertSame($expectedTotal, $quote->installment_total);
        $this->assertSame(800.0, $quote->cash_total);
        $this->assertSame('EUR', $quote->currency_code);
        $this->assertEqualsWithDelta(
            $quote->installment_total,
            array_sum($quote->payment_schedule),
            0.0001,
        );
    }

    public static function discountBandProvider(): array
    {
        return [
            '2-12 installments subtract 9 points' => [12, 9.0, 11.0, 890.0],
            '13-24 installments subtract 11 points' => [24, 11.0, 9.0, 910.0],
            '25-36 installments subtract 14 points' => [36, 14.0, 6.0, 940.0],
        ];
    }

    public function test_installment_discount_is_floored_at_zero(): void
    {
        $quote = (new InstallmentPricingService)->quote(100, 5, 12, 200);

        $this->assertSame(0.0, $quote->installment_discount_percent);
        $this->assertSame(100.0, $quote->installment_total);
        $this->assertSame(95.0, $quote->cash_total);
    }

    public function test_it_keeps_konto_mpc_precision_until_the_final_currency_rounding(): void
    {
        $service = new InstallmentPricingService;

        $twelve = $service->quote(5.6739, 30, 12, 500);
        $twentyFour = $service->quote(5.6739, 30, 24, 500);
        $thirtySix = $service->quote(5.6739, 30, 36, 500);

        $this->assertSame(5.6739, $twelve->gross_list_total);
        $this->assertSame(4.48, $twelve->installment_total);
        $this->assertSame(4.60, $twentyFour->installment_total);
        $this->assertSame(4.77, $thirtySix->installment_total);
    }

    #[DataProvider('strictThresholdProvider')]
    public function test_pdf_thresholds_are_strictly_greater_than(
        int $installments,
        float $threshold,
    ): void {
        $service = new InstallmentPricingService;

        $atBoundary = $service->quote(500, 20, $installments, $threshold);
        $aboveBoundary = $service->quote(500, 20, $installments, $threshold + 0.01);

        $this->assertFalse($atBoundary->eligible);
        $this->assertTrue($aboveBoundary->eligible);
        $this->assertSame($threshold, $atBoundary->eligibility_threshold);
    }

    public static function strictThresholdProvider(): array
    {
        return [
            '2-6 installments require more than 70 EUR' => [6, 70.0],
            '7-12 installments require more than 135 EUR' => [12, 135.0],
            '13-24 installments require more than 270 EUR' => [24, 270.0],
            '25-36 installments require more than 400 EUR' => [36, 400.0],
        ];
    }

    public function test_maximum_installments_follow_each_cart_threshold(): void
    {
        $service = new InstallmentPricingService;

        $this->assertNull($service->maximumEligibleInstallments(70));
        $this->assertSame(6, $service->maximumEligibleInstallments(70.01));
        $this->assertSame(6, $service->maximumEligibleInstallments(135));
        $this->assertSame(12, $service->maximumEligibleInstallments(135.01));
        $this->assertSame(12, $service->maximumEligibleInstallments(270));
        $this->assertSame(24, $service->maximumEligibleInstallments(270.01));
        $this->assertSame(24, $service->maximumEligibleInstallments(400));
        $this->assertSame(36, $service->maximumEligibleInstallments(400.01));
    }

    #[DataProvider('invalidInputProvider')]
    public function test_it_rejects_invalid_inputs(
        float $listTotal,
        float $discount,
        int $installments,
        float $cartTotal,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        (new InstallmentPricingService)->quote($listTotal, $discount, $installments, $cartTotal);
    }

    public static function invalidInputProvider(): array
    {
        return [
            'negative list total' => [-0.01, 10, 12, 200],
            'negative discount' => [100, -0.01, 12, 200],
            'discount over 100' => [100, 100.01, 12, 200],
            'too few installments' => [100, 10, 1, 200],
            'too many installments' => [100, 10, 37, 200],
            'negative cart total' => [100, 10, 12, -0.01],
        ];
    }
}
