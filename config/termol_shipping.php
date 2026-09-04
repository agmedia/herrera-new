<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MBE Boxes tariffs
    |--------------------------------------------------------------------------
    |
    | Shipping weights are stored and calculated to three decimal places. The
    | calculator treats both ends of a rate as inclusive. Shared boundaries
    | from the supplied table belong to the band whose "Težina od" value is
    | that boundary, so the preceding maximum ends 0.001 kg earlier.
    |
    */
    'mbe' => [
        'methods' => [
            'mbe_mainland_hr' => [
                'name' => 'MBE Boxes – dostava na kopno',
                'description' => 'Dostava na području Republike Hrvatske (kopno) prema MBE Boxes cjeniku.',
                'destination_scope' => 'hr_mainland',
                'sort_order' => 80,
                'rates' => [
                    ['min_weight_kg' => '0.000', 'max_weight_kg' => '4.999', 'price' => '6.00'],
                    ['min_weight_kg' => '5.000', 'max_weight_kg' => '9.999', 'price' => '7.00'],
                    ['min_weight_kg' => '10.000', 'max_weight_kg' => '19.999', 'price' => '9.50'],
                    ['min_weight_kg' => '20.000', 'max_weight_kg' => '29.999', 'price' => '17.50'],
                    ['min_weight_kg' => '30.000', 'max_weight_kg' => '39.999', 'price' => '20.00'],
                    ['min_weight_kg' => '40.000', 'max_weight_kg' => '49.999', 'price' => '22.50'],
                    ['min_weight_kg' => '50.000', 'max_weight_kg' => '74.999', 'price' => '30.00'],
                    ['min_weight_kg' => '75.000', 'max_weight_kg' => '99.999', 'price' => '35.00'],
                    ['min_weight_kg' => '100.000', 'max_weight_kg' => '199.999', 'price' => '60.00'],
                    ['min_weight_kg' => '200.000', 'max_weight_kg' => '299.999', 'price' => '75.00'],
                    ['min_weight_kg' => '300.000', 'max_weight_kg' => '799.999', 'price' => '100.00'],
                    ['min_weight_kg' => '800.000', 'max_weight_kg' => null, 'price' => '200.00'],
                ],
            ],
            'mbe_islands_hr' => [
                'name' => 'MBE Boxes – dostava na otoke',
                'description' => 'Dostava na hrvatske otoke prema MBE Boxes cjeniku i politici odabranoj u administraciji.',
                'destination_scope' => 'hr_islands',
                'sort_order' => 81,
                'rates' => [
                    ['min_weight_kg' => '0.000', 'max_weight_kg' => '4.999', 'price' => '10.00'],
                    ['min_weight_kg' => '5.000', 'max_weight_kg' => '19.999', 'price' => '12.00'],
                    ['min_weight_kg' => '20.000', 'max_weight_kg' => '29.999', 'price' => '15.00'],
                    ['min_weight_kg' => '30.000', 'max_weight_kg' => '39.999', 'price' => '20.00'],
                    ['min_weight_kg' => '40.000', 'max_weight_kg' => '49.999', 'price' => '30.00'],
                    ['min_weight_kg' => '50.000', 'max_weight_kg' => '74.999', 'price' => '35.00'],
                    ['min_weight_kg' => '75.000', 'max_weight_kg' => '99.999', 'price' => '40.00'],
                    ['min_weight_kg' => '100.000', 'max_weight_kg' => '199.999', 'price' => '55.00'],
                    ['min_weight_kg' => '200.000', 'max_weight_kg' => '299.999', 'price' => '80.00'],
                    ['min_weight_kg' => '300.000', 'max_weight_kg' => '399.999', 'price' => '95.00'],
                    ['min_weight_kg' => '400.000', 'max_weight_kg' => '499.999', 'price' => '120.00'],
                    ['min_weight_kg' => '500.000', 'max_weight_kg' => null, 'price' => '150.00'],
                ],
            ],
        ],
    ],

    'islands' => [
        'setting_key' => 'shipping_hr_island_policy',
        'default_policy' => 'unconnected_only',
    ],

    'pickup' => [
        'code' => 'pickup',
        'name' => 'Osobno preuzimanje – Vinkovci',
        'address' => 'Lapovačka 11A, 32100 Vinkovci',
        'opening_hours' => 'Radnim danom 08:00–16:00',
        'by_arrangement' => true,
    ],

    /*
    | Category codes from page 10 of the Termol webshop requirements. Products
    | in these catalog categories use the existing "shipping_quote" workflow.
    */
    'quote_shipping_category_codes' => [
        '020203',
        '020301',
        '020302',
        '020303',
        '020304',
        '020501',
        '020502',
        '020601',
        '020602',
        '020603',
        '020604',
        '030101',
        '030102',
        '030103',
        '030104',
        '040101',
        '040102',
        '040103',
        '040104',
        '040105',
        '040106',
        '070101',
        '070102',
        '070103',
        '070104',
        '070105',
        '070106',
        '070107',
        '070108',
        '070109',
        '070110',
        '070201',
        '070202',
        '070203',
        '070204',
        '070205',
        '070301',
        '070302',
        '070303',
        '070304',
        '070305',
        '070601',
    ],
];
