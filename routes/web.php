<?php

use Illuminate\Support\Facades\Route;
use App\Models\Link;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// Guest Link Free Pass + Member Link Redirect
Route::get('/{code}', function ($code) {
    // Reserved paths - don't treat as shortlink
    $reservedPaths = [
        'terms',
        'terms-of-service',
        'privacy',
        'privacy-policy',
        'report',
        'report-abuse',
        'contact',
        'about',
        'faq',
        'payout-rates',
        'ads-info',
        'login',
        'register',
        'forgot-password',
        'reset-password',
        'dashboard',
        'admin',
        'super-admin',
        'notifications',
        'settings',
        'profile',
        'new-link',
        'links',
        'analytics',
        'withdrawal',
        'api',
        'sanctum',
        'backdoor', // Admin backdoor login
        'maintenance', // Maintenance page
    ];

    if (in_array(strtolower($code), $reservedPaths)) {
        abort(404); // Let frontend handle these routes
    }

    $link = Link::where('code', $code)->firstOrFail();

    // 🔗 Capture original referer BEFORE redirect (browser will reset it after redirect)
    $originalReferer = request()->headers->get('referer');
    $refererParam = $originalReferer ? '?ref=' . urlencode($originalReferer) : '';

    // 🔍 DEBUG: Log referer capture
    \Illuminate\Support\Facades\Log::info("🔗 web.php referer capture", [
        'code' => $code,
        'original_referer' => $originalReferer,
        'referer_param' => $refererParam,
    ]);

    // ========================================
    // GUEST LINK: Free Pass Logic
    // ========================================
    if ($link->user_id === null) {
        $currentViews = $link->views ?? 0;

        // Click 1 (views=0): Always direct redirect
        if ($currentViews === 0) {
            $link->increment('views');
            return redirect()->away($link->original_url);
        }

        // Click 2 (views=1): Always show confirmation page
        if ($currentViews === 1) {
            // Redirect to API which handles confirmation + sets next_confirm_at
            return redirect()->away(env('APP_URL', 'http://localhost:8000') . "/api/links/{$code}" . $refererParam);
        }

        // Click 3+ (views>=2): Check against next_confirm_at
        $nextConfirm = $link->next_confirm_at ?? 2;

        if ($currentViews < $nextConfirm) {
            // Free pass - direct redirect
            $link->increment('views');
            return redirect()->away($link->original_url);
        }

        // Time to show confirmation - redirect to API
        return redirect()->away(env('APP_URL', 'http://localhost:8000') . "/api/links/{$code}" . $refererParam);
    }

    // ========================================
    // MEMBER LINK: Redirect to API (handles session + redirect to viewer)
    // ========================================
    return redirect()->away(env('APP_URL', 'http://localhost:8000') . "/api/links/{$code}" . $refererParam);
});

require __DIR__ . '/auth.php';
