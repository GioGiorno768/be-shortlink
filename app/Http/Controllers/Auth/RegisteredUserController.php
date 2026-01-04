<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Setting;
use App\Models\Transaction;
use App\Notifications\SendVerificationEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
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
        // 🔒 CHECK GENERAL SETTINGS (disable_registration & invite_only_mode)
        $generalSettings = Cache::remember('general_settings', 300, function () {
            $setting = Setting::where('key', 'general_settings')->first();
            return $setting ? $setting->value : [
                'disable_registration' => false,
                'invite_only_mode' => false,
            ];
        });

        // Check if registration is disabled
        if ($generalSettings['disable_registration'] ?? false) {
            return response([
                'message' => 'Registration is currently disabled. Please try again later.',
                'error' => 'Registration Disabled'
            ], 403);
        }

        // Check if invite-only mode is enabled (requires referral code)
        if ($generalSettings['invite_only_mode'] ?? false) {
            if (empty($request->referral_code)) {
                return response([
                    'message' => 'Registration is currently invite-only. Please use a valid referral code.',
                    'error' => 'Invite Only Mode'
                ], 403);
            }
        }

        // 🔥🔥 CEK STATUS REGISTRASI (legacy) 🔥🔥
        $setting = Setting::where('key', 'registration_settings')->first();
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
            $referrerId = null;
            if ($request->referral_code) {
                $referrer = User::where('referral_code', $request->referral_code)->lockForUpdate()->first();
                if ($referrer) {
                    $referrerId = $referrer->id;
                }
            }

            // Buat User Baru
            return User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'referral_code' => User::generateReferralCode(),
                'referred_by' => $referrerId,
                'balance' => 0,
                'pending_balance' => 0,
            ]);
        });

        event(new Registered($user));

        // 📧 [DEV MODE] Skip email verification - auto-verify user
        // TODO: Uncomment for production with verified Resend domain
        // $user->notify(new SendVerificationEmail());
        $user->markEmailAsVerified();

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

        // 🎁 REFERRAL SIGNUP BONUS
        // Credit bonus to new user if they registered via referral link
        $signupBonusAmount = 0;
        if ($user->referred_by) {
            // Get signup bonus amount from settings
            $referralSetting = Setting::where('key', 'referral_settings')->first();
            $signupBonusAmount = $referralSetting?->value['signup_bonus'] ?? 0;

            if ($signupBonusAmount > 0) {
                // Credit bonus to new user's balance
                $user->increment('balance', $signupBonusAmount);

                // Create transaction record for audit
                Transaction::create([
                    'user_id' => $user->id,
                    'type' => 'referral_signup_bonus',
                    'amount' => $signupBonusAmount,
                    'description' => 'Signup bonus dari referral',
                    'reference_id' => $user->referred_by, // ID of the referrer
                ]);
            }
        }

        return response([
            'message' => 'Registered successfully.',
            'user' => $user->fresh(), // Refresh to get updated balance
            'token' => $token,
            'signup_bonus' => $signupBonusAmount,
        ], 201);
    }
}
