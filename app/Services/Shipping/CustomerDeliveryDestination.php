<?php

namespace App\Services\Shipping;

use App\Models\User;
use App\Models\User\UserAddress;
use App\Support\CountryCatalog;

class CustomerDeliveryDestination
{
    public function country(?User $user = null, ?string $requested = null): string
    {
        $requested = strtoupper(trim((string) $requested));
        if ($requested !== '' && in_array($requested, CountryCatalog::codes(), true)) {
            return $requested;
        }
        if ($user) {
            $user->loadMissing(['addresses', 'b2bAccount']);
            $candidates = [
                $user->addresses->firstWhere('type', UserAddress::TYPE_SHIPPING)?->country_code,
                $user->addresses->firstWhere('type', UserAddress::TYPE_BILLING)?->country_code,
                $user->b2bAccount?->country_code,
            ];
            foreach ($candidates as $candidate) {
                $country = strtoupper(trim((string) $candidate));
                if (in_array($country, CountryCatalog::codes(), true)) {
                    return $country;
                }
            }
        }

        return 'HR';
    }
}
