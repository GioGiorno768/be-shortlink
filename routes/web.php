<?php

use Illuminate\Support\Facades\Route;
use App\Models\Link;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// Guest Link Free Pass + Member Link Redirect
Route::get('/{code}', function ($code) {
    $link = Link::where('code', $code)->firstOrFail();

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
            return redirect()->away("http://localhost:8000/api/links/{$code}");
        }

        // Click 3+ (views>=2): Check against next_confirm_at
        $nextConfirm = $link->next_confirm_at ?? 2;

        if ($currentViews < $nextConfirm) {
            // Free pass - direct redirect
            $link->increment('views');
            return redirect()->away($link->original_url);
        }

        // Time to show confirmation - redirect to API
        return redirect()->away("http://localhost:8000/api/links/{$code}");
    }

    // ========================================
    // MEMBER LINK: Redirect to viewer app
    // ========================================
    return redirect()->away("http://localhost:5173/{$code}");
});

require __DIR__ . '/auth.php';
