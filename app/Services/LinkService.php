<?php

namespace App\Services;

use App\Models\Link;
use App\Models\User;
use App\Models\View;
use App\Models\AdRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
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
     * 🛡️ Calculate earnings for a view with Anti-Fraud Protection
     * 
     * @param Link $link
     * @param string $ip
     * @param string $countryCode
     * @param string|null $visitorId Device fingerprint from FingerprintJS
     * @return array ['earning' => float, 'is_valid' => bool, 'rejection_reason' => string|null]
     */
    public function calculateEarnings(Link $link, $ip, $countryCode, $visitorId = null)
    {
        // Load owner untuk cek self-click
        $owner = $link->user;

        // ============================================================
        // 🛡️ LAYER 1: SELF-CLICK DETECTION
        // ============================================================
        if ($visitorId && $owner && $owner->last_device_fingerprint === $visitorId) {
            return [
                'earning' => 0,
                'is_valid' => false,
                'rejection_reason' => 'Self Click'
            ];
        }

        // ============================================================
        // 🛡️ LAYER 2: STRICT GLOBAL OWNER LIMIT (1 valid view per 5h per owner)
        // ============================================================
        if ($owner) {
            // Identifier: Prefer visitorId, fallback to IP
            $identifier = $visitorId ?? $ip;

            $cacheKey = "global_limit:{$identifier}:owner:{$owner->id}";
            $hasEarnedRecently = Redis::get($cacheKey);

            if ($hasEarnedRecently === null) {
                // Cek DB: "Apakah ada earning dari (VisitorID INI *ATAU* IP INI) untuk Owner INI dalam 5 jam terakhir?"
                $hasEarnedRecently = View::where(function ($query) use ($visitorId, $ip) {
                    $query->where('ip_address', $ip); // Cek IP
                    if ($visitorId) {
                        $query->orWhere('visitor_id', $visitorId); // ATAU Cek Visitor ID
                    }
                })
                    ->whereHas('link', function ($q) use ($owner) {
                        $q->where('user_id', $owner->id);
                    })
                    ->where('created_at', '>=', now()->subHours(5))
                    ->where('is_valid', true)
                    ->exists();

                // Cache result (1 = blocked, 0 = allowed)
                // If exists (true) -> set cache '1' -> Blocked
                // If not exists (false) -> set cache '0' -> Allowed (but will be set to '1' after this view created)
                if ($hasEarnedRecently) {
                    Redis::setex($cacheKey, 5 * 3600, '1'); // 5 Hours TTL
                }
            }

            // Jika Redis return '1' (string) atau true boolean logic
            if ($hasEarnedRecently && $hasEarnedRecently !== '0') {
                return [
                    'earning' => 0,
                    'is_valid' => false,
                    'rejection_reason' => 'Global Limit (5h)'
                ];
            }
        }

        // ============================================================
        // 🛡️ LAYER 3: DUPLICATE LINK (24h Cooldown per link)
        // ============================================================
        if ($visitorId) {
            $cacheKey = "link_cooldown:{$visitorId}:link:{$link->id}";
            $recentView = Redis::get($cacheKey);

            if ($recentView === null) {
                // Cek DB
                $recentView = View::where('visitor_id', $visitorId)
                    ->where('link_id', $link->id)
                    ->where('created_at', '>=', now()->subHours(24))
                    ->exists();

                if ($recentView) {
                    Redis::setex($cacheKey, 86400, '1');
                }
            }

            if ($recentView) {
                return [
                    'earning' => 0,
                    'is_valid' => false,
                    'rejection_reason' => 'Duplicate Link'
                ];
            }
        }

        // ============================================================
        // ✅ VALID VIEW - Calculate Earnings
        // ============================================================

        // 1. Guest link tidak dapat earning
        if (!$owner) {
            return [
                'earning' => 0,
                'is_valid' => true,
                'rejection_reason' => null
            ];
        }

        // 2. Ambil Rate
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

        // 3. Hitung Bonus Level User
        $finalEarned = $baseEarn;
        $bonusPercentage = $owner->bonus_cpm_percentage;
        if ($bonusPercentage > 0) {
            $bonusAmount = $baseEarn * ($bonusPercentage / 100);
            $finalEarned += $bonusAmount;
        }

        // Update Redis cache untuk layer 2 & 3
        if ($visitorId && $owner) {
            $cacheKey = "daily_limit:{$visitorId}:owner:{$owner->id}";
            Redis::incr($cacheKey);
            Redis::expire($cacheKey, 86400);

            $linkCacheKey = "link_cooldown:{$visitorId}:link:{$link->id}";
            Redis::setex($linkCacheKey, 86400, '1');
        }

        return [
            'earning' => $finalEarned,
            'is_valid' => true,
            'rejection_reason' => null
        ];
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
