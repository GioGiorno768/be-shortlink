<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Exception;

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

            // 3. Buat API Token Sanctum untuk user
            $token = $user->createToken('api_token')->plainTextToken;

            // 4. Kembalikan respons JSON yang sama seperti login/register biasa
            return response()->json([
                'message' => 'Login with Google successful',
                'user' => $user,
                'token' => $token,
            ], 200);

        } catch (Exception $e) {
            // Tangani jika 'access_token' tidak valid atau ada error
            Log::error('Google Login Error: ' . $e->getMessage());
            return response()->json([
                'message' => 'Authentication failed',
                'error' => $e->getMessage(),
            ], 401);
        }
    }
}