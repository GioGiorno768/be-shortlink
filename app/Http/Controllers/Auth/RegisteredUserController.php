<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Support\Facades\DB;

class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): Response
    {
        // 🔥🔥 CEK STATUS REGISTRASI 🔥🔥
        $setting = \App\Models\Setting::where('key', 'registration_settings')->first();
        if ($setting && isset($setting->value['enabled']) && !$setting->value['enabled']) {
            return response([
                'message' => $setting->value['message'] ?? 'Registration is currently closed.',
                'error' => 'Registration Closed'
            ], 403);
        }

        // 1. Validasi Input
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            // Validasi kode referral: harus ada di tabel users kolom referral_code
            'referral_code' => ['nullable', 'string', 'exists:users,referral_code'],
        ]);

        // 2. Eksekusi dalam Transaksi Database
        $user = DB::transaction(function () use ($request) {

            // Cari ID Pengundang (Referrer)
            // Cari ID Pengundang (Referrer)
            $referrerId = null;
            if ($request->referral_code) {
                $referrer = User::where('referral_code', $request->referral_code)->lockForUpdate()->first();
                if ($referrer) {
                    $referrerId = $referrer->id;
                    // Note: total_referrals count is computed dynamically in ReferralController
                }
            }

            // Buat User Baru
            // HANYA simpan referred_by, JANGAN beri saldo bonus disini.
            return User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'referral_code' => User::generateReferralCode(),
                'referred_by' => $referrerId, // Simpan relasi
                'balance' => 0,
                'pending_balance' => 0,
            ]);
        });

        event(new Registered($user));

        $token = $user->createToken('api_token')->plainTextToken;

        // 🛡️ Anti-Fraud: Store Fingerprint & IP
        $visitorId = $request->input('visitor_id');

        $loginIp = $request->ip();
        if (app()->environment('local') && $loginIp === '127.0.0.1') {
            $loginIp = '36.84.69.10';
        }

        $updateData = [];
        if ($visitorId) {
            $updateData['last_device_fingerprint'] = $visitorId;
        }
        if ($loginIp) {
            $updateData['last_login_ip'] = $loginIp;
        }

        if (!empty($updateData)) {
            $user->update($updateData);
        }

        return response([
            'message' => 'Registered successfully.',
            'user' => $user,
            'token' => $token
        ], 201);
    }
}
