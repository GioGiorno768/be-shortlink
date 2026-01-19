<?php

namespace App\Services;

use App\Models\LoginHistory;
use Jenssegers\Agent\Agent;
use Illuminate\Support\Facades\Request;

class LoginLogger
{
    public static function record($user)
    {
        $agent = new Agent();
        $agent->setUserAgent(Request::userAgent());

        $browser = $agent->browser();
        $platform = $agent->platform();
        $device = $agent->device();
        
        // Simple location detection (can be improved with stevebauman/location)
        // For now, we just store the IP
        $ip = Request::ip();
        $location = 'Unknown'; // Placeholder for now

        LoginHistory::create([
            'user_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => Request::userAgent(),
            'browser' => $browser . ' ' . $agent->version($browser),
            'platform' => $platform . ' ' . $agent->version($platform),
            'device' => $device,
            'location' => $location,
            'login_at' => now(),
        ]);
    }
}
