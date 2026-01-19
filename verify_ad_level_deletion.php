<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Setting;
use App\Models\Link;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

// 1. Setup Ad Rates
echo "Setting up Ad Rates...\n";
$rates = [
    1 => 0.05,
    2 => 0.07,
    3 => 0.10,
    4 => 0.15,
];
Setting::updateOrCreate(['key' => 'ad_cpc_rates'], ['value' => $rates]);
Cache::forget('app_ad_cpc_rates');

// 2. Create Dummy User & Link
echo "Creating Dummy Link...\n";
$user = User::first();
if (!$user) {
    $user = User::factory()->create();
}

$link = Link::create([
    'user_id' => $user->id,
    'original_url' => 'https://example.com',
    'code' => 'test_level_del_' . time(),
    'ad_level' => 4, // Set to Level 4
]);

echo "Link created with Ad Level: " . $link->ad_level . "\n";

// 3. Call Delete API (Simulated via Controller)
echo "Deleting Level 4...\n";
$controller = new \App\Http\Controllers\Api\Admin\AdminSettingController();
$response = $controller->deleteAdLevel(4);

echo "Response: " . json_encode($response->getData()) . "\n";

// 4. Verify Link Fallback
$link->refresh();
echo "Link Ad Level after deletion: " . $link->ad_level . "\n";

if ($link->ad_level == 3) {
    echo "✅ SUCCESS: Link downgraded to Level 3.\n";
} else {
    echo "❌ FAILED: Link is at level " . $link->ad_level . "\n";
}

// Cleanup
$link->delete();
