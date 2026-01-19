<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LinkReport;
use App\Models\Link;
use Illuminate\Support\Facades\RateLimiter;

class ReportController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'url' => 'required|url',
            'reason' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'details' => 'nullable|string|max:1000',
        ]);

        $ip = $request->ip();

        // 🛡️ Rate Limiting: 3 reports per IP per day
        $key = 'report_abuse:' . $ip;
        if (RateLimiter::tooManyAttempts($key,3)) {
            $seconds = RateLimiter::availableIn($key);
            return $this->errorResponse('Anda telah mencapai batas laporan harian. Silakan coba lagi besok.', 429, ['retry_after' => $seconds]);
        }
        RateLimiter::hit($key, 86400); // 24 jam

        // Coba cari Link ID dari URL (Opsional, untuk memudahkan admin)
        $linkId = null;
        try {
            // Asumsi URL format: domain.com/CODE
            $path = parse_url($request->url, PHP_URL_PATH);
            $code = ltrim($path, '/');
            $link = Link::where('code', $code)->first();
            if ($link) {
                $linkId = $link->id;
            }
        } catch (\Exception $e) {
            // Ignore parsing error
        }

        LinkReport::create([
            'link_url' => $request->url,
            'link_id' => $linkId,
            'reason' => $request->reason,
            'email' => $request->email,
            'details' => $request->details,
            'ip_address' => $ip,
        ]);

        return $this->successResponse(null, 'Laporan Anda telah diterima. Terima kasih atas kontribusi Anda menjaga keamanan platform kami.', 201);
    }
}
