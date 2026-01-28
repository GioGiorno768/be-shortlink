<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class SuperAdminController extends Controller
{
    /**
     * List all admins with pagination, search, and filters
     * GET /super-admin/admins?page=1&per_page=10&search=&status=&role=
     */
    public function index(Request $request)
    {
        $perPage = min($request->input('per_page', 10), 50);
        $search = $request->input('search');
        $status = $request->input('status'); // active, suspended, all
        $role = $request->input('role'); // admin, super-admin, all

        $query = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->select([
                'id',
                'name',
                'email',
                'role',
                'is_banned',
                'created_at',
                'updated_at',
                'avatar',
                'last_active_at'
            ]);

        // Search by name or email
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($status && $status !== 'all') {
            if ($status === 'suspended') {
                $query->where('is_banned', true);
            } elseif ($status === 'active') {
                $query->where('is_banned', false);
            }
        }

        // Filter by role
        if ($role && $role !== 'all') {
            if ($role === 'admin') {
                $query->where('role', User::ROLE_ADMIN);
            } elseif ($role === 'super-admin') {
                $query->where('role', User::ROLE_SUPER_ADMIN);
            }
        }

        // Order by newest first
        $query->latest('created_at');

        $admins = $query->paginate($perPage);

        // Transform response
        $data = $admins->getCollection()->map(function ($admin) {
            return [
                'id' => (string) $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'username' => explode('@', $admin->email)[0], // Extract username from email
                'avatarUrl' => $admin->avatar ? "/avatars/{$admin->avatar}.webp" : '',
                'role' => $admin->role,
                'status' => $admin->is_banned ? 'suspended' : 'active',
                'joinedAt' => $admin->created_at->toIso8601String(),
                'lastLogin' => $admin->last_active_at
                    ? Carbon::parse($admin->last_active_at)->toIso8601String()
                    : null,
                'stats' => [
                    'usersManaged' => 0, // TODO: Implement if needed
                    'withdrawalsProcessed' => 0,
                    'linksBlocked' => 0,
                ],
            ];
        });

        return $this->paginatedResponse(
            $admins->setCollection($data),
            'Admins retrieved'
        );
    }

    /**
     * Get admin statistics
     * GET /super-admin/admins/stats
     */
    public function stats()
    {
        // Cache for 5 minutes to reduce DB queries
        $stats = Cache::remember('super_admin_stats', 300, function () {
            $baseQuery = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN]);

            $totalAdmins = (clone $baseQuery)->count();
            $activeToday = (clone $baseQuery)
                ->where('is_banned', false)
                ->where('last_active_at', '>=', Carbon::today())
                ->count();
            $suspendedAdmins = (clone $baseQuery)
                ->where('is_banned', true)
                ->count();

            // Calculate trends (compare with 7 days ago)
            $weekAgo = Carbon::now()->subDays(7);
            $newThisWeek = (clone $baseQuery)
                ->where('created_at', '>=', $weekAgo)
                ->count();

            return [
                'totalAdmins' => [
                    'count' => $totalAdmins,
                    'trend' => $totalAdmins > 0 ? round(($newThisWeek / $totalAdmins) * 100, 1) : 0,
                ],
                'activeToday' => [
                    'count' => $activeToday,
                    'trend' => $totalAdmins > 0 ? round(($activeToday / $totalAdmins) * 100, 1) : 0,
                ],
                'suspendedAdmins' => [
                    'count' => $suspendedAdmins,
                    'trend' => $totalAdmins > 0 ? round(($suspendedAdmins / $totalAdmins) * 100, 1) : 0,
                ],
            ];
        });

        return $this->successResponse($stats, 'Admin stats retrieved');
    }

    /**
     * Create a new admin
     * POST /super-admin/admins
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'nullable|string|max:255',
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
            'is_banned' => false,
        ]);

        // Clear stats cache
        Cache::forget('super_admin_stats');

        // Return formatted response
        return $this->successResponse([
            'id' => (string) $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'username' => $validated['username'] ?? explode('@', $admin->email)[0],
            'avatarUrl' => '',
            'role' => $admin->role,
            'status' => 'active',
            'joinedAt' => $admin->created_at->toIso8601String(),
            'lastLogin' => null,
            'stats' => [
                'usersManaged' => 0,
                'withdrawalsProcessed' => 0,
                'linksBlocked' => 0,
            ],
        ], 'Admin created successfully', 201);
    }

    /**
     * Update an admin
     * PUT /super-admin/admins/{id}
     */
    public function update(Request $request, $id)
    {
        $admin = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique('users')->ignore($admin->id)],
            'password' => 'nullable|string|min:8',
            'status' => 'sometimes|in:active,suspended',
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
        if ($request->has('status')) {
            $admin->is_banned = $validated['status'] === 'suspended';
        }

        $admin->save();

        // Clear stats cache
        Cache::forget('super_admin_stats');

        return $this->successResponse([
            'id' => (string) $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $admin->role,
            'status' => $admin->is_banned ? 'suspended' : 'active',
        ], 'Admin updated successfully');
    }

    /**
     * Toggle admin status (suspend/unsuspend)
     * PATCH /super-admin/admins/{id}/toggle-status
     */
    public function toggleStatus($id)
    {
        $admin = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->findOrFail($id);

        // Toggle the banned status
        $admin->is_banned = !$admin->is_banned;
        $admin->save();

        // Clear stats cache
        Cache::forget('super_admin_stats');

        $newStatus = $admin->is_banned ? 'suspended' : 'active';

        return $this->successResponse([
            'id' => (string) $admin->id,
            'name' => $admin->name,
            'status' => $newStatus,
        ], "Admin {$newStatus} successfully");
    }

    /**
     * Delete an admin
     * DELETE /super-admin/admins/{id}
     */
    public function destroy($id)
    {
        $admin = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->findOrFail($id);

        // Prevent deleting yourself
        if (request()->user()->id === $admin->id) {
            return $this->errorResponse('You cannot delete your own account', 400);
        }

        $admin->delete();

        // Clear stats cache
        Cache::forget('super_admin_stats');

        return $this->successResponse(null, 'Admin deleted successfully');
    }

    /**
     * Get withdrawal logs processed by admins
     * GET /super-admin/withdrawal-logs
     */
    public function getWithdrawalLogs(Request $request)
    {
        $perPage = $request->input('per_page', 10);

        $logs = Payout::with(['user:id,name,email', 'processedBy:id,name'])
            ->whereNotNull('processed_by')
            ->latest()
            ->paginate($perPage);

        return $this->paginatedResponse($logs, 'Withdrawal logs retrieved');
    }
}
