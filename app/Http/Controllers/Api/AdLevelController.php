<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdLevelConfig;

class AdLevelController extends Controller
{
    /**
     * Get all ad levels for public display (ads-info page + link creation dropdown)
     */
    public function index()
    {
        $levels = AdLevelConfig::ordered()->get()->map(function ($level) {
            return [
                'id' => $level->id,
                'name' => $level->name,
                'slug' => $level->slug,
                'description' => $level->description,
                'cpm_rate' => $level->cpm_rate,
                'cpm_rate_display' => $level->cpm_rate_display,
                'demo_url' => $level->demo_url,
                'color_theme' => $level->color_theme,
                'revenue_share' => $level->revenue_share,
                'is_popular' => $level->is_popular,
                'features' => $level->features ?? [],
                'display_order' => $level->display_order,
            ];
        });

        return $this->successResponse($levels, 'Ad levels retrieved');
    }
}
