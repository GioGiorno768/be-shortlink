<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Auth\Events\Verified;
use App\Notifications\SendVerificationEmail;

class EmailVerificationController extends Controller
{
    /**
     * Verify the user's email address.
     * Called from frontend with signed URL parameters.
     */
    public function verify(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'hash' => 'required|string',
            'expires' => 'required|string',
            'signature' => 'required|string',
        ]);

        $user = User::findOrFail($request->id);

        // Check if hash matches
        if (!hash_equals(sha1($user->getEmailForVerification()), $request->hash)) {
            return response()->json([
                'message' => 'Link verifikasi tidak valid.',
                'error' => 'Invalid verification link'
            ], 400);
        }

        // Check if link expired
        if (now()->timestamp > $request->expires) {
            return response()->json([
                'message' => 'Link verifikasi sudah kadaluarsa. Silakan minta link baru.',
                'error' => 'Link expired'
            ], 400);
        }

        // Check if already verified
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email sudah terverifikasi sebelumnya.',
                'already_verified' => true
            ]);
        }

        // Verify the email
        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json([
            'message' => 'Email berhasil diverifikasi! Silakan login.',
            'verified' => true
        ]);
    }

    /**
     * Resend verification email.
     */
    public function resend(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email sudah terverifikasi.',
                'already_verified' => true
            ]);
        }

        $user->notify(new SendVerificationEmail());

        return response()->json([
            'message' => 'Email verifikasi telah dikirim ulang.'
        ]);
    }

    /**
     * Check if current user's email is verified.
     */
    public function status(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        return response()->json([
            'verified' => $user->hasVerifiedEmail(),
            'email' => $user->email,
        ]);
    }
}
