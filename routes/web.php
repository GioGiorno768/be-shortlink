<?php

use Illuminate\Support\Facades\Route;
use App\Models\Link;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// merubah route
Route::get('/{code}', function ($code) {
    $link = Link::where('code', $code)->firstOrFail();
    // Redirect ke blogspot landing page, bukan langsung ke original_url
     if ($link->user_id === null) {
        // langsung redirect ke URL asli
        return redirect()->away($link->original_url);
    }
    return redirect()->away("http://localhost:5173/{$code}");
});

require __DIR__.'/auth.php';
