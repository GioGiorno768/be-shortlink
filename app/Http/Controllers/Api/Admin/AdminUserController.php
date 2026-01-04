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
        $query = User::query()
            ->withCount(['links']);

        // Search by name or email
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by role (Strictly only 'user' for this endpoint)
        $query->where('role', 'user');

        // Filter by banned status
        if ($request->has('is_banned')) {
            $query->where('is_banned', $request->boolean('is_banned'));
        }

        $users = $query->latest()->paginate($request->input('per_page', 10));

        // Count active users (last 30 days)
        $activeUsersCount = User::where('role', 'user')->active()->count();

        return $this->successResponse([
            'users' => $users,
            'active_users_count' => $activeUsersCount
        ], 'Users retrieved');
    }

    /**
     * Display the specified user with extended details.
     */
    public function show($id)
    {
        $user = User::with(['paymentMethods', 'currentLevel'])
            ->withCount(['links'])
            ->findOrFail($id);

        // Get stats directly from users table (same as user-side calculation)
        $totalValidViews = $user->total_valid_views ?? 0;
        $totalEarnings = $user->total_earnings ?? 0;

        // Calculate avg CPM (earnings per 1000 valid views) - SAME as user-side
        // Formula: (total_earnings / total_valid_views) * 1000
        $avgCpm = $totalValidViews > 0
            ? round(($totalEarnings / $totalValidViews) * 1000, 2)
            : 0;

        // Get withdrawal history (last 20)
        $withdrawalHistory = $user->payouts()
            ->with('paymentMethod')
            ->orderBy('created_at', 'desc')
            ->take(20)
            ->get()
            ->map(function ($payout) {
                return [
                    'id' => $payout->id,
                    'tx_id' => $payout->transaction_id,
                    'date' => $payout->created_at->toISOString(),
                    'amount' => (float) $payout->amount,
                    'fee' => (float) ($payout->fee ?? 0),
                    'method' => $payout->paymentMethod->bank_name ?? $payout->method,
                    'account' => $payout->paymentMethod->account_number ?? '',
                    'status' => $payout->status,
                ];
            });

        // Format payment methods (no masking for admin view)
        $paymentMethods = $user->paymentMethods->map(function ($pm) {
            return [
                'id' => $pm->id,
                'provider' => $pm->bank_name,
                'account_name' => $pm->account_name,
                'account_number' => $pm->account_number,
                'is_default' => (bool) $pm->is_default,
                'category' => $this->getPaymentCategory($pm->method_type),
                'fee' => (float) ($pm->fee ?? 0),
            ];
        });

        // Build response
        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'username' => explode('@', $user->email)[0], // Use email prefix as username
            'email' => $user->email,
            'avatar_url' => $user->avatar ? "/avatars/{$user->avatar}.webp" : null,
            'status' => $user->is_banned ? 'suspended' : 'active',
            'joined_at' => $user->created_at->toISOString(),
            'last_login' => $user->last_active_at?->toISOString(),
            'stats' => [
                'total_links' => $user->links_count,
                'total_views' => (int) $totalValidViews, // Using valid views
                'wallet_balance' => (float) ($user->balance ?? 0),
                'total_earnings' => (float) $totalEarnings,
                'avg_cpm' => $avgCpm,
            ],
            'current_level' => $user->current_level,
            'payment_methods' => $paymentMethods,
            'withdrawal_history' => $withdrawalHistory,
        ];

        return $this->successResponse($userData, 'User details retrieved');
    }

    /**
     * Mask account number for privacy.
     */
    private function maskAccountNumber(string $account): string
    {
        if (strlen($account) <= 4) {
            return $account;
        }

        $visibleStart = substr($account, 0, 4);
        $visibleEnd = substr($account, -4);

        return $visibleStart . '****' . $visibleEnd;
    }

    /**
     * Get payment category from method type.
     */
    private function getPaymentCategory(?string $methodType): string
    {
        $wallets = ['dana', 'gopay', 'ovo', 'shopeepay', 'linkaja'];
        $crypto = ['btc', 'eth', 'usdt', 'crypto'];

        $type = strtolower($methodType ?? '');

        if (in_array($type, $wallets)) {
            return 'wallet';
        }
        if (in_array($type, $crypto)) {
            return 'crypto';
        }
        return 'bank';
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
    public function ban(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $user = User::findOrFail($id);

        // Prevent banning self
        if ($user->id === auth()->id()) {
            return $this->errorResponse('You cannot ban yourself.', 403);
        }

        $user->update([
            'is_banned' => true,
            'ban_reason' => $validated['reason'],
        ]);

        // 🔥 Hapus cache dashboard user
        Cache::forget("dashboard:{$user->id}");

        // ⚠️ IMPORTANT: Do NOT delete tokens here!
        // Let the CheckBanned middleware detect the ban and return 403 with ban_reason
        // This allows the frontend to show the ban popup with the reason
        // Token will be deleted when user confirms and logs out

        return $this->successResponse(null, 'User has been banned successfully.');
    }

    /**
     * Unban the specified user.
     */
    public function unban($id)
    {
        $user = User::findOrFail($id);
        $user->update([
            'is_banned' => false,
            'ban_reason' => null, // Clear ban reason
        ]);

        // 🔥 Hapus cache dashboard user
        Cache::forget("dashboard:{$user->id}");

        return $this->successResponse(null, 'User has been unbanned successfully.');
    }

    /**
     * Get user statistics for dashboard.
     */
    public function stats()
    {
        // Cache stats for 5 minutes to reduce DB load
        $stats = Cache::remember('admin:users:stats', 300, function () {
            $now = now();
            $today = $now->copy()->startOfDay();
            $yesterday = $now->copy()->subDay()->startOfDay();
            $yesterdayEnd = $now->copy()->subDay()->endOfDay();

            // Base query - only count regular users (not admins)
            $baseQuery = User::where('role', 'user');

            // Total Users
            $totalUsersToday = (clone $baseQuery)->count();
            $totalUsersYesterday = (clone $baseQuery)
                ->where('created_at', '<', $today)
                ->count();

            // Calculate trend (percentage change)
            $totalUsersTrend = $totalUsersYesterday > 0
                ? round((($totalUsersToday - $totalUsersYesterday) / $totalUsersYesterday) * 100, 1)
                : 0;

            // Active Today (logged in within last 24 hours) - using last_active_at
            $activeTodayCount = (clone $baseQuery)
                ->where('last_active_at', '>=', $today)
                ->count();
            $activeYesterdayCount = (clone $baseQuery)
                ->whereBetween('last_active_at', [$yesterday, $yesterdayEnd])
                ->count();

            $activeTodayTrend = $activeYesterdayCount > 0
                ? round((($activeTodayCount - $activeYesterdayCount) / $activeYesterdayCount) * 100, 1)
                : 0;

            // Suspended Users
            $suspendedCount = (clone $baseQuery)->where('is_banned', true)->count();
            $suspendedTrend = 0;

            return [
                'total_users' => [
                    'count' => $totalUsersToday,
                    'trend' => $totalUsersTrend,
                ],
                'active_today' => [
                    'count' => $activeTodayCount,
                    'trend' => $activeTodayTrend,
                ],
                'suspended_users' => [
                    'count' => $suspendedCount,
                    'trend' => $suspendedTrend,
                ],
            ];
        });

        return $this->successResponse($stats, 'User stats retrieved');
    }

    /**
     * Send bulk notification to users.
     */
    public function notify(Request $request)
    {
        $validated = $request->validate([
            'user_ids' => 'required_without:select_all|array',
            'user_ids.*' => 'integer|exists:users,id',
            'select_all' => 'boolean',
            'filters' => 'array',
            'filters.search' => 'nullable|string',
            'filters.status' => 'nullable|string|in:all,active,suspended',
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:2000',
            'type' => 'required|string|in:warning,info',
        ]);

        $selectAll = $validated['select_all'] ?? false;
        $filters = $validated['filters'] ?? [];

        // Build query for target users
        if ($selectAll) {
            // Get all users matching filters
            $query = User::where('role', 'user');

            if (!empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if (!empty($filters['status']) && $filters['status'] !== 'all') {
                $isBanned = $filters['status'] === 'suspended';
                $query->where('is_banned', $isBanned);
            }

            $users = $query->get();
        } else {
            // Get specific users by IDs
            $users = User::whereIn('id', $validated['user_ids'])->get();
        }

        if ($users->isEmpty()) {
            return $this->errorResponse('No users found to notify.', 400);
        }

        // Create notification
        $notification = new \App\Notifications\GeneralNotification(
            $validated['subject'],
            $validated['message'],
            $validated['type'],  // warning or info
            'account',           // category
            null,                // actionUrl
            null                 // expiresAt (permanent)
        );

        // Send notification to all target users
        \Illuminate\Support\Facades\Notification::send($users, $notification);

        return $this->successResponse([
            'users_notified' => $users->count(),
        ], "Notification sent to {$users->count()} users.");
    }
}
