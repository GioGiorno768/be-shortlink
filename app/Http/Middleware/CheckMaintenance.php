<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenance
{
    /**
     * Handle an incoming request.
     * Check if maintenance mode is enabled and block non-whitelisted users.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get general settings from cache
        $settings = Cache::remember('general_settings', 300, function () {
            $setting = Setting::where('key', 'general_settings')->first();
            return $setting ? $setting->value : [
                'maintenance_mode' => false,
                'maintenance_estimated_time' => '2-3 jam',
                'maintenance_whitelist_ips' => '',
            ];
        });

        // If maintenance mode is not enabled, continue
        if (!($settings['maintenance_mode'] ?? false)) {
            return $next($request);
        }

        // Check if user is super admin - they can always access
        $user = $request->user();
        if ($user && $user->role === 'super_admin') {
            return $next($request);
        }

        // Check if IP is whitelisted
        $clientIp = $request->ip();
        $whitelistedIps = $settings['maintenance_whitelist_ips'] ?? '';

        if (!empty($whitelistedIps)) {
            $ips = array_map('trim', explode(',', $whitelistedIps));
            if (in_array($clientIp, $ips)) {
                return $next($request);
            }
        }

        // Return 503 Service Unavailable with maintenance info
        return response()->json([
            'success' => false,
            'message' => 'Website sedang dalam maintenance. Silakan coba lagi nanti.',
            'maintenance' => true,
            'estimated_time' => $settings['maintenance_estimated_time'] ?? 'Beberapa jam',
        ], 503);
    }
}
