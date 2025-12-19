<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
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

        // Cache for 2 minutes
        $data = Cache::remember($cacheKey, 120, function () use ($user) {
            // 1. Get referral commission rate from settings
            $commissionSetting = Setting::where('key', 'referral_percentage')->first();
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

            // 3. Get list of referred users with earnings
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
     */
    public function getReferrerInfo(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $referrer = User::where('referral_code', $request->code)
            ->select('id', 'name')
            ->first();

        if (!$referrer) {
            return $this->errorResponse('Referral code tidak valid', 404);
        }

        return $this->successResponse([
            'name' => $referrer->name,
        ], 'Referrer info retrieved');
    }
}
