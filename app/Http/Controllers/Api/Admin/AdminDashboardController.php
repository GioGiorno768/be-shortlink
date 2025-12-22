<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Link;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    // Statistik global
    public function overview()
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();
        $yesterdayEnd = now()->startOfDay();

        // ============================================
        // 1. BASIC STATS (Totals)
        // ============================================
        $totalUsers = User::count();
        $totalLinks = Link::count();
        $totalClicks = DB::table('views')->count();

        // ============================================
        // 2. TODAY'S STATS + YESTERDAY FOR TRENDS
        // ============================================
        // Use single optimized queries for today/yesterday comparison

        // Payments Today
        $paymentsToday = Payout::where('status', 'approved')
            ->where('updated_at', '>=', $today)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as amount, COUNT(DISTINCT user_id) as users')
            ->first();

        // Payments Yesterday (for trend calculation)
        $paymentsYesterday = Payout::where('status', 'approved')
            ->whereBetween('updated_at', [$yesterday, $yesterdayEnd])
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->first();

        // Links Created Today & Yesterday
        $linksCreatedToday = Link::where('created_at', '>=', $today)->count();
        $linksCreatedYesterday = Link::whereBetween('created_at', [$yesterday, $yesterdayEnd])->count();

        // Links Blocked Today & Yesterday
        $linksBlockedToday = Link::where('is_banned', true)
            ->where('updated_at', '>=', $today)
            ->count();
        $linksBlockedYesterday = Link::where('is_banned', true)
            ->whereBetween('updated_at', [$yesterday, $yesterdayEnd])
            ->count();

        // ============================================
        // 3. TREND CALCULATIONS (% change vs yesterday)
        // ============================================
        $calcTrend = function ($today, $yesterday) {
            if ($yesterday == 0) return $today > 0 ? 100 : 0;
            return round((($today - $yesterday) / $yesterday) * 100, 1);
        };

        $paymentsTrend = $calcTrend($paymentsToday->amount ?? 0, $paymentsYesterday->amount ?? 0);
        $linksTrend = $calcTrend($linksCreatedToday, $linksCreatedYesterday);
        $blockedTrend = $calcTrend($linksBlockedToday, $linksBlockedYesterday);

        // ============================================
        // 4. WITHDRAWAL STATS (General)
        // ============================================
        $pendingWithdrawalsCount = Payout::where('status', 'pending')->count();
        $totalWithdrawalsAmount = Payout::where('status', 'approved')->sum('amount');
        $pendingPaymentsTotal = Payout::where('status', 'pending')->sum('amount');

        // ============================================
        // 5. NEW USERS LAST 5 DAYS (Count per day)
        // ============================================
        $newUsersLast5Days = User::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(5))
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->get();

        // ============================================
        // 6. PENDING WITHDRAWALS LIST (Limit 10) - Enhanced with user details
        // ============================================
        $pendingWithdrawalsList = Payout::with(['user:id,name,email,avatar', 'paymentMethod:id,bank_name,account_number'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($payout) {
                return [
                    'id' => (string) $payout->id,
                    'user' => [
                        'id' => (string) $payout->user?->id,
                        'name' => $payout->user?->name ?? 'Unknown',
                        'email' => $payout->user?->email ?? '',
                        'avatar' => $payout->user?->avatar ?? null,
                    ],
                    'amount' => (float) $payout->amount,
                    'method' => $payout->paymentMethod?->bank_name ?? $payout->method ?? 'Unknown',
                    'account_number' => $payout->paymentMethod?->account_number ?? '',
                    'status' => $payout->status,
                    'date' => $payout->created_at->toISOString(),
                ];
            });

        // ============================================
        // 7. RECENT LINKS LIST (Today, Limit 10)
        // ============================================
        $recentLinksList = Link::select('id', 'code', 'title', 'original_url', 'user_id', 'is_banned', 'created_at')
            ->with('user:id,name,email')
            ->withCount('views')
            ->where('created_at', '>=', $today)
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($link) {
                return [
                    'id' => (string) $link->id,
                    'code' => $link->code,
                    'title' => $link->title ?: 'Untitled',
                    'original_url' => $link->original_url,
                    'short_url' => config('app.viewer_url', 'http://localhost:3001') . '/' . $link->code,
                    'owner' => [
                        'id' => (string) $link->user?->id,
                        'name' => $link->user?->name ?? 'Unknown',
                        'email' => $link->user?->email ?? '',
                    ],
                    'views' => $link->views_count,
                    'status' => $link->is_banned ? 'disabled' : 'active',
                    'created_at' => $link->created_at->toISOString(),
                ];
            });

        // ============================================
        // 8. RECENT USERS LIST (Today, Limit 10)
        // ============================================
        $recentUsersList = User::select('id', 'name', 'email', 'avatar', 'is_banned', 'created_at')
            ->where('role', 'user')
            ->where('created_at', '>=', $today)
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'joined_at' => $user->created_at->toISOString(),
                    'status' => $user->is_banned ? 'suspended' : 'active',
                ];
            });

        // ============================================
        // RESPONSE
        // ============================================
        return $this->successResponse([
            // Basic totals
            'total_users' => $totalUsers,
            'total_links' => $totalLinks,
            'total_clicks' => $totalClicks,

            // Today's stats with trends (for TopStatsCards)
            'payments_today_amount' => (float) ($paymentsToday->amount ?? 0),
            'payments_today_count' => (int) ($paymentsToday->count ?? 0),
            'users_paid_today' => (int) ($paymentsToday->users ?? 0),
            'payments_trend' => $paymentsTrend,

            'links_created_today' => $linksCreatedToday,
            'links_trend' => $linksTrend,

            'links_blocked_today' => $linksBlockedToday,
            'blocked_trend' => $blockedTrend,

            // Withdrawal stats
            'pending_withdrawals' => $pendingWithdrawalsCount,
            'total_withdrawals_amount' => (float) $totalWithdrawalsAmount,
            'pending_payments_total' => (float) $pendingPaymentsTotal,

            // Lists for activity cards
            'new_users_last_5_days' => $newUsersLast5Days,
            'pending_withdrawals_list' => $pendingWithdrawalsList,
            'recent_links_list' => $recentLinksList,
            'recent_users_list' => $recentUsersList,
        ], 'Admin dashboard overview retrieved');
    }

    // Grafik tren user & transaksi
    public function trends()
    {
        $userGrowth = User::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, COUNT(*) as count')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $transactionVolume = Payout::selectRaw('DATE_FORMAT(created_at, "%Y-%m") as month, SUM(amount) as amount')
            ->where('status', 'approved') // Only count approved payouts
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $this->successResponse([
            'user_growth' => $userGrowth,
            'transaction_volume' => $transactionVolume,
        ], 'Trends data retrieved');
    }
}
