<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Transaction;

class ReferralController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Stats
        $totalInvited = User::where('referred_by', $user->id)->count();
        $totalEarnings = Transaction::where('user_id', $user->id)
            ->where('type', 'referral_commission')
            ->sum('amount');

        // 2. List Referrals
        // Ambil daftar user yang diundang
        $referrals = User::where('referred_by', $user->id)
            ->select('id', 'name', 'created_at')
            ->latest()
            ->paginate(10);

        // Transform data untuk menambahkan earnings per user
        $referrals->getCollection()->transform(function ($referral) use ($user) {
            // Hitung total komisi yang didapat dari user ini
            // Logic: Cari transaksi tipe 'referral_commission' milik current user
            // yang terhubung ke payout milik referral user ini.
            
            $earnings = Transaction::where('user_id', $user->id)
                ->where('type', 'referral_commission')
                ->whereHas('payout', function ($q) use ($referral) {
                    $q->where('user_id', $referral->id);
                })
                ->sum('amount');

            $referral->earnings = $earnings;
            return $referral;
        });

        return $this->successResponse([
            'stats' => [
                'total_invited' => $totalInvited,
                'total_earnings' => $totalEarnings,
                'referral_code' => $user->referral_code,
                'referral_link' => url('/register?ref=' . $user->referral_code) // Sesuaikan dengan URL frontend nanti
            ],
            'referrals' => $referrals
        ], 'Referral data retrieved');
    }
}
