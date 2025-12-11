<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$link = \App\Models\Link::where('code', 'HLrvWqY')->first();

if ($link) {
    echo "=== LINK DEBUG INFO ===" . PHP_EOL;
    echo "Code: " . $link->code . PHP_EOL;
    echo "Expired At: " . ($link->expired_at ? $link->expired_at->toDateTimeString() : 'NULL') . PHP_EOL;
    echo "Status: " . $link->status . PHP_EOL;
    echo "Is Banned: " . ($link->is_banned ? 'YES' : 'NO') . PHP_EOL;
    echo "Current Time: " . now()->toDateTimeString() . PHP_EOL;

    if ($link->expired_at) {
        $isExpired = now()->greaterThan($link->expired_at);
        echo "Is Expired: " . ($isExpired ? 'YES ❌' : 'NO ✅') . PHP_EOL;

        if (!$isExpired) {
            $diff = now()->diffForHumans($link->expired_at, true);
            echo "Expires in: " . $diff . PHP_EOL;
        } else {
            $diff = $link->expired_at->diffForHumans(now(), true);
            echo "Expired since: " . $diff . " ago" . PHP_EOL;
        }
    } else {
        echo "No expiration set" . PHP_EOL;
    }
} else {
    echo "Link not found" . PHP_EOL;
}
