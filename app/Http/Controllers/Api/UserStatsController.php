<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class UserStatsController extends Controller
{
    /**
     * Get lightweight header stats for user.
     * Balance & CPM: fresh from users table (fast single row query)
     * Payout: cached for 5 minutes (changes rarely)
     * 
     * Response time: ~10-30ms
     */
    public function headerStats(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        // 🔧 FIX: Always fetch fresh balance & CPM from users table (no cache)
        // Query is fast (~1ms) since it's just reading a single row by primary key
        $user->refresh(); // Ensure we have latest data

        $balance = $user->balance ?? 0;

        // CPM from users table (total_earnings / total_valid_views) * 1000
        $totalViews = $user->total_valid_views ?? 0;
        $totalEarned = $user->total_earnings ?? 0;
        $cpm = $totalViews > 0 ? round(($totalEarned / $totalViews) * 1000, 2) : 0;

        // Payout sum - cached for 5 minutes (changes less frequently)
        $payout = Cache::remember("user:payout:sum:{$userId}", 300, function () use ($userId) {
            return Payout::where('user_id', $userId)
                ->where('status', 'paid')
                ->sum('amount') ?? 0;
        });

        return $this->successResponse([
            'balance' => (float) $balance,
            'payout' => (float) $payout,
            'cpm' => (float) $cpm,
        ], 'Header stats retrieved');
    }

    /**
     * Clear header stats cache (call after balance/payout changes).
     */
    public static function clearCache($userId)
    {
        Cache::forget("user:stats:header:{$userId}");
        Cache::forget("user:payout:sum:{$userId}");
    }
}
