<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Exception;
use App\Services\LoginLogger;

class SocialiteController extends Controller
{
    /**
     * Menangani callback dari Google setelah React mengirim 'code'.
     */
    public function handleGoogleCallback(Request $request)
    {
        // Validasi request harus ada 'code'
        $request->validate([
            'access_token' => 'required|string',
        ]);

        try {
            // 1. Dapatkan info user dari Google menggunakan 'access_token'
            //    Gunakan stateless() karena ini API
            //    REVISI: Menggunakan userFromToken() alih-alih userFromCode()
            $googleUser = Socialite::driver('google')->stateless()->userFromToken($request->access_token);

            // 2. Logika Find or Create User (Sama seperti sebelumnya, tapi ini penting)
            $user = User::where('google_id', $googleUser->getId())->first();

            if (!$user) {
                // Jika tidak ada user dengan google_id itu, cek email
                $user = User::where('email', $googleUser->getEmail())->first();

                if ($user) {
                    // User dengan email ini sudah ada, tapi belum link Google
                    // Update akunnya untuk ditautkan dengan google_id
                    $user->update([
                        'google_id' => $googleUser->getId(),
                        'provider_name' => 'google',
                    ]);
                } else {
                    // 🔥🔥 CEK STATUS REGISTRASI (Hanya untuk user baru) 🔥🔥
                    $setting = \App\Models\Setting::where('key', 'registration_settings')->first();
                    if ($setting && isset($setting->value['enabled']) && !$setting->value['enabled']) {
                        return $this->errorResponse($setting->value['message'] ?? 'Registration is currently closed.', 403, ['error' => 'Registration Closed']);
                    }

                    // Ini adalah user baru, buat akun baru
                    $user = User::create([
                        'name' => $googleUser->getName(),
                        'email' => $googleUser->getEmail(),
                        'google_id' => $googleUser->getId(),
                        'provider_name' => 'google',
                        'email_verified_at' => now(), // Email dari Google sudah terverifikasi
                        'password' => null, // Tidak ada password (karena sudah nullable di migrasi Anda)
                        'referral_code' => User::generateReferralCode(), // Panggil fungsi dari User model Anda
                    ]);
                }
            }

            // 🔥🔥 CEK STATUS BANNED 🔥🔥
            if ($user->is_banned) {
                return $this->errorResponse('Your account has been suspended. Please contact support.', 403, ['error' => 'Account Banned']);
            }

            // 3. Buat API Token Sanctum untuk user
            $token = $user->createToken('api_token')->plainTextToken;

            // 📝 Catat Login History
            LoginLogger::record($user);

            // 🛡️ Save Device Fingerprint & IP for Self-Click Detection
            $visitorId = $request->input('visitor_id');

            $loginIp = $request->ip();
            if (app()->environment('local') && $loginIp === '127.0.0.1') {
                $loginIp = '36.84.69.10';
            }

            Log::info('📝 GOOGLE LOGIN - Received Data', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'visitor_id_received' => $visitorId,
                'login_ip' => $loginIp,
            ]);

            $updateData = [];
            if ($visitorId) {
                $updateData['last_device_fingerprint'] = $visitorId;
            }
            if ($loginIp) {
                $updateData['last_login_ip'] = $loginIp;
            }

            if (!empty($updateData)) {
                Log::info('💾 GOOGLE LOGIN - Updating User', [
                    'user_id' => $user->id,
                    'update_data' => $updateData
                ]);

                $user->update($updateData);

                $user->refresh();
                Log::info('✅ GOOGLE LOGIN - Update Complete', [
                    'user_id' => $user->id,
                    'last_device_fingerprint' => $user->last_device_fingerprint,
                    'last_login_ip' => $user->last_login_ip
                ]);
            }

            // 4. Kembalikan respons JSON yang sama seperti login/register biasa
            return $this->successResponse([
                'user' => $user,
                'token' => $token,
            ], 'Login with Google successful');
        } catch (Exception $e) {
            // Tangani jika 'access_token' tidak valid atau ada error
            Log::error('Google Login Error: ' . $e->getMessage());
            return $this->errorResponse('Authentication failed', 401, ['error' => $e->getMessage()]);
        }
    }
}
