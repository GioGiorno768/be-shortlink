<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;

class AdminUserController extends Controller
{
    /**
     * Display a listing of the users.
     */
    public function index(Request $request)
    {
        $query = User::query();

        // Search by name or email
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by role (Strictly only 'user' for this endpoint)
        $query->where('role', 'user');

        // Optional: if you still want to allow filtering by other non-admin roles in the future
        // if ($role = $request->input('role')) {
        //     $query->where('role', $role);
        // }

        // Filter by banned status
        if ($request->has('is_banned')) {
            $query->where('is_banned', $request->boolean('is_banned'));
        }

        $users = $query->latest()->paginate($request->input('per_page', 10));

        // Count active users (last 30 days)
        $activeUsersCount = User::active()->count();

        return $this->successResponse([
            'users' => $users,
            'active_users_count' => $activeUsersCount
        ], 'Users retrieved');
    }

    /**
     * Display the specified user.
     */
    public function show($id)
    {
        $user = User::withCount(['links', 'withdrawals'])->findOrFail($id);
        
        // Append current level info
        $user->append(['current_level', 'bonus_cpm_percentage']);

        return $this->successResponse($user, 'User details retrieved');
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $id,
            'role' => 'sometimes|required|in:user,admin',
            'password' => 'nullable|string|min:8',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update($validated);

        // 🔥 Hapus cache dashboard user
        Cache::forget("dashboard:{$user->id}");

        return $this->successResponse($user, 'User updated successfully.');
    }

    /**
     * Ban the specified user.
     */
    public function ban($id)
    {
        $user = User::findOrFail($id);
        
        // Prevent banning self
        if ($user->id === auth()->id()) {
            return $this->errorResponse('You cannot ban yourself.', 403);
        }

        $user->update(['is_banned' => true]);

        // 🔥 Hapus cache dashboard user
        Cache::forget("dashboard:{$user->id}");

        // Optional: Revoke all tokens to force logout
        $user->tokens()->delete();

        return $this->successResponse(null, 'User has been banned successfully.');
    }

    /**
     * Unban the specified user.
     */
    public function unban($id)
    {
        $user = User::findOrFail($id);
        $user->update(['is_banned' => false]);

        // 🔥 Hapus cache dashboard user
        Cache::forget("dashboard:{$user->id}");

        return $this->successResponse(null, 'User has been unbanned successfully.');
    }
}
