<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\View;
use App\Models\Payout;
use App\Models\User;
use App\Models\Level;
use App\Models\UserCountryStat;
use App\Models\UserReferrerStat;
use App\Models\UserDailyStat;
use Carbon\Carbon;

class StatsController extends Controller
{
    // Methods dashboard() and index() removed as requested.

    /**
     * 🌍 Endpoint Top Countries
     * GET /analytics/top-countries
     * 🔧 Uses aggregate table for fast lookups
     */
    public function topCountries(Request $request)
    {
        $user = $request->user();
        $limit = $request->query('limit', 7);

        // 🔧 OPTIMIZED: Use centralized config for constants
        $defaultCountryCodes = config('constants.default_countries');
        $countryNames = config('constants.countries');

        // ============================================
        // 📊 Fetch user data dari aggregate table
        // ============================================
        $stats = UserCountryStat::where('user_id', $user->id)->get()->keyBy('country_code');
        $totalViews = $stats->sum('view_count');

        // ============================================
        // 🔀 Merge real data + defaults
        // ============================================
        $mergedItems = collect();

        // 1. Add all countries from real data
        foreach ($stats as $code => $stat) {
            $mergedItems->put($code, [
                'country_code' => $code,
                'country_name' => $countryNames[$code] ?? $code,
                'views' => (int) $stat->view_count,
                'percentage' => $totalViews > 0 ? round(($stat->view_count / $totalViews) * 100, 1) : 0,
            ]);
        }

        // 2. Fill with defaults that don't have data yet
        foreach ($defaultCountryCodes as $code) {
            if (!$mergedItems->has($code)) {
                $mergedItems->put($code, [
                    'country_code' => $code,
                    'country_name' => $countryNames[$code] ?? $code,
                    'views' => 0,
                    'percentage' => 0,
                ]);
            }
        }

        // 3. Sort by views descending, then take limit
        $sortedItems = $mergedItems->sortByDesc('views')->values()->take($limit);

        return $this->successResponse([
            'total_views' => (int) $totalViews,
            'items' => $sortedItems
        ], 'Top countries retrieved');
    }

    /**
     * 🔗 Endpoint Top Referrers
     * GET /analytics/top-referrers
     * 🔧 Uses aggregate table for fast lookups
     */
    public function topReferrers(Request $request)
    {
        $user = $request->user();
        $limit = $request->query('limit', 8);

        // 🔧 OPTIMIZED: Use centralized config for constants
        $defaultReferrerKeys = config('constants.default_referrers');
        $referrerLabels = config('constants.referrer_labels');

        // ============================================
        // 📊 Fetch user data dari aggregate table
        // ============================================
        $stats = UserReferrerStat::where('user_id', $user->id)->get()->keyBy('referrer_key');
        $totalViews = $stats->sum('view_count');

        // ============================================
        // 🔀 Merge real data + defaults
        // ============================================
        $mergedItems = collect();

        // 1. Add all referrers from real data
        foreach ($stats as $key => $stat) {
            $mergedItems->put($key, [
                'referrer_key' => $key,
                'referrer_label' => $referrerLabels[$key] ?? UserReferrerStat::getLabel($key),
                'views' => (int) $stat->view_count,
                'percentage' => $totalViews > 0 ? round(($stat->view_count / $totalViews) * 100, 1) : 0,
            ]);
        }

        // 2. Fill with defaults that don't have data yet
        foreach ($defaultReferrerKeys as $key) {
            if (!$mergedItems->has($key)) {
                $mergedItems->put($key, [
                    'referrer_key' => $key,
                    'referrer_label' => $referrerLabels[$key] ?? ucfirst($key),
                    'views' => 0,
                    'percentage' => 0,
                ]);
            }
        }

        // 3. Sort by views descending, then take limit
        $sortedItems = $mergedItems->sortByDesc('views')->values()->take($limit);

        return $this->successResponse([
            'total_views' => (int) $totalViews,
            'items' => $sortedItems
        ], 'Top referrers retrieved');
    }

