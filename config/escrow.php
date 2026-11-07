<?php

return [
    'currency' => env('ESCROW_CURRENCY', 'USD'),
    'fee_bps' => (int) env('ESCROW_FEE_BPS', 500),
];
