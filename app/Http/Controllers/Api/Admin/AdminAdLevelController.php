<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdLevelConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AdminAdLevelController extends Controller
{
    /**
     * List all ad levels
     */
    public function index()
    {
        $levels = AdLevelConfig::ordered()->get()->map(function ($level) {
            return [
                'id' => (string) $level->id,
                'levelNumber' => $level->display_order,
                'name' => $level->name,
                'slug' => $level->slug,
                'description' => $level->description,
                'cpcRate' => $level->cpm_rate,
                'cpmRateDisplay' => $level->cpm_rate_display,
                'demoUrl' => $level->demo_url,
                'colorTheme' => $level->color_theme,
                'revenueShare' => $level->revenue_share,
                'isPopular' => $level->is_popular,
                'isEnabled' => $level->is_enabled ?? true,
                'isDefault' => $level->is_default ?? false,
                'isRecommended' => $level->is_recommended ?? false,
                'features' => $level->features ?? [],
                'enabledFeatures' => $level->enabled_features ?? [],
                'featureValues' => $level->feature_values ?? [],
                'createdAt' => $level->created_at,
                'updatedAt' => $level->updated_at,
            ];
        });

        return $this->successResponse($levels, 'Ad levels retrieved');
    }

    /**
     * Get single ad level
     */
    public function show($id)
    {
        $level = AdLevelConfig::findOrFail($id);

        return $this->successResponse([
            'id' => (string) $level->id,
            'levelNumber' => $level->display_order,
            'name' => $level->name,
            'slug' => $level->slug,
            'description' => $level->description,
            'cpcRate' => $level->cpm_rate,
            'cpmRateDisplay' => $level->cpm_rate_display,
            'demoUrl' => $level->demo_url,
            'colorTheme' => $level->color_theme,
            'revenueShare' => $level->revenue_share,
            'isPopular' => $level->is_popular,
            'isEnabled' => $level->is_enabled ?? true,
            'isDefault' => $level->is_default ?? false,
            'isRecommended' => $level->is_recommended ?? false,
            'features' => $level->features ?? [],
            'enabledFeatures' => $level->enabled_features ?? [],
            'featureValues' => $level->feature_values ?? [],
            'createdAt' => $level->created_at,
            'updatedAt' => $level->updated_at,
        ], 'Ad level retrieved');
    }

    /**
     * Update ad level
     */
    public function update(Request $request, $id)
    {
        $level = AdLevelConfig::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'slug' => 'sometimes|nullable|string|max:100|unique:ad_level_configs,slug,' . $id,
            'description' => 'nullable|string|max:500',
            'demoUrl' => 'nullable|url|max:255',
            'colorTheme' => 'nullable|string|in:green,blue,orange,red',
            'revenueShare' => 'nullable|integer|min:0|max:100',
            'isPopular' => 'nullable|boolean',
            'isEnabled' => 'nullable|boolean',
            'features' => 'nullable|array',
            'enabledFeatures' => 'nullable|array',
            'featureValues' => 'nullable|array',
        ]);

        // Map camelCase to snake_case
        $updateData = [];
        if (isset($validated['name'])) $updateData['name'] = $validated['name'];
        if (isset($validated['slug'])) $updateData['slug'] = $validated['slug'];
        if (isset($validated['description'])) $updateData['description'] = $validated['description'];
        if (isset($validated['demoUrl'])) $updateData['demo_url'] = $validated['demoUrl'];
        if (isset($validated['colorTheme'])) $updateData['color_theme'] = $validated['colorTheme'];
        if (isset($validated['revenueShare'])) $updateData['revenue_share'] = $validated['revenueShare'];
        if (isset($validated['isPopular'])) $updateData['is_popular'] = $validated['isPopular'];
        if (isset($validated['isEnabled'])) $updateData['is_enabled'] = $validated['isEnabled'];
        if (isset($validated['features'])) $updateData['features'] = $validated['features'];
        if (isset($validated['enabledFeatures'])) $updateData['enabled_features'] = $validated['enabledFeatures'];
        if (isset($validated['featureValues'])) $updateData['feature_values'] = $validated['featureValues'];

        $level->update($updateData);

        // Invalidate public cache
        Cache::forget('ad_levels_public');

        return $this->successResponse([
            'id' => (string) $level->id,
            'name' => $level->name,
        ], 'Ad level updated');
    }

    /**
     * Toggle ad level enabled status
     */
    public function toggleEnabled($id)
    {
        $level = AdLevelConfig::findOrFail($id);
        $level->is_enabled = !$level->is_enabled;
        $level->save();

        // Invalidate public cache
        Cache::forget('ad_levels_public');

        return $this->successResponse([
            'id' => (string) $level->id,
            'name' => $level->name,
            'isEnabled' => $level->is_enabled,
        ], $level->is_enabled ? 'Ad level enabled' : 'Ad level disabled');
    }

    /**
     * Set ad level as default (for new links)
     */
    public function setDefault($id)
    {
        // Remove default from all levels
        AdLevelConfig::where('is_default', true)->update(['is_default' => false]);

        // Set this level as default
        $level = AdLevelConfig::findOrFail($id);
        $level->is_default = true;
        $level->save();

        // Invalidate public cache
        Cache::forget('ad_levels_public');

        return $this->successResponse([
            'id' => (string) $level->id,
            'name' => $level->name,
            'isDefault' => true,
        ], 'Ad level set as default');
    }

    /**
     * Set ad level as recommended (shows badge)
     */
    public function setRecommended($id)
    {
        // Remove recommended from all levels
        AdLevelConfig::where('is_recommended', true)->update(['is_recommended' => false]);

        // Set this level as recommended
        $level = AdLevelConfig::findOrFail($id);
        $level->is_recommended = true;
        $level->save();

        // Invalidate public cache
        Cache::forget('ad_levels_public');

        return $this->successResponse([
            'id' => (string) $level->id,
            'name' => $level->name,
            'isRecommended' => true,
        ], 'Ad level set as recommended');
    }

    /**
     * Create new ad level (kept for backward compatibility, but UI doesn't use it)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100|unique:ad_level_configs,slug',
            'description' => 'nullable|string|max:500',
            'demoUrl' => 'nullable|url|max:255',
            'colorTheme' => 'nullable|string|in:green,blue,orange,red',
            'revenueShare' => 'nullable|integer|min:0|max:100',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        $level = AdLevelConfig::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'demo_url' => $validated['demoUrl'] ?? null,
            'color_theme' => $validated['colorTheme'] ?? 'blue',
            'revenue_share' => $validated['revenueShare'] ?? 50,
            'is_enabled' => true,
            'display_order' => AdLevelConfig::max('display_order') + 1,
        ]);

        // Invalidate public cache
        Cache::forget('ad_levels_public');

        return $this->successResponse([
            'id' => (string) $level->id,
            'name' => $level->name,
        ], 'Ad level created', 201);
    }

    /**
     * Delete ad level (kept for backward compatibility, but UI doesn't use it)
     */
    public function destroy($id)
    {
        $level = AdLevelConfig::findOrFail($id);
        $name = $level->name;
        $level->delete();

        // Invalidate public cache
        Cache::forget('ad_levels_public');

        return $this->successResponse([
            'deletedId' => $id,
            'name' => $name,
        ], 'Ad level deleted');
    }
}