    // Helper: Parse Referrer URL to Clean Label
    private function parseReferrer($url)
    {
        if (empty($url)) {
            return ['key' => 'direct', 'label' => 'Direct / Email / SMS'];
        }

        $host = parse_url($url, PHP_URL_HOST);
        $host = str_replace('www.', '', strtolower($host ?? ''));

        // Mapping Domain Umum
        if (str_contains($host, 'google')) return ['key' => 'google', 'label' => 'Google'];
        if (str_contains($host, 'facebook') || str_contains($host, 'fb.com')) return ['key' => 'facebook', 'label' => 'Facebook'];
        if (str_contains($host, 't.co') || str_contains($host, 'twitter') || str_contains($host, 'x.com')) return ['key' => 'twitter_x', 'label' => 'Twitter / X'];
        if (str_contains($host, 'instagram')) return ['key' => 'instagram', 'label' => 'Instagram'];
        if (str_contains($host, 'youtube') || str_contains($host, 'youtu.be')) return ['key' => 'youtube', 'label' => 'YouTube'];
        if (str_contains($host, 'whatsapp')) return ['key' => 'whatsapp', 'label' => 'WhatsApp'];
        if (str_contains($host, 'telegram') || str_contains($host, 't.me')) return ['key' => 'telegram', 'label' => 'Telegram'];
        if (str_contains($host, 'tiktok')) return ['key' => 'tiktok', 'label' => 'TikTok'];
        if (str_contains($host, 'linkedin')) return ['key' => 'linkedin', 'label' => 'LinkedIn'];

        // Default: Ambil domain utamanya saja
        return ['key' => $host, 'label' => $host];
    }

    // =========================================================================
    // 📊 MOVED & NEW METHODS
    // =========================================================================

    /**
     * GET /dashboard/summary/earnings
     * Cached for 2 minutes per user+range
     * 🔧 FIX: Now properly filters by date range
     */
    public function getEarnings(Request $request)
    {
        $user = $request->user();
        $dateData = $this->getDateRange($request);
        $cacheKey = "stats:earnings:{$user->id}:{$dateData['range']}";

        $totalEarnings = Cache::remember($cacheKey, 120, function () use ($user, $dateData) {
            // For lifetime, use user's total (faster)
            if ($dateData['range'] === 'lifetime') {
                return $user->total_earnings ?? 0;
            }

            // 🔧 FIX: Query from aggregate table instead of views
            return UserDailyStat::getEarningsBetween(
                $user->id,
                $dateData['start'],
                $dateData['end']
            );
        });

        return $this->successResponse([
            'currency' => 'USD',
            'range' => $dateData['range'],
            'from_date' => $dateData['start']->format('Y-m-d'),
            'to_date' => $dateData['end']->format('Y-m-d'),
            'total_earnings' => (float) $totalEarnings,
        ], 'Earnings retrieved');
    }

    /**
     * GET /dashboard/summary/clicks
     * Mengembalikan VALID CLICKS sesuai request user
     * Cached for 2 minutes per user+range
     * 🔧 FIX: Now properly filters by date range
     */
    public function getClicks(Request $request)
    {
        $user = $request->user();
        $dateData = $this->getDateRange($request);
        $cacheKey = "stats:clicks:{$user->id}:{$dateData['range']}";

        $totalClicks = Cache::remember($cacheKey, 120, function () use ($user, $dateData) {
            // For lifetime, use user's total (faster)
            if ($dateData['range'] === 'lifetime') {
                return $user->total_valid_views ?? 0;
            }

            // 🔧 FIX: Query from aggregate table instead of views
            return UserDailyStat::getValidViewsBetween(
                $user->id,
                $dateData['start'],
                $dateData['end']
            );
        });

        return $this->successResponse([
            'range' => $dateData['range'],
            'from_date' => $dateData['start']->format('Y-m-d'),
            'to_date' => $dateData['end']->format('Y-m-d'),
            'total_clicks' => (int) $totalClicks,
        ], 'Clicks retrieved');
    }

    /**
     * GET /dashboard/summary/referrals
     * Menampilkan jumlah user yang mendaftar lewat referral code user ini
     * Cached for 2 minutes per user+range
     */
    public function getReferralStats(Request $request)
    {
        $user = $request->user();
        $dateData = $this->getDateRange($request);
        $cacheKey = "stats:referrals:{$user->id}:{$dateData['range']}";

        $referralCount = Cache::remember($cacheKey, 120, function () use ($user, $dateData) {
            if ($dateData['range'] === 'lifetime') {
                return $user->total_referrals;
            }
            return User::where('referred_by', $user->id)
                ->whereBetween('created_at', [$dateData['start']->utc(), $dateData['end']->utc()])
                ->count();
        });

        return $this->successResponse([
            'range' => $dateData['range'],
            'from_date' => $dateData['start']->format('Y-m-d'),
            'to_date' => $dateData['end']->format('Y-m-d'),
            'referral_count' => (int) $referralCount,
        ], 'Referral stats retrieved');
    }

