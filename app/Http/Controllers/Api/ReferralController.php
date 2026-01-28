<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Level;
use App\Models\Transaction;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class ReferralController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $cacheKey = "referrals:user:{$user->id}";

        // Cache for 30 seconds - balances performance with responsiveness
        $data = Cache::remember($cacheKey, 30, function () use ($user) {
            // 1. Get referral commission rate from settings
            $commissionSetting = Setting::where('key', 'referral_settings')->first();
            if (!$commissionSetting) {
                // Fallback to legacy key
                $commissionSetting = Setting::where('key', 'referral_percentage')->first();
            }
            $commissionRate = $commissionSetting ? ($commissionSetting->value['percentage'] ?? 10) : 10;

            // 2. Count stats with optimized queries
            $totalInvited = User::where('referred_by', $user->id)->count();

            // Active = user has been active in last 30 days (updated_at)
            // Same logic as list status for consistency
            $activeReferred = User::where('referred_by', $user->id)
                ->where('updated_at', '>=', Carbon::now()->subDays(30))
                ->count();

            // Total earnings from referral commissions
            $totalEarnings = Transaction::where('user_id', $user->id)
                ->where('type', 'referral_commission')
                ->sum('amount');

            // 3. Get max referrals from user's current level (direct query to avoid accessor caching)
            if ($user->current_level_id) {
                $level = Level::find($user->current_level_id);
                $maxReferrals = $level?->max_referrals ?? 10;
            } else {
                // Fallback to lowest level (Beginner) if user has no level assigned
                $lowestLevel = Level::orderBy('min_total_earnings', 'asc')->first();
                $maxReferrals = $lowestLevel?->max_referrals ?? 10;
            }
            $isLimitReached = $totalInvited >= $maxReferrals;

            // 4. Get list of referred users with earnings
            $referrals = User::where('referred_by', $user->id)
                ->select('id', 'name', 'created_at', 'updated_at')
                ->latest()
                ->paginate(10);

            // Map earnings per referred user (batch query for optimization)
            $referralIds = $referrals->pluck('id')->toArray();

            // Get earnings map in single query
            $earningsMap = Transaction::where('user_id', $user->id)
                ->where('type', 'referral_commission')
                ->whereHas('payout', function ($q) use ($referralIds) {
                    $q->whereIn('user_id', $referralIds);
                })
                ->get()
                ->groupBy(function ($tx) {
                    return $tx->payout->user_id ?? null;
                })
                ->map(fn($txs) => $txs->sum('amount'));

            // Transform referrals data
            $referrals->getCollection()->transform(function ($referral) use ($earningsMap) {
                // Calculate status: active if has activity in last 30 days
                $isActive = Carbon::parse($referral->updated_at)->gte(Carbon::now()->subDays(30));

                return [
                    'id' => (string) $referral->id,
                    'name' => $referral->name,
                    'dateJoined' => $referral->created_at->toISOString(),
                    'totalEarningsForMe' => (float) ($earningsMap[$referral->id] ?? 0),
                    'status' => $isActive ? 'active' : 'inactive',
                ];
            });

            return [
                'stats' => [
                    'totalEarnings' => (float) $totalEarnings,
                    'totalReferred' => $totalInvited,
                    'activeReferred' => $activeReferred,
                    'commissionRate' => (int) $commissionRate,
                    'maxReferrals' => (int) $maxReferrals,
                    'isLimitReached' => $isLimitReached,
                ],
                'referralLink' => config('app.frontend_url', 'https://shortlinkmu.com') . '/register?ref=' . $user->referral_code,
                'referrals' => $referrals,
            ];
        });

        return $this->successResponse($data, 'Referral data retrieved');
    }

    /**
     * Get referrer info by referral code (PUBLIC - no auth required)
     * Used by frontend to show "Diundang oleh [Nama]" banner
     * Also checks if referrer has reached their max referrals limit
     */
    public function getReferrerInfo(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $referrer = User::where('referral_code', $request->code)
            ->with('currentLevel')
            ->select('id', 'name', 'current_level_id')
            ->first();

        if (!$referrer) {
            return $this->errorResponse('Referral code tidak valid', 404);
        }

        // Check if referrer has reached their max referrals limit
        $totalReferred = User::where('referred_by', $referrer->id)->count();
        if ($referrer->currentLevel) {
            $maxReferrals = $referrer->currentLevel->max_referrals ?? 10;
        } else {
            // Fallback to lowest level (Beginner) if referrer has no level
            $lowestLevel = Level::orderBy('min_total_earnings', 'asc')->first();
            $maxReferrals = $lowestLevel?->max_referrals ?? 10;
        }
        $isLimitReached = $totalReferred >= $maxReferrals;

        return $this->successResponse([
            'name' => $referrer->name,
            'isLimitReached' => $isLimitReached,
        ], 'Referrer info retrieved');
    }

    /**
     * Check if user is eligible for referral bonus (PUBLIC - no auth required)
     * Anti-fraud: checks if device fingerprint already exists or if referrer has too many same-IP referrals
     */
    public function checkEligibility(Request $request)
    {
        $request->validate([
            'visitor_id' => 'nullable|string',
            'referral_code' => 'required|string',
        ]);

        $visitorId = $request->input('visitor_id');
        $referralCode = $request->input('referral_code');

        // Get IP address
        $ip = $request->ip();
        if (app()->environment('local') && $ip === '127.0.0.1') {
            $ip = '36.84.69.10'; // Test IP for local dev
        }

        // 1. Validasi referral code dan ambil referrer dengan IP-nya
        $referrer = User::where('referral_code', $referralCode)
            ->select('id', 'name', 'last_login_ip', 'same_ip_referral_count')
            ->first();

        if (!$referrer) {
            return $this->successResponse([
                'eligible' => false,
                'reason' => 'invalid_code',
            ]);
        }

        // Get referral settings for anti-fraud toggles
        $referralSetting = Setting::where('key', 'referral_settings')->first();
        $fingerprintCheckEnabled = $referralSetting?->value['fingerprint_check_enabled'] ?? true;
        $ipLimitEnabled = $referralSetting?->value['ip_limit_enabled'] ?? true;
        $maxAccountsPerIp = $referralSetting?->value['max_accounts_per_ip'] ?? 2;

        // 2. Cek fingerprint di database (if enabled)
        // Jika fingerprint visitor sudah ada di database = device sudah pernah register
        if ($fingerprintCheckEnabled && $visitorId) {
            $fingerprintExists = User::where('last_device_fingerprint', $visitorId)->exists();

            if ($fingerprintExists) {
                return $this->successResponse([
                    'eligible' => false,
                    'reason' => 'device_registered',
                ]);
            }
        }

        // 3. Cek IP limit (if enabled)
        // LOGIC BARU: Cek apakah IP visitor SAMA dengan IP referrer
        // Jika sama, cek apakah referrer sudah mencapai limit same-IP referrals
        if ($ipLimitEnabled) {
            $referrerIp = $referrer->last_login_ip;

            // Hanya cek jika IP visitor === IP referrer (same network)
            if ($referrerIp && $ip === $referrerIp) {
                // Cek apakah referrer sudah mencapai limit referrals dari IP yang sama
                if ($referrer->same_ip_referral_count >= $maxAccountsPerIp) {
                    return $this->successResponse([
                        'eligible' => false,
                        'reason' => 'ip_limit_exceeded',
                    ]);
                }
            }
            // Jika IP berbeda dari referrer = always eligible (no IP restriction)
        }

        // 4. Eligible - passed all checks
        return $this->successResponse([
            'eligible' => true,
            'referrer_name' => $referrer->name,
        ]);
    }
}
