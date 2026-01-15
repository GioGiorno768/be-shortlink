<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Services\LoginLogger;
use Illuminate\Validation\ValidationException;

class AdminLoginController extends Controller
{
    /**
     * Admin Login - Bypasses disable_login setting
     * Only allows admin and super_admin roles
     * 
     * This endpoint is used for the /backdoor route
     * so admins can always access the system even when
     * regular login is disabled.
     */
    public function store(Request $request)
    {
        // Validate input
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Find user by email
        $user = User::where('email', $request->email)->first();

        // Validate credentials
        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // 🔒 ROLE CHECK: Only admin and super_admin allowed
        if (!in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. This login is for administrators only.',
                'error' => 'unauthorized_role',
            ], 403);
        }

        // Check if banned
        if ($user->is_banned) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended.',
                'ban_reason' => $user->ban_reason ?? 'Policy violation',
            ], 403);
        }

        // Check if admin is suspended (for admin role)
        if ($user->role === 'admin' && $user->is_suspended) {
            return response()->json([
                'success' => false,
                'message' => 'Your admin account has been suspended.',
            ], 403);
        }

        // Login the user
        Auth::login($user);

        // Generate token
        $token = $user->createToken('api_token')->plainTextToken;

        // Log login
        LoginLogger::record($user);

        // Update device fingerprint & IP
        $visitorId = $request->input('visitor_id');
        $loginIp = $request->ip();

        // For local development, use fake IP
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

        \Illuminate\Support\Facades\Log::info('🔐 ADMIN BACKDOOR LOGIN', [
            'user_id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'ip' => $loginIp,
        ]);

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ]
        ]);
    }
}