    /**
     * GET /dashboard/summary/cpm
     * Menampilkan Rata-rata CPM (Cost Per Mille)
     * Rumus: (Total Earnings / Total Valid Views) * 1000
     * 🔧 FIX: Now properly filters by date range
     */
    public function getAverageCpm(Request $request)
    {
        $user = $request->user();
        $dateData = $this->getDateRange($request);
        $cacheKey = "stats:cpm:{$user->id}:{$dateData['range']}";

        $cpm = Cache::remember($cacheKey, 120, function () use ($user, $dateData) {
            // For lifetime, use user's totals (faster)
            if ($dateData['range'] === 'lifetime') {
                $totalViews = $user->total_valid_views ?? 0;
                $totalEarnings = $user->total_earnings ?? 0;
                return $totalViews > 0 ? ($totalEarnings / $totalViews) * 1000 : 0;
            }

            // 🔧 FIX: Query from aggregate table instead of views
            $stats = UserDailyStat::getStatsBetween(
                $user->id,
                $dateData['start'],
                $dateData['end']
            );

            $totalEarnings = $stats['earnings'];
            $totalViews = $stats['valid_views'];

            return $totalViews > 0 ? ($totalEarnings / $totalViews) * 1000 : 0;
        });

        return $this->successResponse([
            'range' => $dateData['range'],
            'from_date' => $dateData['start']->format('Y-m-d'),
            'to_date' => $dateData['end']->format('Y-m-d'),
            'average_cpm' => round($cpm, 2),
        ], 'Average CPM retrieved');
    }

