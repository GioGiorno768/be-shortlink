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
use App\Models\Setting;
use Stevebauman\Location\Facades\Location;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\DB;


class LinkController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Base Query dengan Aggregates + CPM Calculation
        $query = Link::where('user_id', $user->id)
            ->selectRaw('links.*, (links.earn_per_click * 1000) as calculated_cpm')
            ->withCount(['views as total_views'])
            ->withCount(['views as valid_views' => function ($q) {
                $q->where('is_valid', true);
            }])
            ->withSum('views as total_earned', 'earned');

        // 1. Search (Title, Alias/Code, Original URL)
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('original_url', 'like', "%{$search}%");
            });
        }

        // 2. Filter Status (Active/Disabled)
        if ($status = $request->input('status')) {
            if ($status === 'active') {
                $query->where('status', 'active');
            } elseif ($status === 'disabled') {
                $query->where('status', 'disabled');
            }
        }

        // 3. Filter Expired Date
        if ($expiredDate = $request->input('expired_date')) {
            // Filter berdasarkan tanggal spesifik (Y-m-d)
            $query->whereDate('expired_at', $expiredDate);
        }

        // 4. Sorting / Filtering Khusus
        $filter = $request->input('filter');
        switch ($filter) {
            case 'top_links': // Terbanyak diklik (Total Views)
                $query->orderByDesc('total_views');
                break;
            case 'top_valid': // Terbanyak valid click
                $query->orderByDesc('valid_views');
                break;
            case 'top_earned': // Terbanyak penghasilan
                $query->orderByDesc('total_earned');
                break;
            case 'avg_cpm': // Sort by Average CPM (calculated)
                // CPM already calculated in base query, just order by it
                $query->orderByDesc('calculated_cpm');
                break;
            case 'link_password': // Filter links with password
                $query->whereNotNull('password')
                    ->where('password', '!=', '');
                break;
            case 'expired': // Yang sudah expired
                $query->whereNotNull('expired_at')->where('expired_at', '<', now());
                break;
            default:
                $query->latest(); // Default: Terbaru
                break;
        }

        // Pagination
        $perPage = $request->input('per_page', 10);
        $links = $query->paginate($perPage);

        return $this->paginatedResponse($links, 'Links retrieved successfully');
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
            'alias' => 'nullable|string|min:4|max:15|alpha_dash|unique:links,code',
            'ad_level' => 'nullable|integer|min:1|max:4',
        ]);

        $ip = $request->ip();
        if (app()->environment('local') && $ip === '127.0.0.1') {
            $ip = '36.84.69.10';
        }

        $user = null;
        $token = $request->bearerToken(); // ambil token dari header

        // Check if forced guest mode
        $isForcedGuest = $request->boolean('is_guest');

        if ($token && !$isForcedGuest) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken) {
                $user = $accessToken->tokenable; // user valid
            }
        }
        $userId = $user ? $user->id : null;

        // 🔥 GUEST RATE LIMITER: 3 links per 3 days 🔥
        if (!$user) {
            $rateKey = 'guest_link_creation:' . $ip;
            if (RateLimiter::tooManyAttempts($rateKey, 100)) {
                $seconds = RateLimiter::availableIn($rateKey);
                if (RateLimiter::tooManyAttempts($rateKey, 100)) {
                    $seconds = RateLimiter::availableIn($rateKey);
                    return $this->errorResponse('Guest limit reached (100 links/3 days). Please register to create more.', 429, ['retry_after' => $seconds]);
                }
            }
            RateLimiter::hit($rateKey, 259200); // 3 days = 259200 seconds
        }

        $tries = 0;
        $maxTries = 4;
        $link = null;

        // 1. Ambil rates dari Cache (simpan selamanya sampai admin mengupdate)
        $earnRates = Cache::rememberForever('app_ad_cpc_rates', function () {
            $setting = Setting::where('key', 'ad_cpc_rates')->first();
            // Fallback ke default jika database kosong
            return $setting ? $setting->value : [
                1 => 0.05,
                2 => 0.07,
                3 => 0.10,
                4 => 0.15,
                // 5 => 0.20
            ];
        });

        $adLevel = $validated['ad_level'] ?? 1;
        $earnPerClick = $earnRates[$adLevel] ?? ($earnRates[1] ?? 0.05);

        do {
            $code = $validated['alias'] ?? Str::random(7);
            // $adLevel = $validated['ad_level'] ?? 1; // Redundant

            // $earnPerClick = $user
            //     ? round(0.10 * $adLevel, 2) // naik 0.10 per level
            //     : 0.00;

            try {
                $link = Link::create([
                    'user_id' => $userId,
                    'creator_ip' => $ip,
                    'original_url' => $validated['original_url'],
                    'code' => $code,
                    'title' => $validated['title'] ?? null,
                    'expired_at' => $validated['expired_at'] ?? null,
                    'password' => $validated['password'] ?? null,
                    'ad_level' => $adLevel, // ✅ Fix: Save ad_level
                    'earn_per_click' => $earnPerClick, // ✅ Fix: Use correct rate from cache
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
            return $this->errorResponse('Gagal membuat short link setelah beberapa percobaan.', 500);
        }

        // Simpan sementara di Redis (10 menit)
        Cache::put("link:{$link->code}", [
            'id' => $link->id, // ✅ Fix: Save ID to cache
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

        return $this->successResponse([
            'short_url' => url("/{$link->code}"),
            'code' => $link->code,
            'title' => $link->title,
            'expired_at' => $link->expired_at,
            'user_id' => $link->user_id,
            'is_guest' => !$user,
            'earn_per_click' => (float) $link->earn_per_click,
            'source' => 'database',
        ], $user ? 'Shortlink created successfully (eligible for earnings).' : 'Shortlink created as guest (no earnings, stored temporarily).', 201);
    }

    public function checkAlias($alias)
    {
        // Normalisasi alias agar konsisten (huruf kecil, tanpa spasi)
        $alias = strtolower(trim($alias));

        // Simpan cache hasil pengecekan selama 10 detik
        $exists = Cache::remember("alias_check:{$alias}", 10, function () use ($alias) {
            return Link::where('code', $alias)->exists();
        });

        return $this->successResponse(['exists' => $exists], 'Alias check completed');
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

                // 📦 Simpan ke Redis untuk akses cepat
                $cachedLink = [
                    'id' => $link->id,
                    'original_url' => $link->original_url,
                    'user_id' => $link->user_id,
                    'password' => $link->password,
                    'earn_per_click' => $link->earn_per_click,
                    'expired_at' => $link->expired_at,
                    'is_banned' => $link->is_banned,
                    'ban_reason' => $link->ban_reason,
                    'status' => $link->status,
                ];

                Cache::put("link:{$code}", $cachedLink, now()->addMinutes(10));
            }

            // ✅ PRIORITY 1: Check STATUS first (Disabled acts like expired)
            if (isset($cachedLink['status']) && $cachedLink['status'] !== 'active') {
                $frontendUrl = "http://localhost:3000/expired";
                Log::info("Redirecting to expired page (DISABLED) for code: {$code}");
                return redirect("{$frontendUrl}");
            }

            // ✅ PRIORITY 2: Check EXPIRED
            $expiredAt = isset($cachedLink['expired_at']) ? \Carbon\Carbon::parse($cachedLink['expired_at']) : null;
            if ($expiredAt && now()->greaterThan($expiredAt)) {
                $frontendUrl = "http://localhost:3000/expired";
                Log::info("Redirecting to expired page (EXPIRED DATE) for code: {$code}");
                return redirect("{$frontendUrl}");
            }

            // ✅ PRIORITY 3: Check BANNED
            if (isset($cachedLink['is_banned']) && $cachedLink['is_banned']) {
                return $this->errorResponse('This link has been banned by administrator.', 403, ['reason' => $cachedLink['ban_reason'] ?? '']);
            }

            // 🧩 3️⃣ Buat token unik & simpan berdasarkan IP dan User-Agent
            $token = Str::uuid()->toString();
            // aktifkan jika masuk production
            // $ip = $request->ip();
            $ip = $request->ip();
            if (app()->environment('local') && $ip === '127.0.0.1') {
                $ip = '36.84.69.10';
            }
            $userAgent = $request->header('User-Agent');
            $tokenKey = "token:{$code}:" . md5("{$ip}-{$userAgent}");

            Cache::put($tokenKey, [
                'token' => $token,
                'ip' => $ip,
                'user_agent' => $userAgent,
                'status' => 'pending', // Token belum aktif, perlu aktivasi dulu
                'min_wait_seconds' => 10, // User harus nunggu minimal 10 detik
                'created_at' => now(),
                'activated_at' => null
            ], now()->addSeconds(120));

            // 🛡️ 4️⃣ Rate limiting (maks 3 request per menit per IP)
            $rateKey = "rate:{$ip}:{$code}";
            if (RateLimiter::tooManyAttempts($rateKey, 3)) {
                return $this->errorResponse('Too many requests. Please wait a moment.', 429);
            }
            RateLimiter::hit($rateKey, 60); // 1 menit

            // 📈 5️⃣ Catat tampilan awal (pre-view) ringan ke Redis
            Cache::increment("preview:{$code}:count");

            // 💬 6️⃣ Kirim response ke frontend
            // 💬 6️⃣ Logika Bisnis: Guest vs Member
            $userId = $cachedLink['user_id'] ?? null;
            $originalUrl = $cachedLink['original_url'];

            // 🟢 SKENARIO 1: Guest Link (Link Gratisan)
            // Redirect ke Loading Page di Frontend Utama (3 detik)
            if (!$userId) {
                Cache::increment("view:guest:{$code}");
                // Hardcoded Frontend URL (Localhost for dev)
                $frontendUrl = "http://localhost:3000/go";
                return redirect("{$frontendUrl}?code={$code}&token={$token}");
            }

            // 🟡 SKENARIO 2: Member Link (Safelink)
            // Lempar ke Safelink Viewer buat cuan.
            $articles = [
                'future-of-ai-2025',
                'iphone-18-rumors',
                'best-coding-laptops',
                'react-19-features',
                'cybersecurity-tips'
            ];
            $randomSlug = $articles[array_rand($articles)];

            // Hardcoded Viewer URL (Localhost for dev)
            $viewerUrl = "http://localhost:3001/article/{$randomSlug}";

            return redirect("{$viewerUrl}?code={$code}&token={$token}");
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            // 🚫 7️⃣ Jika link tidak ditemukan
            return $this->errorResponse('Shortlink not found.', 404);
        } catch (\Exception $e) {
            // ⚠️ 8️⃣ Fallback error umum
            Log::error('Error in show(): ' . $e->getMessage(), [
                'code' => $code,
                'ip' => $request->ip()
            ]);

            return $this->errorResponse('Internal server error.', 500);
        }
    }


    // ==============================
    // 2.5️⃣ ACTIVATE TOKEN — Aktivasi Token setelah User Lihat Artikel
    // ==============================
    public function activateToken($code, Request $request)
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        $inputToken = $request->input('token');
        $ip = $request->ip();
        if (app()->environment('local') && $ip === '127.0.0.1') {
            $ip = '36.84.69.10';
        }
        $userAgent = $request->header('User-Agent');
        $tokenKey = "token:{$code}:" . md5("{$ip}-{$userAgent}");

        // 1️⃣ Cek token di cache
        $cachedToken = Cache::get($tokenKey);
        if (!$cachedToken) {
            return $this->errorResponse('Token tidak ditemukan atau sudah kadaluarsa.', 404);
        }

        // 2️⃣ Validasi token match
        if ($cachedToken['token'] !== $inputToken) {
            return $this->errorResponse('Token tidak valid.', 403);
        }

        // 3️⃣ Cek apakah sudah aktif
        if (isset($cachedToken['status']) && $cachedToken['status'] === 'active') {
            return $this->successResponse(['status' => 'active'], 'Token sudah aktif.');
        }

        // 4️⃣ Cek waktu minimum (10 detik)
        $createdAt = \Carbon\Carbon::parse($cachedToken['created_at']); // Parse ke Carbon object
        $minWaitSeconds = $cachedToken['min_wait_seconds'] ?? 10;
        $elapsedSeconds = $createdAt->diffInSeconds(now()); // Hitung elapsed time (dari created_at ke sekarang)

        if ($elapsedSeconds < $minWaitSeconds) {
            $remaining = $minWaitSeconds - $elapsedSeconds;
            return $this->errorResponse("Tunggu {$remaining} detik lagi sebelum melanjutkan.", 425, ['remaining_seconds' => $remaining]);
        }

        // 5️⃣ Aktivasi token
        $cachedToken['status'] = 'active';
        $cachedToken['activated_at'] = now();

        // Update cache dengan TTL 120 detik
        Cache::put($tokenKey, $cachedToken, now()->addSeconds(120));

        return $this->successResponse([
            'status' => 'active',
            'activated_at' => $cachedToken['activated_at']
        ], 'Token berhasil diaktifkan.');
    }


    // ==============================
    // 3️⃣ CONTINUE — Validasi Token dari Redis
    // ==============================
    public function continue($code, Request $request, \App\Services\LinkService $linkService)
    {
        // === 1️⃣ Rate Limiting
        $ip = $request->ip();
        if (app()->environment('local') && $ip === '127.0.0.1') {
            $ip = '36.84.69.10';
        }
        if (RateLimiter::tooManyAttempts("continue:{$ip}", 3)) {
            return $this->errorResponse('Too many attempts. Try again later.', 429);
        }
        RateLimiter::hit("continue:{$ip}", 60);

        // === 2️⃣ Ambil link
        $cachedLink = Cache::get("link:{$code}");
        if ($cachedLink) {
            $link = (object) $cachedLink;
            if (!isset($link->id) || is_null($link->id)) {
                $dbLink = Link::where('code', $code)->first(['id', 'user_id']); // Load user_id too
                if ($dbLink) {
                    $link->id = $dbLink->id;
                    $link->user_id = $dbLink->user_id; // Ensure user_id is available
                    $cachedLink['id'] = $dbLink->id;
                    $cachedLink['user_id'] = $dbLink->user_id;
                    Cache::put("link:{$code}", $cachedLink, now()->addMinutes(10));
                }
            }
        } else {
            $link = Link::where('code', $code)->firstOrFail();
        }

        // Convert to Model instance if it's an object/array from cache, for relationship loading
        $linkModel = $link instanceof Link ? $link : Link::with('user')->find($link->id);
        if (!$linkModel) {
            return $this->errorResponse('Link not found.', 404);
        }

        // 🕰️ CRITICAL: Check expiration BEFORE processing
        if ($linkModel->expired_at && now()->greaterThan($linkModel->expired_at)) {
            $frontendUrl = "http://localhost:3000/expired";
            return response()->json([
                'message' => 'This link has expired.',
                'redirect_url' => $frontendUrl
            ], 410);
        }

        // Check banned status
        if ($linkModel->is_banned) {
            return $this->errorResponse('This link has been banned by administrator.', 403, ['reason' => $linkModel->ban_reason]);
        }

        // Check active status
        if ($linkModel->status !== 'active') {
            return $this->errorResponse('This link is currently disabled.', 410);
        }

        // === 3️⃣ Validasi Password
        if (!empty($link->password)) {
            $inputPassword = $request->input('password');
            if (!$inputPassword) {
                return $this->errorResponse('This link is protected by a password.', 401, ['requires_password' => true]);
            }
            if ($inputPassword !== $link->password) {
                return $this->errorResponse('Incorrect password.', 403);
            }
        }

        // === 4️⃣ Validasi Token via Service
        $inputToken = $request->input('token') ?? $request->bearerToken();
        $userAgent = $request->header('User-Agent') ?? '';

        // Check if this is a guest link (user_id is null)
        $isGuestLink = is_null($linkModel->user_id);

        $validation = $linkService->validateToken($code, $inputToken, $ip, $userAgent, $isGuestLink);
        if (!$validation['valid']) {
            $this->logView($linkModel, $ip, $request, false, 0, $validation['error']);
            return $this->errorResponse($validation['error'], $validation['status'] ?? 403, ['remaining' => $validation['remaining'] ?? 0]);
        }

        // === 5️⃣ Hitung Earning via Service
        $location = $linkService->getCountry($ip);
        $finalEarned = $linkService->calculateEarnings($linkModel, $ip, $location['code']);
        $isUnique = $finalEarned > 0 || ($linkModel->user_id && !View::where('link_id', $linkModel->id)->where('ip_address', $ip)->where('created_at', '>=', now()->subSeconds(5))->exists());
        $isOwnedByUser = !is_null($linkModel->user_id);

        // === 6️⃣ Log View & Update Balance
        // === 6️⃣ Log View & Update Balance
        DB::transaction(function () use ($linkModel, $ip, $request, $finalEarned, $isUnique, $isOwnedByUser, $location, $userAgent) {
            // 1. Create View Record
            View::create([
                'link_id' => $linkModel->id,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'referer' => $request->headers->get('referer'),
                'country' => $location['name'],
                'device' => $this->detectDevice($userAgent),
                'browser' => $this->detectBrowser($userAgent),
                'is_unique' => $isUnique,
                'is_valid' => true, // Token valid = view valid
                'earned' => $finalEarned,
            ]);

            // 2. Update Link Stats
            $linkModel->increment('views');
            if ($isUnique) {
                $linkModel->increment('valid_views');
                $linkModel->increment('total_earned', $finalEarned);
            }

            // 3. Update User Stats
            if ($isOwnedByUser && $isUnique) {
                // Use atomic increments without locking the user row
                // This improves concurrency significantly
                User::where('id', $linkModel->user_id)->incrementEach([
                    'total_views' => 1,
                    'total_valid_views' => 1,
                    'balance' => $finalEarned,
                    'total_earnings' => $finalEarned
                ]);

                // Check level update asynchronously (fire and forget logic essentially, or just check)
                // Since we didn't load the user with lock, we can just fetch it fresh or use a lightweight check
                $user = User::find($linkModel->user_id);
                if ($user) {
                    $user->checkAndUpdateLevel();
                }
            }
        });

        return $this->successResponse([
            'original_url' => $linkModel->original_url,
            'is_guest_link' => !$isOwnedByUser,
        ], $isOwnedByUser ? 'Valid view recorded, earnings updated.' : 'Guest link viewed (no earnings).');
    }

    // === Helper: Log View (menghindari duplikasi kode) kode opsinal untuk optimasi
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

        // Ambil dari Cache (sama seperti di store)
        $earnRates = Cache::rememberForever('app_ad_cpc_rates', function () {
            $setting = Setting::where('key', 'ad_cpc_rates')->first();
            return $setting ? $setting->value : [
                1 => 0.05,
                2 => 0.07,
                3 => 0.10,
                4 => 0.15,
                // 5 => 0.20
            ];
        });

        // Jika user ubah level iklan, perbarui earn_per_click
        $adLevel = $validated['ad_level'] ?? $link->ad_level;
        $validated['earn_per_click'] = $earnRates[$adLevel] ?? $link->earn_per_click;

        // Update semua field
        $link->update($validated);

        // ✅ Clear cache so changes are reflected immediately
        Cache::forget("link:{$link->code}");

        return $this->successResponse($link, 'Link updated successfully');
    }

    // ==============================
    // 1.5️⃣ MASS STORE — Buat Banyak Shortlink Sekaligus
    // ==============================
    public function massStore(Request $request)
    {
        $request->validate([
            'urls' => 'required|string', // Expecting newline separated URLs
            'ad_level' => 'nullable|integer|min:1|max:5',
        ]);

        $user = $request->user();
        $urls = preg_split('/\r\n|\r|\n/', $request->urls);
        $urls = array_filter($urls, function ($value) {
            return !is_null($value) && $value !== '';
        });
        $urls = array_slice($urls, 0, 20); // Limit 20 links

        $results = [];
        $adLevel = $request->input('ad_level', 1);

        foreach ($urls as $url) {
            $url = trim($url);
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                $results[] = [
                    'original_url' => $url,
                    'error' => 'Invalid URL'
                ];
                continue;
            }

            // Generate Code
            $code = Str::random(6);
            while (Link::where('code', $code)->exists()) {
                $code = Str::random(6);
            }

            // Create Link
            $link = Link::create([
                'user_id' => $user ? $user->id : null,
                'original_url' => $url,
                'code' => $code,
                'title' => null,
                'password' => null,
                'expired_at' => null,
                'status' => 'active',
                'ad_level' => $adLevel,
            ]);

            // Cache
            $cachedLink = [
                'id' => $link->id,
                'original_url' => $link->original_url,
                'password' => $link->password,
                'status' => $link->status,
                'user_id' => $link->user_id,
                'expired_at' => $link->expired_at,
                'ad_level' => $link->ad_level,
                'is_banned' => false
            ];
            Cache::put("link:{$code}", $cachedLink, now()->addHours(24));

            $results[] = [
                'original_url' => $url,
                'short_url' => url($code),
                'code' => $code
            ];
        }

        return $this->successResponse($results, 'Mass shorten completed');
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

        // ✅ CRITICAL: Clear cache so next access fetches fresh status
        Cache::forget("link:{$link->code}");

        return $this->successResponse(['status' => $link->status], 'Status link diperbarui');
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
