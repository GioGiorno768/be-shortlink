<?php

namespace App\Services;

use App\Models\Link;
use App\Models\User;
use App\Models\View;
use App\Models\AdRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Stevebauman\Location\Facades\Location;
use Carbon\Carbon;

class LinkService
{
    /**
     * Validate the continue token.
     */
    public function validateToken($code, $inputToken, $ip, $userAgent, $isGuestLink = false)
    {
        $uaNormalized = trim($userAgent);
        $tokenKey = "token:{$code}:" . md5("{$ip}-{$uaNormalized}");

        // Ambil dari cache
        $cachedToken = Cache::get($tokenKey);

        // Fallback ke key generik (backward compatibility)
        if (!$cachedToken) {
            $cachedToken = Cache::get("token:{$code}");
            $tokenKey = "token:{$code}";
        }

        if (!$cachedToken) {
            return ['valid' => false, 'error' => 'Token expired or invalid.'];
        }

        // Normalisasi data token
        $storedToken = null;
        $cachedIp = null;
        $cachedUa = null;
        $cachedCreated = null;

        if (is_array($cachedToken)) {
            $storedToken = $cachedToken['token'] ?? null;
            $cachedIp = $cachedToken['ip'] ?? null;
            $cachedUa = $cachedToken['user_agent'] ?? null;
            $cachedCreated = $cachedToken['created_at'] ?? null;
        } elseif (is_object($cachedToken)) {
            $storedToken = $cachedToken->token ?? null;
            $cachedIp = $cachedToken->ip ?? null;
            $cachedUa = $cachedToken->user_agent ?? null;
            $cachedCreated = $cachedToken->created_at ?? null;
        } else {
            $storedToken = $cachedToken;
        }

        // 1. Cek kesesuaian token
        if (!$storedToken || !$inputToken || !hash_equals((string) $storedToken, (string) $inputToken)) {
            return ['valid' => false, 'error' => 'Invalid token.'];
        }

        // 1.5. Cek status token (harus sudah diaktivasi)
        $tokenStatus = null;
        if (is_array($cachedToken)) {
            $tokenStatus = $cachedToken['status'] ?? null;
        } elseif (is_object($cachedToken)) {
            $tokenStatus = $cachedToken->status ?? null;
        }

        // Untuk member links (bukan guest), token HARUS sudah active
        if (!$isGuestLink && $tokenStatus !== null && $tokenStatus !== 'active') {
            return [
                'valid' => false,
                'error' => 'Token belum diaktivasi. Silakan selesaikan proses verifikasi terlebih dahulu.',
                'status' => 403
            ];
        }

        // 2. Cek IP & UA
        if ($cachedIp !== null && $cachedIp !== $ip) {
            return ['valid' => false, 'error' => 'Token IP mismatch.'];
        }
        if ($cachedUa !== null && $cachedUa !== $uaNormalized) {
            return ['valid' => false, 'error' => 'Token UA mismatch.'];
        }

        // 3. Cek Waktu (Bot Protection & Expiry)
        // SKIP timer check untuk guest links (mereka udah nunggu di halaman /go)
        if (!$isGuestLink && $cachedCreated) {
            try {
                $created = Carbon::parse($cachedCreated);
                $now = now();
                $diff = $created->diffInSeconds($now);

                if ($diff < 12) {
                    return [
                        'valid' => false,
                        'error' => 'Please wait for the timer to finish.',
                        'status' => 429,
                        'remaining' => 14 - $diff
                    ];
                }

                if ($diff > 180) {
                    Cache::forget($tokenKey);
                    return ['valid' => false, 'error' => 'Token expired.'];
                }
            } catch (\Exception $e) {
                // Ignore parsing error
            }
        }

        // Guest link masih perlu cek expiry tapi gak perlu timer minimum
        if ($isGuestLink && $cachedCreated) {
            try {
                $created = Carbon::parse($cachedCreated);
                $now = now();
                $diff = $created->diffInSeconds($now);

                if ($diff > 180) {
                    Cache::forget($tokenKey);
                    return ['valid' => false, 'error' => 'Token expired.'];
                }
            } catch (\Exception $e) {
                // Ignore parsing error
            }
        }

        // Hapus token setelah valid
        Cache::forget($tokenKey);

        return ['valid' => true];
    }

    /**
     * Calculate earnings for a view.
     */
    public function calculateEarnings(Link $link, $ip, $countryCode)
    {
        // 1. Cek View Unik (24 Jam terakhir)
        $existing = View::where('link_id', $link->id)
            ->where('ip_address', $ip)
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();

        if ($existing) {
            return 0; // Tidak unik / spam
        }

        // 2. Cek Pemilik Link
        if (!$link->user_id) {
            return 0; // Guest link tidak dapat earning
        }

        // 3. Ambil Rate
        $adRates = Cache::remember('ad_rates_all', 3600, function () {
            return AdRate::all();
        });

        $rateConfig = $adRates->firstWhere('country', $countryCode)
            ?? $adRates->firstWhere('country', 'GLOBAL');

        if (!$rateConfig) {
            // Default hardcoded fallback
            $rateConfig = (object) [
                'rates' => [
                    'level_1' => 0.05,
                    'level_2' => 0.07,
                    'level_3' => 0.10,
                    'level_4' => 0.15,
                ]
            ];
        }

        $level = $link->ad_level ?? 1;
        $levelKey = "level_{$level}";
        $rates = $rateConfig->rates ?? [];
        $baseEarn = $rates[$levelKey] ?? ($rates['level_1'] ?? 0.00);

        // 4. Hitung Bonus Level User
        $finalEarned = $baseEarn;

        // Load user dengan cache level
        $owner = $link->user;
        if ($owner) {
            $bonusPercentage = $owner->bonus_cpm_percentage; // Menggunakan accessor dari User model
            if ($bonusPercentage > 0) {
                $bonusAmount = $baseEarn * ($bonusPercentage / 100);
                $finalEarned += $bonusAmount;
            }
        }

        return $finalEarned;
    }

    /**
     * Get Country from IP
     */
    public function getCountry($ip)
    {
        return Cache::remember("geoip:{$ip}", 86400, function () use ($ip) {
            try {
                $position = Location::get($ip);
                return [
                    'code' => $position->countryCode ?? 'GLOBAL',
                    'name' => $position->countryName ?? 'Unknown'
                ];
            } catch (\Exception $e) {
                return ['code' => 'GLOBAL', 'name' => 'Unknown'];
            }
        });
    }
}
