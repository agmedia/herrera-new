<?php

namespace Database\Seeders;

use App\Services\Settings\SystemSettingsService;
use Illuminate\Database\Seeder;

class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<string, mixed> $defaults */
        $defaults = array_merge(
            config('admin_ui.pagination', []),
            config('catalog_features.flags', []),
            config('user_features.flags', []),
            [
                'loyalty_points_per_currency' => (float) config('user_features.loyalty.points_per_currency', 1.0),
                'loyalty_currency_value_per_point' => (float) config('user_features.loyalty.currency_value_per_point', 0.01),
                'loyalty_customer_group_ids' => (array) config('user_features.loyalty.eligible_customer_group_ids', []),
                'loyalty_min_order_total' => (float) config('user_features.loyalty.min_order_total', 0.0),
                'loyalty_reversal_mode' => (string) config('user_features.loyalty.reversal_mode', 'zero_out'),
            ]
        );

        $settings = app(SystemSettingsService::class);
        $settings->putMany($defaults);

        $islandPolicyKey = (string) config('termol_shipping.islands.setting_key', 'shipping_hr_island_policy');
        if (! array_key_exists($islandPolicyKey, $settings->all())) {
            $settings->put(
                $islandPolicyKey,
                (string) config('termol_shipping.islands.default_policy', 'unconnected_only'),
            );
        }
    }
}
