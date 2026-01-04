<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Validation\ValidationException;
use App\Services\LoginLogger;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request)
    {
        // 🔒 CHECK IF LOGIN IS DISABLED
        $generalSettings = Cache::remember('general_settings', 300, function () {
            $setting = Setting::where('key', 'general_settings')->first();
            return $setting ? $setting->value : ['disable_login' => false];
        });

        if ($generalSettings['disable_login'] ?? false) {
            return response()->json([
                'success' => false,
                'message' => 'Login is temporarily disabled. Please try again later.',
            ], 403);
        }

        // ✅ Validation langsung di controller
        // $request->validate([
        //     'email' => ['required', 'string', 'email'],
        //     'password' => ['required', 'string'],
        // ]);

        // // ✅ Cari user by email
        // $user = User::where('email', $request->email)->first();

        // // ✅ Validasi password
        // if (!$user || !Hash::check($request->password, $user->password)) {
        //     throw ValidationException::withMessages([
        //         'email' => ['The provided credentials are incorrect.'],
        //     ]);
        // }

        $request->authenticate();

        // $request->session()->regenerate();

        /** @var \App\Models\User $user */
        $user = Auth::user();

        // 🔥🔥 CEK STATUS BANNED 🔥🔥
        if ($user->is_banned) {
            // Logout untuk membersihkan (revoke all tokens for API)
            $user->tokens()->delete();

            // Only invalidate session if it exists (web routes have session, API routes don't)
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return response()->json([
                'message' => 'Your account has been suspended.',
                'error' => 'Account Banned',
                'ban_reason' => $user->ban_reason ?? 'Pelanggaran Terms of Service',
            ], 403);
        }

        // dd($user);

        // ✅ Generate token (Sanctum)
        $token = $user->createToken('api_token')->plainTextToken;

        // 📝 Catat Login History
        LoginLogger::record($user);

        // 🛡️ Save Device Fingerprint for Self-Click Detection
        $visitorId = $request->input('visitor_id');

        $loginIp = $request->ip();
        if (app()->environment('local') && $loginIp === '127.0.0.1') {
            $loginIp = '36.84.69.10';
            // $loginIp = '8.8.8.8';
        }

        \Log::info('📝 LOGIN - Received Data', [
            'user_id' => $user->id,
            'user_email' => $user->email,
            'visitor_id_received' => $visitorId,
            'login_ip' => $loginIp,
            'all_request_data' => $request->all()
        ]);

        $updateData = [];
        if ($visitorId) {
            $updateData['last_device_fingerprint'] = $visitorId;
        }
        if ($loginIp) {
            $updateData['last_login_ip'] = $loginIp;
        }

        if (!empty($updateData)) {
            \Log::info('💾 LOGIN - Updating User', [
                'user_id' => $user->id,
                'update_data' => $updateData
            ]);

            $user->update($updateData);

            // Verify update
            $user->refresh();
            \Log::info('✅ LOGIN - Update Complete', [
                'user_id' => $user->id,
                'last_device_fingerprint' => $user->last_device_fingerprint,
                'last_login_ip' => $user->last_login_ip
            ]);
        } else {
            \Log::warning('⚠️ LOGIN - No data to update (visitor_id or IP missing)');
        }

        return response()->json([
            // 'user' => $user,
            'message' => 'Login successful.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]
        ]);

        // return response()->noContent();
    }

    /**
     * Destroy an authenticated session (logout).
     */
    public function destroy(Request $request)
    {
        // ✅ Delete current user's token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}
