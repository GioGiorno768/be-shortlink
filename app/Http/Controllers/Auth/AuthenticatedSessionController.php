<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use App\Services\LoginLogger;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request)
    {
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

        $user = Auth::user();

        // 🔥🔥 CEK STATUS BANNED 🔥🔥
        if ($user->is_banned) {
            // Logout untuk membersihkan session (jika ada)
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Your account has been suspended. Please contact support.',
                'error' => 'Account Banned'
            ], 403);
        }

        // dd($user);

        // ✅ Generate token (Sanctum)
        $token = $user->createToken('api_token')->plainTextToken;

        // 📝 Catat Login History
        LoginLogger::record($user);

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