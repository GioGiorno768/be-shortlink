<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */


    'paths' => [
        'api/*',
        'sanctum/csrf-cookie', // ✅ Breeze butuh ini
        'login',               // ✅ Jika ada
        'register',            // ✅ Jika ada
        'logout',              // ✅ Jika ada
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'http://localhost:5173'),
        'http://localhost:3000', // Main App (shortlinku)
        'http://localhost:3001', // Viewer App (shortlinkmu-viewer)
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'X-Requested-With',
        'Authorization',
        'Accept',
        'Origin',
    ],


    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false, // ✅ False untuk pure token-based

];
