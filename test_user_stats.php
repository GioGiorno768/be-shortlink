<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Query user with email containing "0254" or "kevin"
$user = App\Models\User::whereRaw("email LIKE '%kevinragi%'")->first();

if ($user) {
    echo "Found User:\n";
    echo "ID: " . $user->id . "\n";
    echo "Email: " . $user->email . "\n";
    echo "Balance: " . $user->balance . "\n";
    echo "Total Earnings: " . $user->total_earnings . "\n";
    echo "Total Views: " . $user->total_views . "\n";
    echo "Total Valid Views: " . $user->total_valid_views . "\n";

    echo "\n--- User's Views from views table ---\n";
    $views = App\Models\View::whereHas('link', function ($q) use ($user) {
        $q->where('user_id', $user->id);
    })->get();

    echo "Views count: " . $views->count() . "\n";
    echo "Sum of earned: " . $views->sum('earned') . "\n";
    echo "Valid views: " . $views->where('is_valid', true)->count() . "\n";
} else {
    echo "User not found\n";
}
