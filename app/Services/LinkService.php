<?php

namespace App\Services;

use App\Models\Link;
use App\Models\User;
use App\Models\View;
use App\Models\AdRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Log;
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
        // Get configurable settings
        $linkSettings = $this->getLinkSettings();
        $minWait = $linkSettings['min_wait_seconds'] ?? 12;
        $expiry = $linkSettings['expiry_seconds'] ?? 180;

        // SKIP timer check untuk guest links (mereka udah nunggu di halaman /go)
        if (!$isGuestLink && $cachedCreated) {
            try {
                $created = Carbon::parse($cachedCreated);
                $now = now();
                $diff = $created->diffInSeconds($now);

                if ($diff < $minWait) {
                    return [
                        'valid' => false,
                        'error' => 'Please wait for the timer to finish.',
                        'status' => 429,
                        'remaining' => ($minWait + 2) - $diff
                    ];
                }

                if ($diff > $expiry) {
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

                if ($diff > $expiry) {
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
        // 🛡️ LAYER 1: GLOBAL SELF-CLICK LIMIT (by IP match)
        // Cek apakah IP visitor sama dengan owner's last login IP
        // Kalau sama, cek global daily limit (bukan per-link)
        // ============================================================
        $ipMatch = false;
        $isSelfClick = false;

        if ($owner) {
            $ipMatch = $owner->last_login_ip && $owner->last_login_ip === $ip;
            $fpMatch = $visitorId && $owner->last_device_fingerprint && $owner->last_device_fingerprint === $visitorId;
            $isSelfClick = $ipMatch && $fpMatch; // Full detection untuk tentukan earning rate

            Log::info('🔍 LAYER 1 - Self-Click Check', [
                'link_id' => $link->id,
                'owner_id' => $owner->id,
                'visitor_ip' => $ip,
                'visitor_fingerprint' => $visitorId,
                'owner_last_login_ip' => $owner->last_login_ip,
                'owner_last_fingerprint' => $owner->last_device_fingerprint,
                'ip_match' => $ipMatch,
                'fp_match' => $fpMatch,
                'is_self_click' => $isSelfClick,
            ]);

            // Kalau IP match dengan owner, cek GLOBAL daily limit
            if ($ipMatch) {
                $selfClickSettings = $this->getSelfClickSettings();

                // Check if self-click earning is enabled
                if (!$selfClickSettings['enabled']) {
                    Log::warning('❌ BLOCKED - Self-click disabled (IP match)', [
                        'owner_id' => $owner->id
                    ]);
                    return [
                        'earning' => 0,
                        'is_valid' => false,
                        'rejection_reason' => 'Self Click (Disabled)',
                        'shadow_ban' => true
                    ];
                }

                // Check GLOBAL daily limit (1x per user per 24h, BUKAN per link)
                $today = now()->format('Y-m-d');
                $globalSelfClickKey = "global_self_click:{$owner->id}:{$today}";
                $currentCount = (int) Redis::get($globalSelfClickKey);

                Log::info('🔍 LAYER 1 - Global Self-Click Limit Check', [
                    'owner_id' => $owner->id,
                    'current_count' => $currentCount,
                    'limit' => $selfClickSettings['daily_limit'],
                ]);

                if ($currentCount >= $selfClickSettings['daily_limit']) {
                    // Daily limit exceeded - BLOCK semua link dari IP ini
                    Log::warning('❌ BLOCKED - Global self-click daily limit exceeded', [
                        'owner_id' => $owner->id,
                        'current_count' => $currentCount,
                        'limit' => $selfClickSettings['daily_limit']
                    ]);
                    return [
                        'earning' => 0,
                        'is_valid' => false,
                        'rejection_reason' => 'Self Click (Daily Limit)',
                        'shadow_ban' => true
                    ];
                }

                // Increment GLOBAL counter (bukan per-link)
                Redis::incr($globalSelfClickKey);
                Redis::expire($globalSelfClickKey, 86400);

                Log::info('✅ LAYER 1 PASSED - Global self-click within limit', [
                    'owner_id' => $owner->id,
                    'new_count' => $currentCount + 1,
                    'limit' => $selfClickSettings['daily_limit'],
                    'is_full_self_click' => $isSelfClick,
                ]);
            }
        }

        // ============================================================
        // 🛡️ LAYER 2: PER-LINK COOLDOWN 24H (Redis)
        // ============================================================
        $identifier = $visitorId ?? $ip;
        $cacheKey = "link_cooldown:{$link->id}:{$identifier}";

        Log::info('🔍 LAYER 2 - Per-Link Cooldown Check', [
            'link_id' => $link->id,
            'identifier' => $identifier,
            'cache_key' => $cacheKey,
        ]);

        $recentView = Redis::get($cacheKey);

        if ($recentView && $recentView !== '0') {
            Log::warning('❌ BLOCKED - Per-Link Cooldown (24h)', [
                'link_id' => $link->id,
                'identifier' => $identifier
            ]);
            return [
                'earning' => 0,
                'is_valid' => false,
                'rejection_reason' => 'Duplicate Link (24h Cooldown)',
                'shadow_ban' => true
            ];
        }

        // Set cooldown for this identifier
        Redis::setex($cacheKey, 86400, '1');

        Log::info('✅ LAYER 2 PASSED - Setting per-link cooldown', [
            'link_id' => $link->id,
            'cache_key' => $cacheKey
        ]);

        // ============================================================
        // 💰 CALCULATE EARNING
        // ============================================================

        // 1. Guest link tidak dapat earning
        if (!$owner) {
            return [
                'earning' => 0,
                'is_valid' => true,
                'rejection_reason' => null
            ];
        }

        // 2. Get self-click settings (may already be loaded above)
        if (!isset($selfClickSettings)) {
            $selfClickSettings = $this->getSelfClickSettings();
        }

        // 4. Ambil Rate
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

        // 5. Apply self-click reduction if applicable
        if ($isSelfClick) {
            $originalEarn = $baseEarn;
            $baseEarn = $baseEarn * ($selfClickSettings['cpc_percentage'] / 100);
            Log::info('💰 SELF-CLICK EARNING', [
                'original' => $originalEarn,
                'percentage' => $selfClickSettings['cpc_percentage'],
                'reduced' => $baseEarn
            ]);
        }

        // 6. Hitung Bonus Level User
        $finalEarned = $baseEarn;
        $bonusPercentage = $owner->bonus_cpm_percentage;
        if ($bonusPercentage > 0) {
            $bonusAmount = $baseEarn * ($bonusPercentage / 100);
            $finalEarned += $bonusAmount;
        }

        return [
            'earning' => $finalEarned,
            'is_valid' => true,
            'is_self_click' => $isSelfClick,
            'rejection_reason' => null
        ];
    }

    /**
     * Get self-click settings from database/cache
     */
    private function getSelfClickSettings()
    {
        return Cache::remember('self_click_settings', 3600, function () {
            $setting = \App\Models\Setting::where('key', 'self_click')->first();

            if ($setting && $setting->value) {
                return $setting->value;
            }

            // Default settings
            return [
                'enabled' => true,
                'cpc_percentage' => 30,
                'daily_limit' => 1,
            ];
        });
    }

    /**
     * Helper function untuk ambil link settings dari cache/database
     */
    private function getLinkSettings()
    {
        return Cache::remember('link_settings', 3600, function () {
            $setting = \App\Models\Setting::where('key', 'link_settings')->first();

            if ($setting && $setting->value) {
                return $setting->value;
            }

            // Default settings
            return [
                'min_wait_seconds' => 12,
                'expiry_seconds' => 180,
            ];
        });
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
