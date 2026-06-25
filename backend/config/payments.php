<?php

return [
    'mock' => [
        'webhook_secret' => env('MOCK_PAYMENT_WEBHOOK_SECRET', 'dev_mock_webhook_secret'),
    ],

    // Platform-level policy for landing-page product sales + seller wallet payouts.
    'platform' => [
        // Commission Hellom keeps from each landing-page product sale, in percent.
        // The remainder is credited to the seller's wallet as pending balance.
        'sale_commission_percent' => (float) env('PLATFORM_SALE_COMMISSION_PERCENT', 5),
    ],

    'wallet' => [
        // Minimum amount (IDR) a seller may request to withdraw.
        'min_withdrawal' => (int) env('WALLET_MIN_WITHDRAWAL', 100000),
        // Delay before a sale's pending balance is released to available balance.
        // Hours are added then rolled forward to skip Sat/Sun (weekday-only payouts).
        'settlement_delay_hours' => (int) env('WALLET_SETTLEMENT_DELAY_HOURS', 24),
    ],

    'providers' => [
        'midtrans' => [
            // Set these in .env
            'server_key' => env('MIDTRANS_SERVER_KEY', ''),
            'is_production' => (bool) env('MIDTRANS_IS_PRODUCTION', false),
        ],

        'xendit' => [
            'secret_key' => env('XENDIT_SECRET_KEY', ''),
            'callback_token' => env('XENDIT_CALLBACK_TOKEN', 'dev_xendit_callback_token'),
            'is_production' => (bool) env('XENDIT_IS_PRODUCTION', false),
            'withdrawal_fee_flat' => (int) env('XENDIT_WITHDRAWAL_FEE_FLAT', 5000),
            'policy' => [
                'default' => [
                    'channel' => 'default',
                    'settlement_mode' => 'pending',
                    'settlement_hours' => 24,
                    'payout_hold_hours' => 12,
                    'fee_fixed' => 0,
                    'fee_bps' => 0,
                    'bank_cutoff' => '17:00',
                ],
                'channels' => [
                    'qris' => [
                        'settlement_mode' => 'pending',
                        'settlement_hours' => 24,
                        'payout_hold_hours' => 12,
                        'fee_fixed' => 0,
                        'fee_bps' => 700,
                        'bank_cutoff' => '17:00',
                    ],
                    'va' => [
                        'settlement_mode' => 'pending',
                        'settlement_hours' => 6,
                        'payout_hold_hours' => 6,
                        'fee_fixed' => 4000,
                        'fee_bps' => 0,
                        'bank_cutoff' => '17:00',
                    ],
                    'ewallet' => [
                        'settlement_mode' => 'pending',
                        'settlement_hours' => 12,
                        'payout_hold_hours' => 6,
                        'fee_fixed' => 0,
                        'fee_bps' => 150,
                        'bank_cutoff' => '17:00',
                    ],
                    'card' => [
                        'settlement_mode' => 'pending',
                        'settlement_hours' => 48,
                        'payout_hold_hours' => 24,
                        'fee_fixed' => 0,
                        'fee_bps' => 290,
                        'bank_cutoff' => '17:00',
                    ],
                ],
            ],
        ],

        'ipaymu' => [
            'va' => env('IPAYMU_VA', ''),
            'api_key' => env('IPAYMU_API_KEY', ''),
            'callback_token' => env('IPAYMU_CALLBACK_TOKEN', 'dev_ipaymu_callback_token'),
            'is_production' => (bool) env('IPAYMU_IS_PRODUCTION', false),
        ],

        'doku' => [
            'client_id' => env('DOKU_CLIENT_ID', ''),
            'secret_key' => env('DOKU_SECRET_KEY', ''),
            'callback_token' => env('DOKU_CALLBACK_TOKEN', 'dev_doku_callback_token'),
            'is_production' => (bool) env('DOKU_IS_PRODUCTION', false),
            'payment_method_types' => array_values(array_filter(array_map(
                static fn (string $item): string => trim(strtoupper($item)),
                explode(',', (string) env('DOKU_PAYMENT_METHOD_TYPES', 'VIRTUAL_ACCOUNT_BCA,VIRTUAL_ACCOUNT_BANK_MANDIRI,QRIS'))
            ))),
        ],

        // Future: xendit, duitku, etc.
    ],
];
