<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for cross-origin resource sharing. Configured for production
    | with shortlinkmu.com (frontend) and shortlinkmu.space (backend).
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'register',
        'logout',
    ],

    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Production domains only. Localhost entries removed for security.
    | Add localhost back only for local development.
    |
    */
    'allowed_origins' => array_filter([
        // Production domains
        env('FRONTEND_URL', 'https://shortlinkmu.com'),
        env('VIEWER_URL', 'https://go.shortlinkmu.com'),
        'https://shortlinkmu.com',
        'https://www.shortlinkmu.com',
        'https://go.shortlinkmu.com',
        
        // Development (only enable when needed)
        // 'http://localhost:3000',
        // 'http://localhost:3001',
    ]),

    'allowed_origins_patterns' => [
        // Allow all subdomains of shortlinkmu.com
        '#^https?://.*\.shortlinkmu\.com$#',
    ],

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Authorization',
        'Accept',
        'Origin',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
    ],

    'exposed_headers' => [],

    // Cache preflight request for 24 hours
    'max_age' => 86400,

    // Required for cookie/credentials support (Sanctum)
    'supports_credentials' => true,

];

