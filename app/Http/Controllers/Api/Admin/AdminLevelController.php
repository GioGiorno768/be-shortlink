<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Level;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminLevelController extends Controller
{
    /**
     * List all levels (ordered by min_total_earnings)
     */
    public function index()
    {
        $levels = Level::orderBy('min_total_earnings', 'asc')->get()->map(function ($level, $index) {
            return [
                'id' => $level->slug,
                'no' => $index + 1,
                'name' => $level->name,
                'icon' => $level->icon,
                'minEarnings' => (float) $level->min_total_earnings,
                'cpmBonus' => (float) $level->bonus_percentage,
                'benefits' => $level->benefits ?? [],
                'iconColor' => $level->icon_color,
                'bgColor' => $level->bg_color,
                'borderColor' => $level->border_color,
                'createdAt' => $level->created_at,
                'updatedAt' => $level->updated_at,
            ];
        });

        return $this->successResponse($levels, 'Levels retrieved successfully');
    }

    /**
     * Get single level
     */
    public function show($slug)
    {
        $level = Level::where('slug', $slug)->firstOrFail();

        return $this->successResponse([
            'id' => $level->slug,
            'name' => $level->name,
            'icon' => $level->icon,
            'minEarnings' => (float) $level->min_total_earnings,
            'cpmBonus' => (float) $level->bonus_percentage,
            'benefits' => $level->benefits ?? [],
            'iconColor' => $level->icon_color,
            'bgColor' => $level->bg_color,
            'borderColor' => $level->border_color,
        ], 'Level retrieved');
    }

    /**
     * Create new level
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:50',
            'icon' => 'required|string|max:30',
            'minEarnings' => 'required|numeric|min:0',
            'cpmBonus' => 'required|numeric|min:0|max:100',
            'benefits' => 'nullable|array',
            'benefits.*' => 'string|max:255',
            'iconColor' => 'required|string|max:50',
            'bgColor' => 'required|string|max:50',
            'borderColor' => 'required|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        // Generate unique slug
        $slug = Str::slug($request->name);
        $originalSlug = $slug;
        $counter = 1;
        while (Level::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        $level = Level::create([
            'name' => $request->name,
            'slug' => $slug,
            'icon' => $request->icon,
            'min_total_earnings' => $request->minEarnings,
            'bonus_percentage' => $request->cpmBonus,
            'benefits' => $request->benefits ?? [],
            'icon_color' => $request->iconColor,
            'bg_color' => $request->bgColor,
            'border_color' => $request->borderColor,
        ]);

        // Clear cache
        Cache::forget('account_levels_config');

        return $this->successResponse([
            'id' => $level->slug,
            'name' => $level->name,
            'icon' => $level->icon,
            'minEarnings' => (float) $level->min_total_earnings,
            'cpmBonus' => (float) $level->bonus_percentage,
            'benefits' => $level->benefits ?? [],
            'iconColor' => $level->icon_color,
            'bgColor' => $level->bg_color,
            'borderColor' => $level->border_color,
        ], 'Level created successfully', 201);
    }

    /**
     * Update level
     */
    public function update(Request $request, $slug)
    {
        $level = Level::where('slug', $slug)->firstOrFail();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:50',
            'icon' => 'sometimes|string|max:30',
            'minEarnings' => 'sometimes|numeric|min:0',
            'cpmBonus' => 'sometimes|numeric|min:0|max:100',
            'benefits' => 'nullable|array',
            'benefits.*' => 'string|max:255',
            'iconColor' => 'sometimes|string|max:50',
            'bgColor' => 'sometimes|string|max:50',
            'borderColor' => 'sometimes|string|max:50',
        ]);

        if ($validator->fails()) {
            return $this->errorResponse($validator->errors()->first(), 422);
        }

        // Map camelCase to snake_case
        $updateData = [];
        if ($request->has('name')) $updateData['name'] = $request->name;
        if ($request->has('icon')) $updateData['icon'] = $request->icon;
        if ($request->has('minEarnings')) $updateData['min_total_earnings'] = $request->minEarnings;
        if ($request->has('cpmBonus')) $updateData['bonus_percentage'] = $request->cpmBonus;
        if ($request->has('benefits')) $updateData['benefits'] = $request->benefits;
        if ($request->has('iconColor')) $updateData['icon_color'] = $request->iconColor;
        if ($request->has('bgColor')) $updateData['bg_color'] = $request->bgColor;
        if ($request->has('borderColor')) $updateData['border_color'] = $request->borderColor;

        $level->update($updateData);

        // Clear cache
        Cache::forget('account_levels_config');

        return $this->successResponse([
            'id' => $level->slug,
            'name' => $level->name,
            'icon' => $level->icon,
            'minEarnings' => (float) $level->min_total_earnings,
            'cpmBonus' => (float) $level->bonus_percentage,
            'benefits' => $level->benefits ?? [],
            'iconColor' => $level->icon_color,
            'bgColor' => $level->bg_color,
            'borderColor' => $level->border_color,
        ], 'Level updated successfully');
    }

    /**
     * Delete level
     */
    public function destroy($slug)
    {
        $level = Level::where('slug', $slug)->firstOrFail();

        // Check if any users are at this level
        $usersAtLevel = \App\Models\User::where('current_level_id', $level->id)->count();
        if ($usersAtLevel > 0) {
            return $this->errorResponse(
                "Cannot delete level. {$usersAtLevel} user(s) are currently at this level. Please migrate them first.",
                409
            );
        }

        $level->delete();

        // Clear cache
        Cache::forget('account_levels_config');

        return $this->successResponse(null, 'Level deleted successfully');
    }

    /**
     * Get level statistics
     */
    public function stats()
    {
        $levels = Level::orderBy('min_total_earnings', 'asc')->get();
        $userCounts = \App\Models\User::selectRaw('current_level_id, COUNT(*) as count')
            ->groupBy('current_level_id')
            ->pluck('count', 'current_level_id');

        $stats = [
            'totalLevels' => $levels->count(),
            'maxCpmBonus' => (float) $levels->max('bonus_percentage'),
            'maxThreshold' => (float) $levels->max('min_total_earnings'),
            'totalBenefits' => $levels->sum(fn($l) => count($l->benefits ?? [])),
            'distribution' => $levels->map(fn($l) => [
                'id' => $l->slug,
                'name' => $l->name,
                'usersCount' => $userCounts[$l->id] ?? 0,
            ]),
        ];

        return $this->successResponse($stats, 'Level statistics retrieved');
    }
}
