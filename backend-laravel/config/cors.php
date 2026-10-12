<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie', 'sanctum/*'],

    'allowed_methods' => ['*'],

    // Deployment-specific origins come from APP_URL, FRONTEND_URL and the
    // optional CORS_ALLOWED_ORIGINS list (comma-separated, for split-origin
    // setups). The Vite dev-server origins are only added when APP_ENV=local so
    // a production host never allow-lists localhost. The admin console is
    // served from the same origin as the API (nginx serves /admin/ and /api/),
    // so in the default deployment this list is just APP_URL/FRONTEND_URL.
    'allowed_origins' => array_values(array_unique(array_filter(array_merge(
        array_map('trim', array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))),
        [env('APP_URL'), env('FRONTEND_URL')],
        env('APP_ENV') === 'local' ? [
            'http://localhost:5173',
            'http://127.0.0.1:5173',
            'http://localhost:8080',
            'http://127.0.0.1:8080',
        ] : [],
    )))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-XSRF-TOKEN', 'Authorization', 'Accept', 'Origin', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
