<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Cache;

class ProfileController extends Controller
{
    /**
     * Update the user's password.
     */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $rules = [
            'password' => ['required', 'confirmed', Password::defaults()],
        ];

        $messages = [
            'password.required' => 'New password is required.',
            'password.confirmed' => 'Password confirmation does not match.',
            'current_password.required' => 'Current password is required.',
            'current_password.current_password' => 'The current password is incorrect.',
        ];

        // Hanya validasi current_password jika user punya password
        if (!is_null($user->password)) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        $validated = $request->validate($rules, $messages);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        // 🔥 Hapus cache dashboard agar data user terupdate
        Cache::forget("dashboard:{$user->id}");

        return $this->successResponse(null, 'Password updated successfully.');
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'avatar' => ['nullable', 'string', 'max:50'], // avatar-1, avatar-2, etc.
        ]);

        $user->update($validated);

        // 🔥 Hapus cache dashboard agar data user terupdate
        Cache::forget("dashboard:{$user->id}");

        return $this->successResponse($user, 'Profile updated successfully.');
    }
}
