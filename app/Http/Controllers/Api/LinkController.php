<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use App\Models\Link;
use App\Models\User;
use App\Models\View;
use Stevebauman\Location\Facades\Location;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\RateLimiter;


class LinkController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Link::withCount(['views as total_views'])
            ->where('user_id', $user->id);

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    // ->orWhere('alias', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filter top links (by total views)
        if ($request->input('filter') === 'top_links') {
            $query->orderByDesc('total_views');
        }

        // Filter berdasarkan penghasilan (earned)
        if ($request->input('filter') === 'top_earned') {
            $query->withSum('views as total_earned', 'earned')
                ->orderByDesc('total_earned');
        }

        // Pagination
        $perPage = $request->input('per_page', 10);
        $links = $query->latest()->paginate($perPage);

        return response()->json($links);
    }


    // ==============================
    // 1️⃣ STORE — Buat Shortlink & Simpan di Redis
    // ==============================
    public function store(Request $request)
    {
        $validated = $request->validate([
            'original_url' => 'required|url',
            'title' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'expired_at' => 'nullable|date|after_or_equal:today', // format: YYYY-MM-DD atau YYYY-MM-DD HH:MM:SS
            'alias' => 'nullable|string|max:20|alpha_dash|unique:links,code',
            'ad_level' => 'nullable|integer|min:1|max:5',
        ]);

        $ip = $request->ip() === '127.0.0.1' ? '36.84.69.10' : $request->ip();

        $user = null;
        $token = $request->bearerToken(); // ambil token dari header
        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $user = $accessToken->tokenable; // user valid
            }
        }
        $userId = $user ? $user->id : null;

        $tries = 0;
        $maxTries = 5;
        $link = null;

        $adLevel = $validated['ad_level'] ?? 1;
        $earnRates = [
            1 => 0.05,
            2 => 0.07,
            3 => 0.10,
            4 => 0.15,
            5 => 0.20,
        ];
        $earnPerClick = $earnRates[$adLevel] ?? 0.05;

        do {
            $code = $validated['alias'] ?? Str::random(7);
            $adLevel = $validated['ad_level'] ?? 1;

            $earnPerClick = $user
                ? round(0.10 * $adLevel, 2) // naik 0.10 per level
                : 0.00;

            try {
                $link = Link::create([
                    'user_id' => $userId,
                    'creator_ip' => $ip,
                    'original_url' => $validated['original_url'],
                    'code' => $code,
                    'title' => $validated['title'] ?? null,
                    'expired_at' => $validated['expired_at'] ?? null,
                    'password' => $validated['password'] ?? null,
                    'earn_per_click' => $earnPerClick,
                    'status' => 'active',
                ]);
                $created = true;
            } catch (QueryException $e) {
                // 23000 = duplicate entry
                if ($e->getCode() === '23000') {
                    $created = false;
                    $tries++;
                } else {
                    throw $e;
                }
            }
        } while (!$created && $tries < $maxTries);

        if (!$link) {
            return response()->json([
                'error' => 'Gagal membuat short link setelah beberapa percobaan.',
            ], 500);
        }

        // Simpan sementara di Redis (10 menit)
        Cache::put("link:{$link->code}", [
            'original_url' => $link->original_url,
            'user_id' => $link->user_id,
            'password' => $link->password,
            'expired_at' => $link->expired_at,
            'earn_per_click' => $link->earn_per_click,
        ], now()->addMinutes(10));

        // Jika user login, bisa menambahkan logika referral bonus
        if ($user && $user->referred_by) {
            // Contoh: bonus kecil untuk referral aktif
            $referrer = User::where('referral_code', $user->referred_by)->first();
            if ($referrer) {
                $referrer->increment('balance', 0.01);
            }
        }

        return response()->json([
            'short_url' => url("/{$link->code}"),
            'code' => $link->code,
            'title' => $link->title,
            'expired_at' => $link->expired_at,
            'user_id' => $link->user_id,
            'is_guest' => !$user,
            'earn_per_click' => (float) $link->earn_per_click,
            'source' => 'database',
            'message' => $user
                ? 'Shortlink created successfully (eligible for earnings).'
                : 'Shortlink created as guest (no earnings, stored temporarily).',
        ], 201);
    }

    public function checkAlias($alias)
    {
        // Normalisasi alias agar konsisten (huruf kecil, tanpa spasi)
        $alias = strtolower(trim($alias));

        // Simpan cache hasil pengecekan selama 10 detik
        $exists = Cache::remember("alias_check:{$alias}", 10, function () use ($alias) {
            return Link::where('code', $alias)->exists();
        });

        return response()->json(['exists' => $exists]);
    }



    // ==============================
    // 2️⃣ SHOW — Generate Token & Simpan ke Redis
    // ==============================
    public function show($code, Request $request)
    {
        try {
            // 🧱 1️⃣ Ambil data dari cache Redis (jika tersedia)
            $cachedLink = Cache::get("link:{$code}");

            if (!$cachedLink) {
                $link = Link::where('code', $code)->firstOrFail();

                // 🔒 2️⃣ Cek apakah link sudah kedaluwarsa
                if ($link->expired_at && now()->greaterThan($link->expired_at)) {
                    return response()->json([
                        'error' => 'This link has expired.'
                    ], 410);
                }

                // 📦 Simpan ke Redis untuk akses cepat
                $cachedLink = [
                    'id' => $link->id,
                    'original_url' => $link->original_url,
                    'user_id' => $link->user_id,
                    'password' => $link->password,
                    'earn_per_click' => $link->earn_per_click,
                ];

                Cache::put("link:{$code}", $cachedLink, now()->addMinutes(10));
            }

            // 🧩 3️⃣ Buat token unik & simpan berdasarkan IP dan User-Agent
            $token = Str::uuid()->toString();
            $ip = $request->ip();
            $userAgent = $request->header('User-Agent');
            $tokenKey = "token:{$code}:" . md5("{$ip}-{$userAgent}");

            Cache::put($tokenKey, [
                'token' => $token,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'created_at' => now()
            ], now()->addSeconds(120));

            // 🛡️ 4️⃣ Rate limiting (maks 3 request per menit per IP)
            $rateKey = "rate:{$ip}:{$code}";
            if (RateLimiter::tooManyAttempts($rateKey, 3)) {
                return response()->json([
                    'error' => 'Too many requests. Please wait a moment.'
                ], 429);
            }
            RateLimiter::hit($rateKey, 60); // 1 menit

            // 📈 5️⃣ Catat tampilan awal (pre-view) ringan ke Redis
            Cache::increment("preview:{$code}:count");

            // 💬 6️⃣ Kirim response ke frontend
            return response()->json([
                'ads' => [
                    'https://ads1.example.com',
                    'https://ads2.example.com',
                ],
                'token' => $token,
                'wait_time' => 10,
                'password' => $cachedLink['password'],
                'message' => 'Please wait 10 seconds before continuing.'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // 🚫 7️⃣ Jika link tidak ditemukan
            return response()->json([
                'error' => 'Shortlink not found.'
            ], 404);
        } catch (\Exception $e) {
            // ⚠️ 8️⃣ Fallback error umum
            Log::error('Error in show(): ' . $e->getMessage(), [
                'code' => $code,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'error' => 'Internal server error.'
            ], 500);
        }
    }


    // ==============================
    // 3️⃣ CONTINUE — Validasi Token dari Redis
    // ==============================
    public function continue($code, Request $request)
    {
        // === 1️⃣ Rate Limiting (anti spam / bot)
        $ip = $request->ip() === '127.0.0.1' ? '36.84.69.10' : $request->ip();
        if (RateLimiter::tooManyAttempts("continue:{$ip}", 3)) {
            return response()->json(['error' => 'Too many attempts. Try again later.'], 429);
        }
        RateLimiter::hit("continue:{$ip}", 60);

        // === 2️⃣ Ambil token & link dari cache / DB
        $cachedToken = Cache::get("token:{$code}");
        $cachedLink = Cache::get("link:{$code}");

        $link = $cachedLink
            ? (object) array_merge(['id' => null], $cachedLink)
            : Link::where('code', $code)->firstOrFail();

        // === 3️⃣ Validasi Password (jika ada)
        if (!empty($link->password)) {
            $inputPassword = $request->input('password');
            if (!$inputPassword) {
                return response()->json([
                    'requires_password' => true,
                    'message' => 'This link is protected by a password.',
                ], 401);
            }

            if ($inputPassword !== $link->password) {
                return response()->json(['error' => 'Incorrect password.'], 403);
            }
        }

        // === 4️⃣ Validasi Token
        if (!$cachedToken || $cachedToken['token'] !== $request->token) {
            $this->logView($link, $ip, $request, false, 0, 'Invalid token');
            return response()->json(['error' => 'Invalid or missing token.'], 403);
        }

        // Pastikan token sesuai IP & UA
        $userAgent = $request->header('User-Agent');
        if (($cachedToken['ip'] ?? null) !== $ip || ($cachedToken['user_agent'] ?? null) !== $userAgent) {
            $this->logView($link, $ip, $request, false, 0, 'Token mismatch');
            return response()->json(['error' => 'Token mismatch with client.'], 403);
        }

        // === 5️⃣ Cek Token Expired
        if (Carbon::parse($cachedToken['created_at'])->diffInSeconds(now()) > 180) {
            Cache::forget("token:{$code}");
            return response()->json(['error' => 'Token expired. Please reload the page.'], 403);
        }

        // === 6️⃣ Ambil lokasi (optional, dengan fallback)
        try {
            $position = Location::get($ip);
            $country = $position->countryName ?? 'Unknown';
        } catch (\Exception $e) {
            Log::warning("Location lookup failed", ['ip' => $ip]);
            $country = 'Unknown';
        }

        // === 7️⃣ Cek View Unik
        $existing = View::where('link_id', $link->id ?? null)
            ->where('ip_address', $ip)
            ->whereDate('created_at', now()->toDateString())
            ->first();

        $isUnique = !$existing;
        $isOwnedByUser = !is_null($link->user_id ?? null);
        $earn = ($isUnique && $isOwnedByUser) ? ($link->earn_per_click ?? 1) : 0;
        $isSelfClick = true; // ganti false jika aktifkan commnet di bawah

        // // Cek jika user login & dia pemilik link
        // if (auth()->check() && $isOwnedByUser && auth()->id() === $link->user_id) {
        //     $isSelfClick = true;
        // }

        // // Tambahan: cek jika IP klik sama dengan IP pembuat link
        // if (!$isSelfClick && !empty($link->creator_ip) && $link->creator_ip === $ip) {
        //     $isSelfClick = true;
        // }

        // === 8️⃣ Log View
        $this->logView($link, $ip, $request, true, $earn);

        // === jalanakan php artisan queue:work untuk menjalankan proses yang di simpan di anrian atau queue
        // === 9️⃣ Update Saldo User & Pendapatan Link (asynchronous)
        // === jika ingin lebih aman dan cepat
        // if ($isUnique && $isOwnedByUser && $earn > 0) {
        //     dispatch(function () use ($link, $earn) {
        //         $user = $link->user ?? \App\Models\User::find($link->user_id);
        //         if ($user)
        //             $user->increment('balance', $earn);

        //         $linkModel = \App\Models\Link::find($link->id);
        //         if ($linkModel)
        //             $linkModel->increment('total_earned', $earn);
        //     })->afterResponse(); // tidak menghambat request utama
        // }

        // --- Tambah saldo user (hanya jika bukan self-click)
        if ($isUnique && $isOwnedByUser && !$isSelfClick) {
            $user = isset($link->user)
                ? $link->user
                : User::find($link->user_id);

            if ($user) {
                $user->increment('balance', $earn);
            }

            if (isset($link->id)) {
                $linkModel = Link::find($link->id);
                if ($linkModel) {
                    $linkModel->increment('total_earned', $earn);
                }
            }
        }

        // === 🔟 Hapus token (one-time use)
        Cache::forget("token:{$code}");

        return response()->json([
            'original_url' => $link->original_url,
            'ads' => [
                'https://ads1.example.com',
                'https://ads2.example.com',
            ],
            'is_guest_link' => !$isOwnedByUser,
            'message' => $isOwnedByUser
                ? 'Valid view recorded, earnings updated.'
                : 'Guest link viewed (no earnings).',
        ]);
    }

    // === Helper: Log View (menghindari duplikasi kode)
    private function logView($link, $ip, $request, $isValid = false, $earned = 0, $note = null)
    {
        $userAgent = $request->header('User-Agent');
        $referer = $request->headers->get('referer');
        $country = $note ? 'Unknown' : ($request->input('country') ?? 'Unknown');

        try {
            View::create([
                'link_id' => $link->id ?? null,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'referer' => $referer,
                'country' => $country,
                'device' => $this->detectDevice($userAgent),
                'browser' => $this->detectBrowser($userAgent),
                'is_unique' => $isValid,
                'is_valid' => $isValid,
                'earned' => $earned,
                'note' => $note,
            ]);
        } catch (\Throwable $e) {
            Log::error("Failed to log view", ['error' => $e->getMessage()]);
        }
    }

    // ==============================
    // 4 STATS - Validasi fungsi statistk
    // ==============================
    public function stats($code)
    {
        $cacheKey = "link_stats:{$code}:user:" . auth()->id();

        // 1️⃣ Coba ambil dari cache Redis dulu
        if (Cache::has($cacheKey)) {
            return response()->json([
                'source' => 'redis',
                'data' => Cache::get($cacheKey),
            ]);
        }

        // 2️⃣ Kalau belum ada, ambil dari DB
        $link = Link::where('code', $code)
            ->where('user_id', auth()->id())
            ->with('views')
            ->firstOrFail();

        if (auth()->id() !== $link->user_id) {
            return response()->json(['error' => 'Unauthorized.'], 403);
        }

        $data = [
            'total_views' => $link->views->count(),
            'unique_views' => $link->views->where('is_unique', true)->count(),
            'earned_total' => $link->views->sum('earned'),
            'updated_at' => now()->toDateTimeString(),
        ];

        // 3️⃣ Simpan hasilnya ke Redis selama 2 menit
        Cache::put($cacheKey, $data, now()->addMinutes(2));

        // 4️⃣ Kembalikan response ke frontend
        if (Cache::has($cacheKey)) {
            return response()->json([
                'source' => 'redis',
                'data' => [
                    'summary' => Cache::get($cacheKey),
                    'meta' => [
                        'link_code' => $code,
                        'last_updated' => now()->toDateTimeString(),
                    ]
                ]
            ]);
        }


    }
    // ==============================
// PUT - Update Link
// ==============================
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $link = Link::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // Validasi lengkap semua field yang boleh diupdate
        $validated = $request->validate([
            'original_url' => 'nullable|url|max:2048',
            'title' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'expired_at' => 'nullable|date',
            'alias' => 'nullable|string|max:100|unique:links,code,' . $link->id,
            'ad_level' => 'nullable|integer|min:1|max:5',
        ]);

        // Tarif per level (bisa disesuaikan)
        $earnRates = [
            1 => 0.05,
            2 => 0.07,
            3 => 0.10,
            4 => 0.15,
            5 => 0.20,
        ];

        // Jika user ubah level iklan, perbarui earn_per_click juga
        $adLevel = $validated['ad_level'] ?? $link->ad_level;
        $validated['earn_per_click'] = $earnRates[$adLevel] ?? $link->earn_per_click;

        // Update semua field
        $link->update($validated);

        return response()->json([
            'message' => 'Link updated successfully',
            'link' => $link,
        ]);
    }

    // ==============================
    //   Update status Link
    // ==============================
    public function toggleStatus(Request $request, $id)
    {
        $user = $request->user();
        $link = Link::where('id', $id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $link->status = $link->status === 'active' ? 'disabled' : 'active';
        $link->save();

        return response()->json([
            'message' => 'Status link diperbarui',
            'status' => $link->status,
        ]);
    }



    // ==============================
    // 🔍 Helper: Deteksi Device & Browser
    // ==============================
    private function detectDevice($userAgent)
    {
        if (preg_match('/mobile/i', $userAgent))
            return 'Mobile';
        if (preg_match('/tablet/i', $userAgent))
            return 'Tablet';
        return 'Desktop';
    }

    private function detectBrowser($userAgent)
    {
        if (preg_match('/chrome/i', $userAgent))
            return 'Chrome';
        if (preg_match('/firefox/i', $userAgent))
            return 'Firefox';
        if (preg_match('/safari/i', $userAgent))
            return 'Safari';
        if (preg_match('/edge/i', $userAgent))
            return 'Edge';
        if (preg_match('/msie|trident/i', $userAgent))
            return 'Internet Explorer';
        return 'Other';
    }
}
