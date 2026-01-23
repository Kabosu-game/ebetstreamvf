<?php

// Get CORS origins from environment or use defaults
$corsOrigins = env('CORS_ALLOWED_ORIGINS');

if (!$corsOrigins) {
    // Default origins if not set in .env
    $allowedOrigins = [
        'https://ebetstream.com',
        'https://www.ebetstream.com',
        'http://localhost:5173', // Keep for local dev
        'http://127.0.0.1:5173',
        'http://localhost:8000', // Laravel backend (if needed)
        'http://127.0.0.1:8000',
    ];
    // Add pattern to allow any localhost port for development
    $allowedOriginsPatterns = [
        '#^http://localhost:\d+$#',
        '#^http://127\.0\.0\.1:\d+$#',
    ];
} elseif ($corsOrigins === '*') {
    $allowedOrigins = ['*'];
    $allowedOriginsPatterns = [];
} else {
    $allowedOrigins = array_map('trim', explode(',', $corsOrigins));
    // Check if any localhost origins are in the list
    $hasLocalhost = false;
    foreach ($allowedOrigins as $origin) {
        if (strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false) {
            $hasLocalhost = true;
            break;
        }
    }
    // If localhost is in the list, add patterns for any port
    if ($hasLocalhost) {
        $allowedOriginsPatterns = [
            '#^http://localhost:\d+$#',
            '#^http://127\.0\.0\.1:\d+$#',
        ];
    } else {
        $allowedOriginsPatterns = [];
    }
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
