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
    // Statistik global with optional period filter
    public function overview(Request $request)
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();
        $yesterdayEnd = now()->startOfDay();
        $thirtyDaysAgo = now()->subDays(30);
        $sixtyDaysAgo = now()->subDays(60);

        // ============================================
        // 0. PERIOD FILTER HANDLING
        // ============================================
        $period = $request->input('period', 'all');
        $periodStart = null;

        switch ($period) {
            case 'week':
                $periodStart = now()->startOfWeek();
                break;
            case 'month':
                $periodStart = now()->startOfMonth();
                break;
            case 'year':
                $periodStart = now()->startOfYear();
                break;
            default:
                $periodStart = null; // all time
        }

        // ============================================
        // 1. BASIC STATS (Filtered by period if set)
        // ============================================
        $totalUsers = User::where('role', 'user')
            ->when($periodStart, fn($q) => $q->where('created_at', '>=', $periodStart))
            ->count();

        $totalLinks = Link::query()
            ->when($periodStart, fn($q) => $q->where('created_at', '>=', $periodStart))
            ->count();

        $totalClicks = DB::table('views')
            ->when($periodStart, fn($q) => $q->where('created_at', '>=', $periodStart))
            ->count();

        // Active users (in period or last 30 days for 'all')
        $activeUsers = User::where('role', 'user')
            ->where('updated_at', '>=', $periodStart ?? $thirtyDaysAgo)
            ->count();

        // ============================================
        // 1b. REVENUE STATS (NEW - Filtered by period)
        // ============================================
        $totalPaid = Payout::where('status', 'paid')
            ->when($periodStart, fn($q) => $q->where('updated_at', '>=', $periodStart))
            ->sum('amount');

        $totalPending = Payout::where('status', 'pending')
            ->when($periodStart, fn($q) => $q->where('created_at', '>=', $periodStart))
            ->sum('amount');

        $totalTransactions = Payout::where('status', 'paid')
            ->when($periodStart, fn($q) => $q->where('updated_at', '>=', $periodStart))
            ->count();

        // Est. Revenue (reverse calculation from user earnings)
        // Assuming users get ~70% of ad revenue
        $estRevenue = $totalPaid > 0 ? round($totalPaid / 0.7, 2) : 0;

        // ============================================
        // 1c. GROWTH CALCULATIONS (vs previous period)
        // ============================================
        // Users growth
        $previousPeriodUsers = User::where('role', 'user')
            ->where('created_at', '<', $thirtyDaysAgo)
            ->where('created_at', '>=', $sixtyDaysAgo)
            ->count();
        $recentUsers = User::where('role', 'user')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        // Links growth
        $previousPeriodLinks = Link::where('created_at', '<', $thirtyDaysAgo)
            ->where('created_at', '>=', $sixtyDaysAgo)
            ->count();
        $recentLinks = Link::where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        // Clicks growth
        $previousPeriodClicks = DB::table('views')
            ->where('created_at', '<', $thirtyDaysAgo)
            ->where('created_at', '>=', $sixtyDaysAgo)
            ->count();
        $recentClicks = DB::table('views')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->count();

        // Active users growth
        $previousActiveUsers = User::where('role', 'user')
            ->where('updated_at', '<', $thirtyDaysAgo)
            ->where('updated_at', '>=', $sixtyDaysAgo)
            ->count();

        $calcGrowth = function ($recent, $previous) {
            if ($previous == 0) return $recent > 0 ? 100 : 0;
            return round((($recent - $previous) / $previous) * 100, 1);
        };

        $totalUsersGrowth = $calcGrowth($recentUsers, $previousPeriodUsers);
        $totalLinksGrowth = $calcGrowth($recentLinks, $previousPeriodLinks);
        $totalClicksGrowth = $calcGrowth($recentClicks, $previousPeriodClicks);
        $activeUsersGrowth = $calcGrowth($activeUsers, $previousActiveUsers);

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

        // Staff Online (Active in last 15 mins) & Total Staff
        // Assuming roles: 'admin', 'super-admin'
        $staffRoles = ['admin', 'super-admin', 'super_admin'];
        $staffOnline = User::whereIn('role', $staffRoles)
            ->where('last_active_at', '>=', now()->subMinutes(15))
            ->count();
        $totalStaff = User::whereIn('role', $staffRoles)->count();
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
            // Basic totals + growth (for Platform Analytics)
            'total_users' => $totalUsers,
            'total_users_growth' => $totalUsersGrowth,
            'active_users' => $activeUsers,
            'active_users_growth' => $activeUsersGrowth,
            'total_links' => $totalLinks,
            'total_links_growth' => $totalLinksGrowth,
            'total_clicks' => $totalClicks,
            'total_clicks_growth' => $totalClicksGrowth,

            // Revenue stats (for Platform Analytics - filtered by period)
            'est_revenue' => (float) $estRevenue,
            'total_paid' => (float) $totalPaid,
            'total_pending' => (float) $totalPending,
            'total_transactions' => (int) $totalTransactions,

            // Today's stats with trends (for TopStatsCards)
            'payments_today_amount' => (float) ($paymentsToday->amount ?? 0),
            'payments_today_count' => (int) ($paymentsToday->count ?? 0),
            'users_paid_today' => (int) ($paymentsToday->users ?? 0),
            'payments_trend' => $paymentsTrend,

            'links_created_today' => $linksCreatedToday,
            'links_trend' => $linksTrend,

            'links_blocked_today' => $linksBlockedToday,
            'blocked_trend' => $blockedTrend,

            'staff_online' => $staffOnline,
            'total_staff' => $totalStaff,

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
            ->where('status', 'paid')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        return $this->successResponse([
            'user_growth' => $userGrowth,
            'transaction_volume' => $transactionVolume,
        ], 'Trends data retrieved');
    }

    /**
     * Platform-wide top countries by views
     * Uses aggregated user_country_stats table for performance
     */
    public function topCountries()
    {
        // Country code to name mapping
        $countryNames = [
            'ID' => 'Indonesia',
            'US' => 'United States',
            'GB' => 'United Kingdom',
            'MY' => 'Malaysia',
            'SG' => 'Singapore',
            'PH' => 'Philippines',
            'TH' => 'Thailand',
            'VN' => 'Vietnam',
            'IN' => 'India',
            'AU' => 'Australia',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'DE' => 'Germany',
            'FR' => 'France',
            'BR' => 'Brazil',
            'CA' => 'Canada',
            'NL' => 'Netherlands',
            'IT' => 'Italy',
        ];

        // Get views grouped by country from aggregated table
        $countries = DB::table('user_country_stats')
            ->selectRaw('country_code, SUM(view_count) as views')
            ->whereNotNull('country_code')
            ->groupBy('country_code')
            ->orderByDesc('views')
            ->limit(10)
            ->get();

        $totalViews = DB::table('user_country_stats')->sum('view_count');

        // Calculate percentages
        $items = $countries->map(function ($item) use ($totalViews, $countryNames) {
            $code = $item->country_code ?? 'OTHER';
            return [
                'country_code' => $code,
                'country_name' => $countryNames[$code] ?? $code,
                'views' => (int) $item->views,
                'percentage' => $totalViews > 0
                    ? round(($item->views / $totalViews) * 100, 1)
                    : 0,
            ];
        });

        return $this->successResponse([
            'items' => $items,
            'total_views' => (int) $totalViews,
        ], 'Top countries retrieved');
    }

    /**
     * Revenue estimation chart data
     * Returns user earnings and estimated platform revenue
     */
    public function revenueChart(Request $request)
    {
        $period = $request->input('period', 'perWeek');

        $categories = [];
        $userEarnings = [];

        if ($period === 'perWeek') {
            // Last 7 days
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i);
                $categories[] = $date->format('D'); // Mon, Tue, etc.

                $earnings = Payout::where('status', 'paid')
                    ->whereDate('updated_at', $date->toDateString())
                    ->sum('amount');
                $userEarnings[] = round($earnings, 2);
            }
        } elseif ($period === 'perMonth') {
            // Last 4 weeks
            for ($i = 3; $i >= 0; $i--) {
                $weekStart = now()->subWeeks($i)->startOfWeek();
                $weekEnd = now()->subWeeks($i)->endOfWeek();
                $categories[] = 'Week ' . (4 - $i);

                $earnings = Payout::where('status', 'paid')
                    ->whereBetween('updated_at', [$weekStart, $weekEnd])
                    ->sum('amount');
                $userEarnings[] = round($earnings, 2);
            }
        } else {
            // perYear - Last 12 months
            for ($i = 11; $i >= 0; $i--) {
                $month = now()->subMonths($i);
                $categories[] = $month->format('M'); // Jan, Feb, etc.

                $earnings = Payout::where('status', 'paid')
                    ->whereMonth('updated_at', $month->month)
                    ->whereYear('updated_at', $month->year)
                    ->sum('amount');
                $userEarnings[] = round($earnings, 2);
            }
        }

        // Calculate estimated platform revenue (user gets ~70%)
        $estimatedRevenue = array_map(fn($val) => round($val / 0.7, 2), $userEarnings);

        return $this->successResponse([
            'categories' => $categories,
            'series' => [
                [
                    'name' => 'Est. Platform Revenue (100%)',
                    'data' => $estimatedRevenue,
                ],
                [
                    'name' => 'User Earnings (70%)',
                    'data' => $userEarnings,
                ],
            ],
        ], 'Revenue chart data retrieved');
    }

    /**
     * Active users chart data
     * Returns user activity trends
     */
    public function activeUsersChart(Request $request)
    {
        $period = $request->input('period', 'week');

        $categories = [];
        $activeUsers = [];

        if ($period === 'week') {
            // Last 7 days - daily active users
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i);
                $categories[] = $date->format('D');

                $count = User::where('role', 'user')
                    ->whereDate('updated_at', $date->toDateString())
                    ->count();
                $activeUsers[] = $count;
            }
        } elseif ($period === 'month') {
            // Last 4 weeks
            for ($i = 3; $i >= 0; $i--) {
                $weekStart = now()->subWeeks($i)->startOfWeek();
                $weekEnd = now()->subWeeks($i)->endOfWeek();
                $categories[] = 'Week ' . (4 - $i);

                $count = User::where('role', 'user')
                    ->whereBetween('updated_at', [$weekStart, $weekEnd])
                    ->count();
                $activeUsers[] = $count;
            }
        } else {
            // year - Last 12 months
            for ($i = 11; $i >= 0; $i--) {
                $month = now()->subMonths($i);
                $categories[] = $month->format('M');

                $count = User::where('role', 'user')
                    ->whereMonth('updated_at', $month->month)
                    ->whereYear('updated_at', $month->year)
                    ->count();
                $activeUsers[] = $count;
            }
        }

        return $this->successResponse([
            'categories' => $categories,
            'series' => [
                [
                    'name' => 'Active Users',
                    'data' => $activeUsers,
                ],
            ],
        ], 'Active users chart data retrieved');
    }
}
