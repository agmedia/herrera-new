<?php

// Confirmed purchase schedule, supplied as a PDF on 9 November 2026.
// Administrators can change these initial values without changing source code.
return [
    'timezone' => 'Europe/Zagreb',
    'customer_days' => 2,
    'holiday_buffer_days' => 5,
    'schedules' => [
        'as-schwabe' => ['as-Schwabe', [1, 15], 7, ['as schwabe', 'schwabe']],
        'vayox' => ['Vayox', [10, 25], 10, []],
        'braytron' => ['Braytron', [], 7, [], 3],
        'dpm' => ['DPM', [5, 20], 14, []],
        'elbi-novalite' => ['Elbi - Novalite', [], 45, ['elbi', 'novalite']],
        'enovatek' => ['Enovatek', [10, 25], 7, ['enovalite']],
        'eti' => ['ETI', [10, 25], 7, []],
        'esperanza' => ['Esperanza', [5, 20], 14, []],
        'gatlinburg' => ['Gatlinburg', [5], 14, []],
        'master' => ['Master - VIP / Vipelectro', [1, 15], 14, ['vip', 'master vip', 'vipelectro', 'master-vip']],
        'mixvill' => ['Mixvill', [1, 15], 7, []],
        'nopallux' => ['Nopallux', [], 14, []],
        'pawbol' => ['Pawbol', [25], 30, []],
        'portwest' => ['Portwest', [1, 15], 7, []],
        'prometeo' => ['Prometeo', [20], 7, []],
        'kuvek' => ['Kuvek', [1], 21, []],
        'struhm' => ['Struhm', [10, 25], 7, []],
        'sve-prom' => ['Sve-prom', [5, 20], 7, []],
        'telur' => ['Telur', [15], 14, []],
        'toshiba' => ['Toshiba', [], 45, []],
        'uni-t' => ['UNI-T', [5, 20], 10, ['unit']],
        'verkatto' => ['Verkatto', [5, 20], 14, []],
        'videx' => ['Videx', [10, 25], 10, []],
        'platinet' => ['Platinet', [15], 14, []],
        'pelsan' => ['Pelsan', [], 45, []],
        'wago' => ['Wago', [1, 15], 7, []],
    ],
];
