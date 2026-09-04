<?php

namespace App\Support;

use App\Models\Settings\Local\ShippingMethod;

class GlsShipping
{
    public static function isGlsShippingMethod(ShippingMethod|string|null $method): bool
    {
        if ($method instanceof ShippingMethod) {
            return strtolower(trim((string) $method->carrier)) === 'gls';
        }

        $normalized = self::normalize($method);

        return $normalized !== '' && str_contains($normalized, 'gls');
    }

    public static function isGlsDpmShippingMethod(ShippingMethod|string|null $method): bool
    {
        if ($method instanceof ShippingMethod) {
            return self::isGlsShippingMethod($method)
                && (string) $method->service_type === 'parcel_locker';
        }

        $normalized = self::normalize($method);
        if (! self::isGlsShippingMethod($normalized)) {
            return false;
        }

        foreach ([
            'dpm',
            'paketomat',
            'locker',
            'parcelshop',
            'parcel_shop',
            'parcel-shop',
            'shop',
            'psd',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function glsDpmFilterType(ShippingMethod|string|null $method): ?string
    {
        $normalized = self::normalize($method instanceof ShippingMethod ? $method->code : $method);

        if (! self::isGlsDpmShippingMethod($method)) {
            return null;
        }

        foreach (['paketomat', 'locker'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'parcel-locker';
            }
        }

        foreach (['parcelshop', 'parcel_shop', 'parcel-shop', 'shop'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'parcel-shop';
            }
        }

        return null;
    }

    private static function normalize(?string $code): string
    {
        return strtolower(trim((string) $code));
    }
}
