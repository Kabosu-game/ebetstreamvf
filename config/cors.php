<?php

// Get CORS origins from environment or use defaults
$corsOrigins = env('CORS_ALLOWED_ORIGINS');

// Always allow localhost for local dev (frontend on localhost:5173 calling acmpt.online API)
$allowedOriginsPatterns = [
    '#^http://localhost:\d+$#',
    '#^http://127\.0\.0\.1:\d+$#',
];

if (!$corsOrigins) {
    // Default origins if not set in .env
    $allowedOrigins = [
        'https://ebetstream.com',
        'https://www.ebetstream.com',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:8000',
        'http://127.0.0.1:8000',
    ];
} elseif ($corsOrigins === '*') {
    $allowedOrigins = ['*'];
    $allowedOriginsPatterns = [];
} else {
    $allowedOrigins = array_map('trim', explode(',', $corsOrigins));
}

// Determine supports_credentials based on origins
$supportsCredentials = $corsOrigins && $corsOrigins !== '*';

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => $allowedOriginsPatterns ?? [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => $supportsCredentials,

];
