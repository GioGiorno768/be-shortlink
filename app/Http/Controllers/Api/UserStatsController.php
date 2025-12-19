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
     * Optimized: reads from users table + cached payout sum.
     * CPM: calculated from users.total_earnings / users.total_valid_views
     * 
     * Response time: ~10-30ms
     */
    public function headerStats(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        // Cache key for user header stats
        $cacheKey = "user:stats:header:{$userId}";

        // Cache for 2 minutes
        $stats = Cache::remember($cacheKey, 120, function () use ($user, $userId) {
            // Balance from users table (instant)
            $balance = $user->balance ?? 0;

            // CPM from users table (total_earnings / total_valid_views) * 1000
            $totalViews = $user->total_valid_views ?? 0;
            $totalEarned = $user->total_earnings ?? 0;
            $cpm = $totalViews > 0 ? round(($totalEarned / $totalViews) * 1000, 2) : 0;

            // Payout sum - cached separately for 5 minutes (changes less frequently)
            $payout = Cache::remember("user:payout:sum:{$userId}", 300, function () use ($userId) {
                return Payout::where('user_id', $userId)
                    ->where('status', 'paid')
                    ->sum('amount') ?? 0;
            });

            return [
                'balance' => (float) $balance,
                'payout' => (float) $payout,
                'cpm' => (float) $cpm,
            ];
        });

        return $this->successResponse($stats, 'Header stats retrieved');
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
