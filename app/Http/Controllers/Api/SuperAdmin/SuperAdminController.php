<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Payout; // ✅ Import Payout
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SuperAdminController extends Controller
{
    /**
     * List all admins
     */
    public function index()
    {
        $admins = User::where('role', User::ROLE_ADMIN)->get();
        return $this->successResponse($admins, 'Admins retrieved');
    }

    /**
     * Create a new admin
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        $admin = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_ADMIN,
            'email_verified_at' => now(),
            'referral_code' => User::generateReferralCode(),
        ]);

        return $this->successResponse($admin, 'Admin created successfully', 201);
    }

    /**
     * Update an admin
     */
    public function update(Request $request, $id)
    {
        $admin = User::where('role', User::ROLE_ADMIN)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($admin->id)],
            'password' => 'nullable|string|min:8',
        ]);

        if ($request->has('name')) {
            $admin->name = $validated['name'];
        }
        if ($request->has('email')) {
            $admin->email = $validated['email'];
        }
        if ($request->filled('password')) {
            $admin->password = Hash::make($validated['password']);
        }

        $admin->save();

        return $this->successResponse($admin, 'Admin updated successfully');
    }

    /**
     * Delete an admin
     */
    public function destroy($id)
    {
        $admin = User::where('role', User::ROLE_ADMIN)->findOrFail($id);
        $admin->delete();

        return $this->successResponse(null, 'Admin deleted successfully');
    }

    /**
     * Get withdrawal logs
     */
    public function getWithdrawalLogs(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        
        $logs = Payout::with(['user', 'processedBy'])
            ->whereNotNull('processed_by') // Hanya yang sudah diproses
            ->latest()
            ->paginate($perPage);

        return $this->paginatedResponse($logs, 'Withdrawal logs retrieved');
    }
}
