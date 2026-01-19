<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Link;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class GeneralSettingsController extends Controller
{
    /**
     * Default general settings
     */
    private $defaultSettings = [
        'maintenance_mode' => false,
        'maintenance_estimated_time' => '2-3 jam',
        'maintenance_whitelist_ips' => '',
        'disable_registration' => false,
        'invite_only_mode' => false,
        'disable_login' => false,
        'cleanup_expired_links_days' => 30,
        'cleanup_blocked_links_days' => 7,
        'cleanup_old_notifications_days' => 30,
        'backdoor_access_code' => 'admin123', // Default backdoor access code
    ];

    /**
     * Get general settings
     */
    public function getSettings()
    {
        $settings = Cache::remember('general_settings', 300, function () {
            $setting = Setting::where('key', 'general_settings')->first();
            return $setting ? $setting->value : $this->defaultSettings;
        });

        return $this->successResponse($settings, 'General settings retrieved');
    }

    /**
     * Get access settings (public - no auth required)
     */
    public function getAccessSettings()
    {
        $settings = Cache::remember('general_settings', 300, function () {
            $setting = Setting::where('key', 'general_settings')->first();
            return $setting ? $setting->value : $this->defaultSettings;
        });

        return $this->successResponse([
            'disable_registration' => $settings['disable_registration'] ?? false,
            'disable_login' => $settings['disable_login'] ?? false,
            'invite_only_mode' => $settings['invite_only_mode'] ?? false,
        ], 'Access settings retrieved');
    }

    /**
     * Get maintenance status (public - for middleware check)
     */
    public function getMaintenanceStatus(Request $request)
    {
        $settings = Cache::remember('general_settings', 300, function () {
            $setting = Setting::where('key', 'general_settings')->first();
            return $setting ? $setting->value : $this->defaultSettings;
        });

        $maintenanceMode = $settings['maintenance_mode'] ?? false;
        $estimatedTime = $settings['maintenance_estimated_time'] ?? '2-3 jam';
        $whitelistIps = $settings['maintenance_whitelist_ips'] ?? '';

        // Check if client IP is whitelisted
        $clientIp = $request->ip();
        $isWhitelisted = false;

        if (!empty($whitelistIps)) {
            $whitelist = array_map('trim', explode(',', $whitelistIps));
            $isWhitelisted = in_array($clientIp, $whitelist);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'maintenance_mode' => $maintenanceMode,
                'estimated_time' => $estimatedTime,
                'is_whitelisted' => $isWhitelisted,
            ],
        ]);
    }

    /**
     * Update general settings
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'maintenance_mode' => 'required|boolean',
            'maintenance_estimated_time' => 'required|string|max:100',
            'maintenance_whitelist_ips' => 'nullable|string|max:500',
            'disable_registration' => 'required|boolean',
            'invite_only_mode' => 'required|boolean',
            'disable_login' => 'required|boolean',
            'cleanup_expired_links_days' => 'required|integer|min:0|max:365',
            'cleanup_blocked_links_days' => 'required|integer|min:0|max:365',
            'cleanup_old_notifications_days' => 'required|integer|min:0|max:365',
            'backdoor_access_code' => 'required|string|min:4|max:50',
        ]);

        // Get current settings to check if maintenance mode is being turned ON
        $currentSettings = $this->getCurrentSettings();
        $isMaintenanceTurningOn = !$currentSettings['maintenance_mode'] && $validated['maintenance_mode'];

        // Save settings
        Setting::updateOrCreate(
            ['key' => 'general_settings'],
            ['value' => $validated]
        );

        // Clear cache
        Cache::forget('general_settings');

        // If maintenance mode is being turned ON, force logout all users
        if ($isMaintenanceTurningOn) {
            $loggedOutCount = $this->forceLogoutAllUsers();
        }

        return $this->successResponse([
            'settings' => $validated,
            'users_logged_out' => $isMaintenanceTurningOn ? ($loggedOutCount ?? 0) : null,
        ], $isMaintenanceTurningOn
            ? 'Settings saved. Maintenance mode ON - all users logged out.'
            : 'General settings updated successfully.');
    }

    /**
     * Force logout all users (delete all tokens except current admin)
     */
    public function forceLogout(Request $request)
    {
        $count = $this->forceLogoutAllUsers();

        return $this->successResponse([
            'users_logged_out' => $count,
        ], "Successfully logged out {$count} users.");
    }

    /**
     * Run data cleanup manually
     */
    public function runCleanup()
    {
        $settings = $this->getCurrentSettings();
        $result = [
            'expired_links' => 0,
            'blocked_links' => 0,
            'old_notifications' => 0,
        ];

        // Cleanup expired links
        if ($settings['cleanup_expired_links_days'] > 0) {
            $cutoffDate = now()->subDays($settings['cleanup_expired_links_days']);
            $result['expired_links'] = Link::whereNotNull('expiry_date')
                ->where('expiry_date', '<', $cutoffDate)
                ->delete();
        }

        // Cleanup blocked links
        if ($settings['cleanup_blocked_links_days'] > 0) {
            $cutoffDate = now()->subDays($settings['cleanup_blocked_links_days']);
            $result['blocked_links'] = Link::where('is_blocked', true)
                ->where('updated_at', '<', $cutoffDate)
                ->delete();
        }

        // Cleanup old notifications
        if ($settings['cleanup_old_notifications_days'] > 0) {
            $cutoffDate = now()->subDays($settings['cleanup_old_notifications_days']);
            $result['old_notifications'] = DB::table('notifications')
                ->whereNotNull('read_at')
                ->where('read_at', '<', $cutoffDate)
                ->delete();
        }

        $total = $result['expired_links'] + $result['blocked_links'] + $result['old_notifications'];

        return $this->successResponse($result, "Cleanup completed. {$total} items deleted.");
    }

    /**
     * Verify backdoor access code (public endpoint)
     */
    public function verifyBackdoorCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $settings = $this->getCurrentSettings();
        $storedCode = $settings['backdoor_access_code'] ?? 'admin123';

        if ($request->code === $storedCode) {
            return response()->json([
                'success' => true,
                'message' => 'Access code verified successfully.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid access code.',
        ], 403);
    }

    /**
     * Helper: Get current settings from cache/db
     */
    private function getCurrentSettings(): array
    {
        $setting = Setting::where('key', 'general_settings')->first();
        return $setting ? $setting->value : $this->defaultSettings;
    }

    /**
     * Helper: Force logout all users
     */
    private function forceLogoutAllUsers(): int
    {
        // Get current user's token ID to exclude
        $currentTokenId = auth()->user()?->currentAccessToken()?->id;

        // Delete all tokens except current admin's token
        $query = PersonalAccessToken::query();

        if ($currentTokenId) {
            $query->where('id', '!=', $currentTokenId);
        }

        $count = $query->count();
        $query->delete();

        return $count;
    }
}
