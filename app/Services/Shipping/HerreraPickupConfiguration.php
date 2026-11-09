<?php

namespace App\Services\Shipping;

use App\Models\Settings\Local\ShippingMethod;

class HerreraPickupConfiguration
{
    public function configure(): ShippingMethod
    {
        return ShippingMethod::query()->firstOrCreate(['code' => 'herrera_pickup'], [
            'name' => 'Osobno preuzimanje u skladištu – Križevci',
            'carrier' => 'pickup', 'service_type' => 'pickup', 'pricing_type' => 'free',
            'description' => 'Gospodarska ulica 10, 48260 Križevci. Preuzimanje uz prethodni telefonski dogovor.',
            'price' => 0, 'is_active' => true, 'sort_order' => 100,
            'allows_fragile' => true, 'allows_oversized' => true, 'allows_heavy' => true,
            'missing_measurements_policy' => 'allow',
            'settings' => ['configured_from' => 'herrera-client-2026-11', 'tax_rate_percent' => 0,
                'pickup_address' => ['address_line_1' => 'Gospodarska ulica 10', 'postal_code' => '48260', 'city' => 'Križevci', 'country_code' => 'HR'],
                'coordination' => 'telephone', 'automatic_ready_notification' => false],
        ]);
    }
}
