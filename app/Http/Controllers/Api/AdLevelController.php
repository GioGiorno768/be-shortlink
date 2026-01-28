<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdLevelConfig;
use App\Models\GlobalFeature;
use Illuminate\Support\Facades\Cache;

class AdLevelController extends Controller
{
    /**
     * Get all ad levels for public display (ads-info page + link creation dropdown)
     * Cached for 10 minutes to reduce database load
     */
    public function index()
    {
        $levels = Cache::remember('ad_levels_public', 600, function () {
            // Get all global features for building the features list
            $globalFeatures = GlobalFeature::ordered()->get();

            // Only show enabled levels to users
            return AdLevelConfig::ordered()
                ->where('is_enabled', true)
                ->get()
                ->map(function ($level) use ($globalFeatures) {
                    // Build features array from global_features + enabled_features + feature_values
                    $enabledFeatureIds = $level->enabled_features ?? [];
                    $featureValues = $level->feature_values ?? [];
                    $features = $globalFeatures->map(function ($gf) use ($enabledFeatureIds, $featureValues, $level) {
                        $featureIdStr = (string) $gf->id;
                        $isIncluded = in_array($featureIdStr, $enabledFeatureIds);

                        // Priority: feature_values > legacy features > default
                        // 1. Check for custom per-level value
                        if (isset($featureValues[$featureIdStr]) && !empty($featureValues[$featureIdStr])) {
                            $value = $featureValues[$featureIdStr];
                        } else {
                            // 2. Fallback to legacy features
                            $legacyFeatures = $level->features ?? [];
                            $legacyFeature = collect($legacyFeatures)->firstWhere('label', $gf->name);
                            $value = $legacyFeature['value'] ?? $isIncluded;
                        }

                        return [
                            'label' => $gf->name,
                            'value' => $value,
                            'included' => $isIncluded,
                        ];
                    })->toArray();

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
                        'is_popular' => $level->is_recommended,
                        'is_default' => $level->is_default ?? false,
                        'features' => $features,
                        'display_order' => $level->display_order,
                    ];
                });
        });

        return $this->successResponse($levels, 'Ad levels retrieved');
    }
}
