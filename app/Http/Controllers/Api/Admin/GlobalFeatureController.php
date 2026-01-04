<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlobalFeature;
use Illuminate\Http\Request;

class GlobalFeatureController extends Controller
{
    /**
     * List all global features
     */
    public function index()
    {
        $features = GlobalFeature::ordered()->get()->map(function ($feature) {
            return [
                'id' => (string) $feature->id,
                'name' => $feature->name,
                'description' => $feature->description,
                'display_order' => $feature->display_order,
            ];
        });

        return $this->successResponse($features, 'Global features retrieved');
    }

    /**
     * Create new global feature
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'display_order' => 'nullable|integer|min:0',
        ]);

        // Auto-set display order if not provided
        $validated['display_order'] = $validated['display_order']
            ?? (GlobalFeature::max('display_order') + 1);

        $feature = GlobalFeature::create($validated);

        return $this->successResponse([
            'id' => (string) $feature->id,
            'name' => $feature->name,
            'description' => $feature->description,
        ], 'Global feature created', 201);
    }

    /**
     * Update global feature
     */
    public function update(Request $request, $id)
    {
        $feature = GlobalFeature::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string|max:500',
            'display_order' => 'nullable|integer|min:0',
        ]);

        $feature->update($validated);

        return $this->successResponse([
            'id' => (string) $feature->id,
            'name' => $feature->name,
            'description' => $feature->description,
        ], 'Global feature updated');
    }

    /**
     * Delete global feature
     */
    public function destroy($id)
    {
        $feature = GlobalFeature::findOrFail($id);
        $name = $feature->name;
        $feature->delete();

        return $this->successResponse([
            'deleted_id' => $id,
            'name' => $name,
        ], 'Global feature deleted');
    }
}
