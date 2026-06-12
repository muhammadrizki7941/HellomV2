<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Dummy auth users (no DB)
    |--------------------------------------------------------------------------
    | Foundation stage only.
    | Passwords are plaintext for local-only skeleton testing.
    */

    'users' => [
        [
            'email' => 'super@demo.test',
            'password' => 'super',
            'name' => 'Super Admin',
            'role' => 'super_admin',
            'allowed_tenants' => ['*'],
        ],
        [
            'email' => 'admin@alpha.test',
            'password' => 'admin',
            'name' => 'Alpha Owner',
            'role' => 'tenant_admin',
            'allowed_tenants' => ['alpha'],
        ],
        [
            'email' => 'cashier@alpha.test',
            'password' => 'cashier',
            'name' => 'Alpha Cashier',
            'role' => 'cashier',
            'allowed_tenants' => ['alpha'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dynamic users (local convenience)
    |--------------------------------------------------------------------------
    | If enabled, new tenants can login without editing this config:
    | - Tenant admin (global login): admin@{tenant}.test / admin
    | - Cashier (tenant cashier login): cashier@{tenant}.test / cashier
    */
    'enabled' => env('DUMMY_AUTH_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Dynamic users (local convenience)
    |--------------------------------------------------------------------------
    | If enabled, new tenants can login without editing this config:
    | - Tenant admin (global login): admin@{tenant}.test / admin
    | - Cashier (tenant cashier login): cashier@{tenant}.test / cashier
    */
    'dynamic_users' => true,
];
