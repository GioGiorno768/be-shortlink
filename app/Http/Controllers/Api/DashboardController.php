<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Link;
use App\Models\View;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function overview(Request $request)
    {
        $user = $request->user();
        $period = $request->query('period', 'weekly');
        $linkCode = $request->query('link', null);

        $startDate = match ($period) {
            'daily' => Carbon::now()->startOfDay(),
            'monthly' => Carbon::now()->startOfMonth(),
            default => Carbon::now()->subWeek(),
        };

        $cacheKey = "dashboard:overview:user:{$user->id}:{$period}:" . ($linkCode ?? 'all');
        if (Cache::has($cacheKey)) {
            return $this->successResponse(Cache::get($cacheKey), 'Dashboard overview retrieved (cached)');
        }

        $balance = $user->balance ?? 0;
        $payout = Payout::where('user_id', $user->id)->where('status', 'paid')->sum('amount');

        // Calculate Average CPM: (total_earnings / total_views) * 1000
        $totalViews = $user->total_views ?? 0;
        $totalEarnings = $user->total_earnings ?? 0;
        $avgCpm = $totalViews > 0 ? round(($totalEarnings / $totalViews) * 1000, 2) : 0;

        // ====================================
        // 🔹 TOP LINKS SECTION (Top 10 by Earnings)
        // ====================================
        $topLinks = Link::where('user_id', $user->id)
            ->orderByDesc('total_earned') // 🔥 Sort by Earnings (highest first)
            ->limit(10) // 🔥 Limit 10
            ->get()
            ->map(function ($link) {
                $views = $link->views ?? 0;
                $validViews = $link->valid_views ?? 0;
                $earnings = $link->total_earned ?? 0;
                $cpm = $views > 0 ? round(($earnings / $views) * 1000, 2) : 0;

                return [
                    'id' => $link->id,
                    'title' => $link->title, // Include title (nullable)
                    'short_url' => url("/links/{$link->code}"),
                    'original_url' => $link->original_url,
                    'created_at' => $link->created_at->format('d M Y, h:i A'),
                    'views' => (int) $views,
                    'valid_views' => (int) $validViews,
                    'earnings' => round($earnings, 5),
                    'cpm' => $cpm,
                ];
            });

        // ====================================
        //  REFERRAL SECTION
        // ====================================
        if (!$user->referral_code) {
            $user->referral_code = Str::random(8);
            $user->save();
        }

        $referralData = [
            'code' => $user->referral_code,
            'users' => User::where('referred_by', $user->id)->count(), // Count directly from DB
            'referral_links' => [
                [
                    'platform' => 'whatsapp',
                    'url' => "https://wa.me/?text=Join+using+{$user->referral_code}"
                ],
                [
                    'platform' => 'facebook',
                    'url' => "https://facebook.com/share?code={$user->referral_code}"
                ],
                [
                    'platform' => 'instagram',
                    'url' => "https://instagram.com/share?code={$user->referral_code}"
                ],
                [
                    'platform' => 'telegram',
                    'url' => "https://t.me/share/url?url=" . urlencode("https://shortenlinks.com/ref/{$user->referral_code}")
                ]
            ]
        ];

        // ====================================
        // 🔹 FINAL STRUCTURED RESPONSE
        // ====================================
        $data = [
            'summary' => [
                'balance' => (float) $balance,
                'payout' => (float) $payout,
                'cpm' => (float) $avgCpm,
                'total_earnings' => (float) ($user->total_earnings ?? 0),
            ],
            'top_links' => $topLinks,
            'referral' => $referralData,
        ];

        Cache::put($cacheKey, $data, now()->addMinutes(3));

        return $this->successResponse($data, 'Dashboard overview retrieved');
    }


    // 🔹 NEW ENDPOINT: statistik tren (harian/mingguan)
    public function trends(Request $request)
    {
        $user = $request->user();
        $period = $request->query('period', 'weekly');
        $linkCode = $request->query('link', null);

        $startDate = match ($period) {
            'daily' => Carbon::now()->startOfDay(),
            'monthly' => Carbon::now()->startOfMonth(),
            default => Carbon::now()->subWeek(),
        };

        $cacheKey = "dashboard:trends:user:{$user->id}:{$period}:" . ($linkCode ?? 'all');

        if (Cache::has($cacheKey)) {
            return $this->successResponse(Cache::get($cacheKey), 'Trends data retrieved (cached)');
        }

        // 🔹 Ambil views berdasarkan periode
        $views = View::whereHas('link', function ($q) use ($user, $linkCode) {
            $q->where('user_id', $user->id);
            if ($linkCode)
                $q->where('code', $linkCode);
        })
            ->where('created_at', '>=', $startDate)
            ->get()
            ->groupBy(fn($v) => $v->created_at->format('Y-m-d'));

        // 🔹 Format data per hari
        $trendData = $views->map(function ($items, $date) {
            return [
                'date' => $date,
                'label' => Carbon::parse($date)->format('d M'), // Added label for Chart XAxis
                'earnings' => round($items->sum('earned'), 5),
                'clicks' => $items->count(),
                'valid_clicks' => $items->where('is_valid', true)->count(),
            ];
        })->values();

        // Pastikan urutan berdasarkan tanggal
        $trendData = $trendData->sortBy('date')->values();

        $data = [
            'period' => $period,
            'link' => $linkCode,
            'trends' => $trendData,
        ];

        Cache::put($cacheKey, $data, now()->addMinutes(3));

        return $this->successResponse($data, 'Trends data retrieved');
    }
}
