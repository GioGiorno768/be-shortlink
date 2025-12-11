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
use Carbon\Carbon;

class StatsController extends Controller
{
    // Methods dashboard() and index() removed as requested.

    /**
     * 🌍 Endpoint Top Countries
     * GET /analytics/top-countries
     */
    public function topCountries(Request $request)
    {
        $user = $request->user();
        $limit = $request->query('limit', 7);
        
        // Ambil Range Tanggal
        $dateData = $this->getDateRange($request);
        
        // Base Query
        $query = View::whereHas('link', fn($q) => $q->where('user_id', $user->id))
            ->whereBetween('created_at', [$dateData['start']->utc(), $dateData['end']->utc()]);

        // Hitung Total Views di periode ini untuk kalkulasi persentase
        $totalViews = (clone $query)->count();

        if ($totalViews === 0) {
            return $this->successResponse([
                'range' => $dateData['range'],
                'from_date' => $dateData['start']->format('Y-m-d'),
                'to_date' => $dateData['end']->format('Y-m-d'),
                'total_views' => 0,
                'items' => []
            ], 'Top countries retrieved (empty)');
        }

        // Group by Country
        $countries = $query->select('country', DB::raw('count(*) as total'))
            ->groupBy('country')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $items = $countries->map(function ($item) use ($totalViews) {
            $code = $item->country ?: 'Unknown';
            
            // --- REVISI BAGIAN INI ---
            $name = $code;
            // Gunakan fungsi intl HANYA JIKA tersedia
           if ($code !== 'Unknown' && function_exists('locale_get_display_region')) {
                $name = locale_get_display_region('en-' . $code, 'en');
            }
            
            return [
                'country_code' => $code,
                'country_name' => $name ?: ($item->country ?? 'Unknown'),
                'views' => $item->total,
                'percentage' => round(($item->total / $totalViews) * 100, 1),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'range' => $dateData['range'],
                'from_date' => $dateData['start']->format('Y-m-d'),
                'to_date' => $dateData['end']->format('Y-m-d'),
                'total_views' => $totalViews,
                'items' => $items
            ]
        ]);
    }