    /**
     * GET /analytics/monthly-performance
     * Menampilkan performa bulanan: clicks, earnings, cpm, dan user level history
     * 
     * 🔧 FIX: Falls back to users table if views table is empty (FULL_REDIS_MODE)
     */
    public function monthlyPerformance(Request $request)
    {
        $user = $request->user();
        $timezone = $request->query('timezone', 'Asia/Jakarta');

        // Default range: 12 bulan terakhir jika tidak ada filter spesifik
        $dateData = $this->getDateRange($request);
        $start = $dateData['start'];
        $end = $dateData['end'];

        // 1. Hitung Initial Cumulative Earnings (Pendapatan sebelum periode start)
        $initialEarnings = View::whereHas('link', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
            ->where('created_at', '<', $start->copy()->utc())
            ->sum('earned');

        // 2. Ambil Data Views Group by Month
        $tzOffset = Carbon::now($timezone)->format('P');

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            $monthExpression = "strftime('%Y-%m', created_at)";
        } else {
            $monthExpression = "DATE_FORMAT(CONVERT_TZ(created_at, '+00:00', '$tzOffset'), '%Y-%m')";
        }

        $monthlyData = View::whereHas('link', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
            ->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->select(
                DB::raw("$monthExpression as month"),
                DB::raw("SUM(earned) as earnings"),
                DB::raw("COUNT(*) as total_views"),
                DB::raw("SUM(CASE WHEN is_valid = 1 THEN 1 ELSE 0 END) as valid_clicks")
            )
            ->groupBy('month')
            ->orderBy('month', 'asc')
            ->get()
            ->keyBy('month');

        // 🔧 FIX: Check if views table is empty but user has earnings (FULL_REDIS_MODE active)
        // 🔧 FIX: Always use users table for current month since views table may be incomplete
        // This ensures data consistency with header/stats which also use users table
        $currentMonthKey = Carbon::now($timezone)->format('Y-m');

        // 3. Loop dari Start sampai End per Bulan
        $result = [];
        $current = $start->copy()->startOfMonth();
        $cumulativeEarnings = $initialEarnings;

        // Cache levels untuk efisiensi
        $levels = Level::orderBy('min_total_earnings', 'asc')->get();

        while ($current <= $end) {
            $monthKey = $current->format('Y-m');
            $monthLabel = $current->isoFormat('MMMM YYYY');

            $data = $monthlyData->get($monthKey);

            // 🔧 FIX: Use views table data for ALL months consistently
            // Previous bug: current month was using lifetime totals instead of monthly
            $monthlyEarnings = $data ? (float)$data->earnings : 0;
            $validClicks = $data ? (int)$data->valid_clicks : 0;
            $totalViews = $data ? (int)$data->total_views : 0;

            // Hitung CPM Bulan ini
            $cpm = $totalViews > 0 ? ($monthlyEarnings / $totalViews) * 1000 : 0;

            // Update Cumulative Earnings
            $cumulativeEarnings += $monthlyEarnings;

            // Tentukan Level pada bulan ini berdasarkan Cumulative Earnings
            $currentLevel = $levels->where('min_total_earnings', '<=', $cumulativeEarnings)->last();
            $levelName = $currentLevel ? $currentLevel->name : 'Beginner';
            $levelCpmBonus = $currentLevel ? ($currentLevel->cpm_bonus ?? 0) : 0;

            $result[] = [
                'month' => $monthKey,
                'label' => $monthLabel,
                'valid_clicks' => $validClicks,
                'earnings' => round($monthlyEarnings, 4),
                'average_cpm' => round($cpm, 2),
                'user_level' => $levelName,
                'level_cpm_bonus' => (float) $levelCpmBonus,
            ];

            $current->addMonth();
        }

        return $this->successResponse([
            'range_info' => [
                'from' => $start->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
            ],
            'items' => $result
        ], 'Monthly performance retrieved');
    }

    /**
     * GET /dashboard/analytics
     * 🔧 Fixed: Use user_daily_stats table for accurate chart data
     */
    public function analytics(Request $request)
    {
        $user = $request->user();

        $metric = $request->query('metric'); // 'clicks', 'earnings', 'valid_clicks'
        $groupBy = $request->query('group_by', 'day');

        // Validasi metric
        if (!in_array($metric, ['clicks', 'earnings', 'valid_clicks'])) {
            return $this->errorResponse('Parameter metric wajib diisi: clicks, valid_clicks, atau earnings.', 400);
        }

        $dateData = $this->getDateRange($request);
        $start = $dateData['start'];
        $end = $dateData['end'];
        $timezone = $dateData['timezone'];

        // 🔧 FIX: Query from daily stats aggregate table
        $dailyStats = UserDailyStat::where('user_id', $user->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        // Group by date/week/month
        $groupedStats = $dailyStats->groupBy(function ($item) use ($groupBy, $timezone) {
            $date = Carbon::parse($item->date)->setTimezone($timezone);
            if ($groupBy === 'month') return $date->format('Y-m');
            elseif ($groupBy === 'week') return $date->format('o-W');
            else return $date->format('Y-m-d');
        });

        $points = [];
        $current = $start->copy();
        $totalValue = 0;

        while ($current <= $end) {
            if ($groupBy === 'month') {
                $key = $current->format('Y-m');
                $label = $current->isoFormat('MMM YYYY');
                $nextStep = fn($c) => $c->addMonth();
            } elseif ($groupBy === 'week') {
                $key = $current->format('o-W');
                $label = 'W' . $current->weekOfYear . ' - ' . $current->isoFormat('D MMM');
                $nextStep = fn($c) => $c->addWeek();
            } else {
                $key = $current->format('Y-m-d');
                $label = $current->isoFormat('D MMM');
                $nextStep = fn($c) => $c->addDay();
            }

            $groupItems = $groupedStats->get($key);

            $value = 0;
            if ($groupItems) {
                if ($metric === 'earnings') {
                    $value = (float) $groupItems->sum('earnings');
                } elseif ($metric === 'valid_clicks') {
                    $value = (int) $groupItems->sum('valid_views');
                } else {
                    // clicks = valid views
                    $value = (int) $groupItems->sum('valid_views');
                }
            }

            $points[] = [
                'label' => $label,
                'value' => $metric === 'earnings' ? round($value, 4) : $value,
                'date'  => $current->format('Y-m-d')
            ];

            $totalValue += $value;
            $nextStep($current);
        }

        return $this->successResponse([
            'metric' => $metric,
            'group_by' => $groupBy,
            'range_info' => [
                'from' => $start->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
            ],
            'points' => $points,
            'total' => $metric === 'earnings' ? round($totalValue, 2) : $totalValue
        ], 'Analytics data retrieved');
    }

    // =========================================================================
    // 🔧 HELPER: Date Range Parsing
    // =========================================================================
    private function getDateRange(Request $request)
    {
        $timezone = $request->query('timezone', 'Asia/Jakarta');
        $range = $request->query('range', 'month');
        $now = Carbon::now($timezone);

        switch ($range) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'yesterday':
                $start = $now->copy()->subDay()->startOfDay();
                $end = $now->copy()->subDay()->endOfDay();
                break;
            case 'week':
                $start = $now->copy()->startOfWeek();
                $end = $now->copy()->endOfWeek();
                break;
            case 'year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                break;
            case 'lifetime':
                $start = Carbon::create(2000, 1, 1);
                $end = $now->copy()->endOfDay();
                break;
            case '6months':
                // 5 bulan sebelumnya + bulan ini = 6 bulan total
                $start = $now->copy()->subMonths(5)->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;
            case '12months':
                // 11 bulan sebelumnya + bulan ini = 12 bulan total (trailing 12 months)
                $start = $now->copy()->subMonths(11)->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;
            case 'custom':
                $reqFrom = $request->query('from_date');
                $reqTo = $request->query('to_date');
                if (!$reqFrom || !$reqTo) {
                    $start = $now->copy()->startOfMonth();
                    $end = $now->copy()->endOfMonth();
                } else {
                    $start = Carbon::parse($reqFrom, $timezone)->startOfDay();
                    $end = Carbon::parse($reqTo, $timezone)->endOfDay();
                }
                break;
            case 'month':
            default:
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;
        }

        return ['start' => $start, 'end' => $end, 'range' => $range, 'timezone' => $timezone];
    }
}
