<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ViolationReferrer;
use App\Models\LinkViolation;
use App\Services\ViolationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ViolationReferrerController extends Controller
{
    protected ViolationService $violationService;

    public function __construct(ViolationService $violationService)
    {
        $this->violationService = $violationService;
    }

    /**
     * List all violation referrers
     */
    public function index(Request $request)
    {
        $referrers = ViolationReferrer::orderBy('domain')
            ->get()
            ->map(function ($referrer) {
                return [
                    'id' => $referrer->id,
                    'domain' => $referrer->domain,
                    'name' => $referrer->name,
                    'is_active' => $referrer->is_active,
                    'created_at' => $referrer->created_at->toISOString(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $referrers,
        ]);
    }

    /**
     * Add a new violation referrer
     */
    public function store(Request $request)
    {
        $request->validate([
            'domain' => 'required|string|max:255|unique:violation_referrers,domain',
            'name' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        // Clean domain (remove protocol and www)
        $domain = $this->cleanDomain($request->domain);

        $referrer = ViolationReferrer::create([
            'domain' => $domain,
            'name' => $request->name ?? $domain,
            'is_active' => $request->is_active ?? true,
            'created_by' => Auth::id(),
        ]);

        // Clear cache
        $this->violationService->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Violation referrer added successfully.',
            'data' => [
                'id' => $referrer->id,
                'domain' => $referrer->domain,
                'name' => $referrer->name,
                'is_active' => $referrer->is_active,
            ],
        ], 201);
    }

    /**
     * Update a violation referrer
     */
    public function update(Request $request, $id)
    {
        $referrer = ViolationReferrer::findOrFail($id);

        $request->validate([
            'domain' => 'sometimes|string|max:255|unique:violation_referrers,domain,' . $id,
            'name' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        if ($request->has('domain')) {
            $referrer->domain = $this->cleanDomain($request->domain);
        }
        if ($request->has('name')) {
            $referrer->name = $request->name;
        }
        if ($request->has('is_active')) {
            $referrer->is_active = $request->is_active;
        }

        $referrer->save();

        // Clear cache
        $this->violationService->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Violation referrer updated successfully.',
            'data' => [
                'id' => $referrer->id,
                'domain' => $referrer->domain,
                'name' => $referrer->name,
                'is_active' => $referrer->is_active,
            ],
        ]);
    }

    /**
     * Delete a violation referrer
     */
    public function destroy($id)
    {
        $referrer = ViolationReferrer::findOrFail($id);
        $referrer->delete();

        // Clear cache
        $this->violationService->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Violation referrer deleted successfully.',
        ]);
    }

    /**
     * Get violation settings
     */
    public function getSettings()
    {
        $settings = $this->violationService->getViolationSettings();

        return response()->json([
            'status' => 'success',
            'data' => $settings,
        ]);
    }

    /**
     * Update violation settings
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'penalty_percent' => 'integer|min:0|max:100',
            'threshold' => 'integer|min:1|max:100',
            'penalty_days' => 'integer|min:1|max:365',
            'auto_disable' => 'boolean',
            'auto_disable_threshold' => 'integer|min:1|max:1000',
        ]);

        $currentSettings = $this->violationService->getViolationSettings();
        $newSettings = array_merge($currentSettings, $request->only([
            'penalty_percent',
            'threshold',
            'penalty_days',
            'auto_disable',
            'auto_disable_threshold',
        ]));

        $this->violationService->updateViolationSettings($newSettings);

        return response()->json([
            'status' => 'success',
            'message' => 'Violation settings updated successfully.',
            'data' => $newSettings,
        ]);
    }

    /**
     * Get violation stats and recent violations
     */
    public function stats()
    {
        $totalViolations = LinkViolation::count();
        $totalViolationCount = LinkViolation::sum('violation_count');
        $affectedLinks = LinkViolation::distinct('link_id')->count('link_id');
        $affectedUsers = LinkViolation::distinct('user_id')->count('user_id');

        // Top violation referrers
        $topReferrers = LinkViolation::selectRaw('referrer_domain, SUM(violation_count) as total')
            ->groupBy('referrer_domain')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        // Recent violations
        $recentViolations = LinkViolation::with(['link:id,code,title', 'user:id,name,email'])
            ->orderByDesc('last_detected_at')
            ->limit(20)
            ->get()
            ->map(function ($v) {
                return [
                    'id' => $v->id,
                    'link' => [
                        'id' => $v->link->id ?? null,
                        'code' => $v->link->code ?? null,
                        'title' => $v->link->title ?? null,
                    ],
                    'user' => [
                        'id' => $v->user->id ?? null,
                        'name' => $v->user->name ?? null,
                        'email' => $v->user->email ?? null,
                    ],
                    'referrer_domain' => $v->referrer_domain,
                    'violation_count' => $v->violation_count,
                    'first_detected_at' => $v->first_detected_at?->toISOString(),
                    'last_detected_at' => $v->last_detected_at?->toISOString(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'total_violations' => $totalViolations,
                'total_violation_count' => $totalViolationCount,
                'affected_links' => $affectedLinks,
                'affected_users' => $affectedUsers,
                'top_referrers' => $topReferrers,
                'recent_violations' => $recentViolations,
            ],
        ]);
    }

    /**
     * Clean domain from URL input
     */
    private function cleanDomain(string $input): string
    {
        // Remove protocol
        $domain = preg_replace('#^https?://#', '', $input);
        // Remove www.
        $domain = preg_replace('#^www\.#', '', $domain);
        // Remove path
        $domain = explode('/', $domain)[0];
        // Lowercase
        return strtolower(trim($domain));
    }
}
