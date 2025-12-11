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

        // 1. Hitung Total Earnings (Real-time dari tabel views)
        // Kita gunakan logic yang sama dengan StatsController untuk konsistensi
        // 1. Hitung Total Earnings (Optimized: Ambil dari kolom user)
        $currentEarnings = $user->total_earnings;

        // 2. Ambil Semua Level
        $levels = Level::orderBy('min_total_earnings', 'asc')->get();

        // 3. Tentukan Level Saat Ini
        // Level saat ini adalah level dengan min_earnings terbesar yang <= currentEarnings
        $currentLevel = $levels->filter(function ($level) use ($currentEarnings) {
            return $currentEarnings >= $level->min_total_earnings;
        })->last();

        // Jika belum mencapai level apapun (misal earnings 0), default ke level pertama atau null
        if (!$currentLevel && $levels->isNotEmpty()) {
            $currentLevel = $levels->first();
        }

        // 4. Tentukan Level Berikutnya
        $nextLevel = $levels->first(function ($level) use ($currentEarnings) {
            return $level->min_total_earnings > $currentEarnings;
        });

        // 5. Hitung Data Progress
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
            
            // Hitung persentase progress menuju level berikutnya
            // Rumus: (Earnings saat ini / Target Level Berikutnya) * 100
            // Atau range based: ($currentEarnings - $currentLevelMin) / ($nextLevelMin - $currentLevelMin)
            // User request example: progress_percent: 10. 
            // Kita pakai simple percentage dari total target saja agar mudah dipahami, 
            // atau jika user baru mulai, 0/500000 = 0%.
            
            if ($nextLevelMin > 0) {
                $progressPercent = ($currentEarnings / $nextLevelMin) * 100;
            }
            
            // Cap at 100% just in case
            if ($progressPercent > 100) $progressPercent = 100;
        } else {
            // Sudah level max
            $progressPercent = 100;
        }

        // 6. Format Data Card
        $cardData = [
            "current_level" => $currentLevel ? $currentLevel->name : "No Level",
            "current_earnings" => round($currentEarnings, 2),
            "current_level_cpm" => $currentLevel ? $currentLevel->bonus_percentage : 0,
            "current_level_min" => $currentLevel ? $currentLevel->min_total_earnings : 0,
            "next_level_id" => $nextLevelName, // User minta 'next_level_id' tapi isinya nama level
            "next_level_min" => $nextLevelMin,
            "next_level_cpm" => $nextLevelCpm,
            "needed_to_next_level" => round($neededToNext, 2),
            "progress_percent" => round($progressPercent, 1),
        ];

        // 7. Format List Level
        $listData = $levels->map(function ($level) use ($currentEarnings) {
            return [
                "title" => $level->name,
                "min_earnings" => $level->min_total_earnings,
                "cpm_bonus_percent" => $level->bonus_percentage,
                "locked" => $currentEarnings < $level->min_total_earnings,
            ];
        });

        return $this->successResponse([
            'card' => $cardData,
            'levels' => $listData
        ], 'User level data retrieved');
    }
}
