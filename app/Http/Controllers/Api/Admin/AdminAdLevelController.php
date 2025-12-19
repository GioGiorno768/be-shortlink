<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdLevelConfig;
use Illuminate\Http\Request;
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

    /**
     * Create new ad level
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100|unique:ad_level_configs,slug',
            'description' => 'nullable|string|max:500',
            'demo_url' => 'nullable|url|max:255',
            'color_theme' => 'nullable|string|in:green,blue,orange,red',
            'revenue_share' => 'nullable|integer|min:0|max:100',
            'is_popular' => 'nullable|boolean',
            'features' => 'nullable|array',
            'display_order' => 'nullable|integer|min:0',
        ]);

        // Auto-generate slug if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
        }

        // Set defaults
        $validated['color_theme'] = $validated['color_theme'] ?? 'blue';
        $validated['revenue_share'] = $validated['revenue_share'] ?? 50;
        $validated['is_popular'] = $validated['is_popular'] ?? false;
        $validated['display_order'] = $validated['display_order'] ?? (AdLevelConfig::max('display_order') + 1);

        $level = AdLevelConfig::create($validated);

        return $this->successResponse([
            'id' => $level->id,
            'name' => $level->name,
            'slug' => $level->slug,
        ], 'Ad level created', 201);
    }

    /**
     * Get single ad level
     */
    public function show($id)
    {
        $level = AdLevelConfig::findOrFail($id);

        return $this->successResponse([
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
            'demo_url' => 'nullable|url|max:255',
            'color_theme' => 'nullable|string|in:green,blue,orange,red',
            'revenue_share' => 'nullable|integer|min:0|max:100',
            'is_popular' => 'nullable|boolean',
            'features' => 'nullable|array',
            'display_order' => 'nullable|integer|min:0',
        ]);

        $level->update($validated);

        return $this->successResponse([
            'id' => $level->id,
            'name' => $level->name,
            'slug' => $level->slug,
        ], 'Ad level updated');
    }

    /**
     * Delete ad level
     */
    public function destroy($id)
    {
        $level = AdLevelConfig::findOrFail($id);
        $name = $level->name;
        $level->delete();

        return $this->successResponse([
            'deleted_id' => $id,
            'name' => $name,
        ], 'Ad level deleted');
    }
}
