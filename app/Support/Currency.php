<?php

namespace App\Support;

final class Currency
{
    /**
     * @param float|int|string $amount
     */
    public static function format(float|int|string $amount, ?string $currencyCode = 'EUR', int $decimals = 2): string
    {
        $numeric = (float) $amount;
        $formatted = number_format($numeric, $decimals);
        $symbol = self::symbol($currencyCode);

        return $formatted.' '.$symbol;
    }

    public static function symbol(?string $currencyCode = 'EUR'): string
    {
        return match (strtoupper((string) $currencyCode)) {
            'EUR' => '€',
            'USD' => '$',
            'GBP' => '£',
            'CHF' => 'CHF',
            default => strtoupper((string) ($currencyCode ?: 'EUR')),
        };
    }
}
