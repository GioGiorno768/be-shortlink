<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Level;
use App\Models\View;
use Illuminate\Support\Facades\Cache;

class UserLevelController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. User earnings - REAL-TIME (no cache, changes frequently)
        $currentEarnings = $user->total_earnings;

        // 2. Get all levels - CACHED (config rarely changes)
        $levels = Cache::remember('account_levels_config', 600, function () {
            return Level::orderBy('min_total_earnings', 'asc')->get();
        });

        // 3. Determine current level (computed per-user)
        $currentLevel = $levels->filter(function ($level) use ($currentEarnings) {
            return $currentEarnings >= $level->min_total_earnings;
        })->last();

        // Default to first level if no level reached
        if (!$currentLevel && $levels->isNotEmpty()) {
            $currentLevel = $levels->first();
        }

        // 4. Determine next level (computed per-user)
        $nextLevel = $levels->first(function ($level) use ($currentEarnings) {
            return $level->min_total_earnings > $currentEarnings;
        });

        // 5. Calculate progress data (computed per-user)
        $progressPercent = 0;
        $neededToNext = 0;
        $nextLevelMin = 0;
        $nextLevelCpm = 0;
        $nextLevelName = null;

        if ($nextLevel) {
            $nextLevelMin = $nextLevel->min_total_earnings;
            $nextLevelCpm = $nextLevel->bonus_percentage;
            $nextLevelName = $nextLevel->name;

            $neededToNext = $nextLevelMin - $currentEarnings;

            if ($nextLevelMin > 0) {
                $progressPercent = ($currentEarnings / $nextLevelMin) * 100;
            }

            // Cap at 100%
            if ($progressPercent > 100) $progressPercent = 100;
        } else {
            // Already at max level
            $progressPercent = 100;
        }

        // 6. Find which level first unlocks each feature (for UI display)
        $unlockRequirements = [
            "ad_level_3" => null,
            "ad_level_4" => null,
            "top_countries" => null,
            "top_referrers" => null,
        ];

        foreach ($levels as $level) {
            if ($unlockRequirements["ad_level_3"] === null && $level->unlock_ad_level_3) {
                $unlockRequirements["ad_level_3"] = $level->name;
            }
            if ($unlockRequirements["ad_level_4"] === null && $level->unlock_ad_level_4) {
                $unlockRequirements["ad_level_4"] = $level->name;
            }
            if ($unlockRequirements["top_countries"] === null && $level->unlock_top_countries) {
                $unlockRequirements["top_countries"] = $level->name;
            }
            if ($unlockRequirements["top_referrers"] === null && $level->unlock_top_referrers) {
                $unlockRequirements["top_referrers"] = $level->name;
            }
        }

        // 7. Format card data (user-specific)
        $cardData = [
            "current_level" => $currentLevel ? $currentLevel->slug : "beginner",
            "current_level_name" => $currentLevel ? $currentLevel->name : "Beginner",
            "current_earnings" => $currentEarnings,
            "current_level_cpm" => $currentLevel ? $currentLevel->bonus_percentage : 0,
            "current_level_min" => $currentLevel ? $currentLevel->min_total_earnings : 0,
            "next_level_id" => $nextLevel ? $nextLevel->slug : null,
            "next_level_name" => $nextLevelName,
            "next_level_min" => $nextLevelMin,
            "next_level_cpm" => $nextLevelCpm,
            "needed_to_next_level" => $neededToNext,
            "progress_percent" => round($progressPercent, 1),
            // Feature locks based on current level
            "feature_locks" => [
                "unlock_ad_level_3" => $currentLevel ? (bool) $currentLevel->unlock_ad_level_3 : false,
                "unlock_ad_level_4" => $currentLevel ? (bool) $currentLevel->unlock_ad_level_4 : false,
                "unlock_top_countries" => $currentLevel ? (bool) $currentLevel->unlock_top_countries : false,
                "unlock_top_referrers" => $currentLevel ? (bool) $currentLevel->unlock_top_referrers : false,
                "max_referrals" => $currentLevel ? (int) $currentLevel->max_referrals : 10,
                "monthly_withdrawal_limit" => $currentLevel ? (float) $currentLevel->monthly_withdrawal_limit : 100,
            ],
            // Which level unlocks each feature (for locked UI display)
            "unlock_requirements" => $unlockRequirements,
        ];

        // 7. Format level list (uses cached levels, but locked status is per-user)
        $listData = $levels->map(function ($level) use ($currentEarnings) {
            return [
                "id" => $level->slug,
                "name" => $level->name,
                "icon" => $level->icon,
                "min_earnings" => $level->min_total_earnings,
                "cpm_bonus" => $level->bonus_percentage,
                "benefits" => $level->benefits ?? [],
                "icon_color" => $level->icon_color,
                "bg_color" => $level->bg_color,
                "border_color" => $level->border_color,
                "locked" => $currentEarnings < $level->min_total_earnings, // Computed per-user
            ];
        });

        return $this->successResponse([
            'card' => $cardData,
            'levels' => $listData
        ], 'User level data retrieved');
    }
}
