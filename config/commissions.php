<?php

/*
|--------------------------------------------------------------------------
| Commission rate presets
|--------------------------------------------------------------------------
|
| Ready-made tier sets an administrator can apply in one step from the
| dashboard. Applying a preset retires every active tier and creates these
| bands in its place; bookings already made keep their own snapshotted rate.
|
| Amounts are Philippine pesos, both bounds are inclusive, and a null
| max_amount is the open-ended top band — the same rules as a tier entered
| by hand. Each preset must cover every amount from 0 without overlaps.
|
*/

return [
    'presets' => [
        'standard' => [
            'name' => 'Standard',
            'description' => 'Higher rates for larger bookings: 5%, 10%, 15% and 20%.',
            'tiers' => [
                ['name' => 'Under ₱200', 'min_amount' => 0, 'max_amount' => 199.99, 'percentage' => 5],
                ['name' => '₱200 to ₱499', 'min_amount' => 200, 'max_amount' => 499.99, 'percentage' => 10],
                ['name' => '₱500 to ₱999', 'min_amount' => 500, 'max_amount' => 999.99, 'percentage' => 15],
                ['name' => '₱1,000 and above', 'min_amount' => 1000, 'max_amount' => null, 'percentage' => 20],
            ],
        ],
        'flat_10' => [
            'name' => 'Flat 10%',
            'description' => 'The same 10% on every booking, whatever its amount.',
            'tiers' => [
                ['name' => 'All bookings', 'min_amount' => 0, 'max_amount' => null, 'percentage' => 10],
            ],
        ],
    ],
];