    /**
     * 🔗 Endpoint Top Referrers
     * GET /analytics/top-referrers
     */
    public function topReferrers(Request $request)
    {
        $user = $request->user();
        $limit = $request->query('limit', 6);

        // Ambil Range Tanggal
        $dateData = $this->getDateRange($request);

        // Base Query
        $query = View::whereHas('link', fn($q) => $q->where('user_id', $user->id))
            ->whereBetween('created_at', [$dateData['start']->utc(), $dateData['end']->utc()]);

        $totalViews = (clone $query)->count();

        if ($totalViews === 0) {
            return $this->successResponse([
                'range' => $dateData['range'],
                'from_date' => $dateData['start']->format('Y-m-d'),
                'to_date' => $dateData['end']->format('Y-m-d'),
                'total_views' => 0,
                'items' => []
            ], 'Top referrers retrieved (empty)');
        }

        // Ambil raw referrer untuk diproses (Grouping SQL bisa kurang akurat karena subdomain)
        // Kita ambil top raw referrers dulu untuk efisiensi, baru di-merge di PHP
        $rawReferrers = $query->select('referer', DB::raw('count(*) as total'))
            ->groupBy('referer')
            ->orderByDesc('total')
            ->limit(100) // Ambil lebih banyak dari limit untuk diproses grouping-nya
            ->get();

        // Proses Grouping Domain (Merge subdomain, misal: m.facebook.com & facebook.com -> Facebook)
        $grouped = collect();
        
        foreach ($rawReferrers as $row) {
            $parsed = $this->parseReferrer($row->referer);
            $key = $parsed['key'];
            
            if (!$grouped->has($key)) {
                $grouped->put($key, [
                    'referrer_key' => $key,
                    'referrer_label' => $parsed['label'],
                    'views' => 0
                ]);
            }
            
            $data = $grouped->get($key);
            $data['views'] += $row->total;
            $grouped->put($key, $data);
        }

        // Sort dan Limit Hasil Akhir
        $items = $grouped->sortByDesc('views')->take($limit)->values()->map(function ($item) use ($totalViews) {
            $item['percentage'] = round(($item['views'] / $totalViews) * 100, 1);
            return $item;
        });

        return response()->json([
            'success' => true,
            'data' => [
                'range' => $dateData['range'],
                'from_date' => $dateData['start']->format('Y-m-d'),
                'to_date' => $dateData['end']->format('Y-m-d'),
                'total_views' => $totalViews,
                'items' => $items
            ]
        ]);
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
     */
    public function getEarnings(Request $request)
    {
        $user = $request->user();
        $dateData = $this->getDateRange($request);

        if ($dateData['range'] === 'lifetime') {
            $totalEarnings = $user->total_earnings; // 🔥 Optimized
        } else {
            $totalEarnings = View::whereHas('link', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->whereBetween('created_at', [$dateData['start']->utc(), $dateData['end']->utc()])
            ->sum('earned');
        }

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
     */
    public function getClicks(Request $request)
    {
        $user = $request->user();
        $dateData = $this->getDateRange($request);

        // Note: getClicks returns VALID CLICKS based on the query filter 'is_valid' => true
        if ($dateData['range'] === 'lifetime') {
            $totalClicks = $user->total_valid_views; // 🔥 Optimized
        } else {
            $totalClicks = View::whereHas('link', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->where('is_valid', true) // Filter Valid Clicks
            ->whereBetween('created_at', [$dateData['start']->utc(), $dateData['end']->utc()])
            ->count();
        }

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
     */
    public function getReferralStats(Request $request)
    {
        $user = $request->user();
        $dateData = $this->getDateRange($request);

        if ($dateData['range'] === 'lifetime') {
            $referralCount = $user->total_referrals; // 🔥 Optimized
        } else {
            $referralCount = User::where('referred_by', $user->id)
                ->whereBetween('created_at', [$dateData['start']->utc(), $dateData['end']->utc()])
                ->count();
        }

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
     * Rumus: (Total Earnings / Total Views) * 1000
     */
    public function getAverageCpm(Request $request)
    {
        $user = $request->user();
        $dateData = $this->getDateRange($request);

        $viewsQuery = View::whereHas('link', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->whereBetween('created_at', [$dateData['start']->utc(), $dateData['end']->utc()]);

        $totalEarnings = (clone $viewsQuery)->sum('earned');
        $totalViews = (clone $viewsQuery)->count(); // Gunakan raw views untuk CPM biasanya, atau valid views tergantung kebijakan. Default raw.

        $cpm = $totalViews > 0 ? ($totalEarnings / $totalViews) * 1000 : 0;

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
     */
    public function monthlyPerformance(Request $request)
    {
        $user = $request->user();
        $timezone = $request->query('timezone', 'Asia/Jakarta');
        
        // Default range: 12 bulan terakhir jika tidak ada filter spesifik
        // Kita gunakan logic getDateRange tapi kita butuh start date yang pasti untuk loop
        $dateData = $this->getDateRange($request);
        
        // Jika range 'lifetime', kita batasi misal 2 tahun terakhir agar tidak terlalu berat, 
        // atau biarkan semua jika user minta. Mari kita ikuti dateData.
        $start = $dateData['start'];
        $end = $dateData['end'];

        // 1. Hitung Initial Cumulative Earnings (Pendapatan sebelum periode start)
        // Ini penting untuk menentukan Level User di awal periode
        $initialEarnings = View::whereHas('link', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->where('created_at', '<', $start->copy()->utc())
            ->sum('earned');

        // 2. Ambil Data Views Group by Month
        // Kita perlu raw query untuk grouping bulan yang efisien
        // Format bulan: YYYY-MM
        
        // Perhatikan Timezone saat grouping!
        // Di MySQL: DATE_FORMAT(CONVERT_TZ(created_at, '+00:00', '+07:00'), '%Y-%m')
        // Kita perlu offset timezone, misal '+07:00'
        $tzOffset = Carbon::now($timezone)->format('P'); // e.g., +07:00

        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            // SQLite implementation for testing
            $monthExpression = "strftime('%Y-%m', created_at)";
        } else {
            // MySQL implementation
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

        // 3. Loop dari Start sampai End per Bulan untuk mengisi data (termasuk bulan kosong)
        $result = [];
        $current = $start->copy()->startOfMonth();
        $cumulativeEarnings = $initialEarnings;
        
        // Cache levels untuk efisiensi
        $levels = Level::orderBy('min_total_earnings', 'asc')->get();

        while ($current <= $end) {
            $monthKey = $current->format('Y-m');
            $monthLabel = $current->isoFormat('MMMM YYYY');
            
            $data = $monthlyData->get($monthKey);
            
            $monthlyEarnings = $data ? (float)$data->earnings : 0;
            $validClicks = $data ? (int)$data->valid_clicks : 0;
            $totalViews = $data ? (int)$data->total_views : 0;
            
            // Hitung CPM Bulan ini
            // CPM = (Earnings / Total Views) * 1000
            $cpm = $totalViews > 0 ? ($monthlyEarnings / $totalViews) * 1000 : 0;

            // Update Cumulative Earnings (Pendapatan sampai akhir bulan ini)
            $cumulativeEarnings += $monthlyEarnings;

            // Tentukan Level pada bulan ini berdasarkan Cumulative Earnings
            $currentLevel = $levels->where('min_total_earnings', '<=', $cumulativeEarnings)->last();
            $levelName = $currentLevel ? $currentLevel->name : 'Basic'; // Default Basic jika tidak ada

            $result[] = [
                'month' => $monthKey,
                'label' => $monthLabel,
                'valid_clicks' => $validClicks,
                'earnings' => round($monthlyEarnings, 4),
                'average_cpm' => round($cpm, 2),
                'user_level' => $levelName,
                // 'cumulative_earnings_debug' => $cumulativeEarnings // Uncomment for debug
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
     */
    public function analytics(Request $request)
    {
        $user = $request->user();
        
        $metric = $request->query('metric'); // 'clicks', 'earnings', 'valid_clicks'
        $groupBy = $request->query('group_by', 'day'); 

        // Validasi metric (tambah valid_clicks)
        if (!in_array($metric, ['clicks', 'earnings', 'valid_clicks'])) {
            return $this->errorResponse('Parameter metric wajib diisi: clicks, valid_clicks, atau earnings.', 400);
        }

        $dateData = $this->getDateRange($request); 
        $start = $dateData['start'];
        $end = $dateData['end'];
        $timezone = $dateData['timezone'];

        $query = View::whereHas('link', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })
        ->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()]);

        // Filter khusus jika metric adalah valid_clicks
        if ($metric === 'valid_clicks') {
            $query->where('is_valid', true);
        }

        $views = $query->get();

        $groupedViews = $views->groupBy(function ($item) use ($groupBy, $timezone) {
            $date = Carbon::parse($item->created_at)->setTimezone($timezone);
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

            $groupItems = $groupedViews->get($key);
            
            $value = 0;
            if ($groupItems) {
                if ($metric === 'earnings') {
                    $value = (float) $groupItems->sum('earned');
                } else {
                    // clicks atau valid_clicks (sudah difilter di query)
                    $value = $groupItems->count();
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
