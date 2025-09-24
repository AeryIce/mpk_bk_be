<?php

return [
    // Jalur API yang kena CORS
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // Method yang diizinkan
    'allowed_methods' => ['*'],

    // Origin FE yang boleh akses (isi dari ENV, dipisah koma)
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', '')),

    'allowed_origins_patterns' => [],

    // Header yang diizinkan dari FE
    'allowed_headers' => ['*'],

    // Header yang diekspos balik ke FE
    'exposed_headers' => [],

    // Cache preflight (detik). 0 = non-cache.
    'max_age' => 0,

    // Untuk skenario cookie-based (SPA), set true.
    // Kita pakai Bearer, jadi false saja.
    'supports_credentials' => false,
];
