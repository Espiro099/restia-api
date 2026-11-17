<?php

// CORS (D-36, sección 6): orígenes explícitos y con credenciales, nunca `*`. RESTIA_CORS_ORIGINS es una
// lista separada por comas; por omisión, el panel en desarrollo.
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('RESTIA_CORS_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Content-Type', 'If-Match', 'Idempotency-Key', 'X-Request-ID', 'X-XSRF-TOKEN', 'X-Requested-With'],
    'exposed_headers' => ['ETag', 'X-Request-ID', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => true,
];
