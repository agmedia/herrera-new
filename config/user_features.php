<?php

return [
    'flags' => [
        'user_tracking_enabled' => true,
        'user_loyalty_enabled' => false,
    ],
    'loyalty' => [
        'points_per_currency' => 1.0,
        'currency_value_per_point' => 0.01,
        'eligible_customer_group_ids' => [],
        'min_order_total' => 0.0,
        'reversal_mode' => 'zero_out',
    ],
];
