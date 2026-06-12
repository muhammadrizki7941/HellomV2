<?php

return [
    'enabled' => env('REALTIME_ENABLED', true),

    // Internal server URL that Laravel uses to POST events.
    // Local example: http://127.0.0.1:3001
    'server_url' => env('REALTIME_SERVER_URL', 'http://127.0.0.1:3001'),

    // Public URL for browsers to connect to Socket.IO.
    // Local example: http://127.0.0.1:3001
    'public_url' => env('REALTIME_PUBLIC_URL', env('REALTIME_SERVER_URL', 'http://127.0.0.1:3001')),

    // Shared secret to prevent arbitrary event injection.
    'secret' => env('REALTIME_SERVER_SECRET'),

    'timeout_seconds' => env('REALTIME_TIMEOUT_SECONDS', 1),
];
