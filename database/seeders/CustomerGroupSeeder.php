<?php

namespace Database\Seeders;

use App\Models\User\CustomerGroup;
use Illuminate\Database\Seeder;

class CustomerGroupSeeder extends Seeder
{
    public function run(): void
    {
        $groups = [
            ['code' => 'retail', 'name' => 'Retail', 'description' => 'Default customer group.', 'is_default' => true, 'sort_order' => 10],
            ['code' => 'b2b', 'name' => 'B2B', 'description' => 'Business customers with special pricing.', 'is_default' => false, 'sort_order' => 20],
            ['code' => 'vip', 'name' => 'VIP', 'description' => 'Loyal or high-value customers.', 'is_default' => false, 'sort_order' => 30],
        ];

        foreach ($groups as $group) {
            CustomerGroup::query()->updateOrCreate(
                ['code' => $group['code']],
                $group + [
                    'is_active' => true,
                    'payload' => null,
                ]
            );
        }
    }
}

