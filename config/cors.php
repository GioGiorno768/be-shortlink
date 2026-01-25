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
        'auth/*', 
        '*',
        'sanctum/csrf-cookie', // ✅ Breeze butuh ini
        // 'login',               // ✅ Jika ada
        // 'register',            // ✅ Jika ada
        // 'logout',              // ✅ Jika ada
    ],

    'allowed_methods' => ['*'],
    
    // 'allowed_origins' => ['*'],

    'allowed_origins' => ['https://www.shortlinkmu.site', 'https://shortlinkmu.site', 'https://shortlinkmu.space', 'https://shortlinkmu.com', 'https://www.shortlinkmu.com', 'http://localhost:3000', 'http://localhost:5173', 'https://technosia.web.id', 'https://www.technosia.web.id', 'http://localhost:3001'],

    // env('FRONTEND_URL', 'http://localhost:5173'),
    // 'http://localhost:3000',        // Dev - Main App
    // 'http://localhost:3001',        // Dev - Viewer App
    // 'http://localhost:5173',        // Dev - Vite default
    // // .site domain
    // 'https://shortlinkmu.site',
    // 'https://www.shortlinkmu.site',
    // 'https://api.shortlinkmu.site',
    // // .space domain
    // 'https://shortlinkmu.space',
    // 'https://www.shortlinkmu.space',
    // 'https://api.shortlinkmu.space',
    // // .my.id domain (backend)
    // 'https://slmu.my.id',
    // 'https://www.slmu.my.id',



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

    'supports_credentials' => true, // ✅ True untuk cookie/credentials support

];
