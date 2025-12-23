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

        // Base Query dengan Aggregates (Optimized: Use denormalized columns)
        // valid_views = view yang valid (lolos anti-cheat), ini yang ditampil ke user
        $query = Link::where('user_id', $user->id)
            ->selectRaw('links.*, links.valid_views as total_views, (links.earn_per_click * 1000) as calculated_cpm');
        // ->withCount removed (redundant)
        // ->withSum removed (redundant)

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

        // 3.5 Filter Ad Level
        if ($adLevel = $request->input('ad_level')) {
            if ($adLevel === 'noAds') {
                $query->where('ad_level', 0);
            } elseif (str_starts_with($adLevel, 'level')) {
                // Extract number from "level1" -> 1
                $level = (int) str_replace('level', '', $adLevel);
                $query->where('ad_level', $level);
            } elseif (is_numeric($adLevel)) {
                $query->where('ad_level', $adLevel);
            }
        }

        // 4. Sorting / Filtering Khusus
        $filter = $request->input('filter');
        switch ($filter) {
            case 'top_links': // Terbanyak diklik (Total Views)
                $query->orderByDesc('views'); // Use real column
                break;
            case 'least_links': // Sedikit views
                $query->orderBy('views'); // Use real column
                break;
            case 'top_valid': // Terbanyak valid click
                $query->orderByDesc('valid_views');
                break;
            case 'top_earned': // Terbanyak penghasilan
                $query->orderByDesc('total_earned');
                break;
            case 'least_earned': // Sedikit penghasilan
                $query->orderBy('total_earned');
                break;
            case 'avg_cpm': // Sort by Average CPM (calculated)
                $query->orderByDesc('calculated_cpm');
                break;
            case 'link_password': // Filter links with password
                $query->whereNotNull('password')
                    ->where('password', '!=', '');
                break;
            case 'expired': // Yang sudah expired
                $query->whereNotNull('expired_at')->where('expired_at', '<', now());
                break;
            case 'oldest': // Terlama
                $query->oldest();
                break;
            case 'newest': // Terbaru
                $query->latest();
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
            'id' => $link->id,
            'original_url' => $link->original_url,
            'user_id' => $link->user_id,
            'password' => $link->password,
            'expired_at' => $link->expired_at,
            'earn_per_click' => $link->earn_per_click,
            'ad_level' => $link->ad_level, // ✅ Include ad_level
            'status' => $link->status,
            'is_banned' => false,
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
            'original_url' => $link->original_url,
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
                    'ad_level' => $link->ad_level ?? 1, // ✅ Include ad_level
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
                $viewerUrl = "http://localhost:3001/banned";
                $reason = urlencode($cachedLink['ban_reason'] ?? '');
                Log::info("Redirecting to banned page for code: {$code}");
                return redirect("{$viewerUrl}?reason={$reason}");
            }

            // 🟡 Determine ad_level and maxSteps early (needed for token cache)
            $userId = $cachedLink['user_id'] ?? null;
            $adLevel = (int) ($cachedLink['ad_level'] ?? 1);
            $maxSteps = match ($adLevel) {
                1 => 1,
                2 => 2,
                3 => 3,
                4 => 3,
                default => 1,
            };

            // ========================================
            // 🎟️ GUEST LINK FREE PASS - BEFORE RATE LIMIT
            // Direct redirects bypass rate limiting for better UX
            // ========================================
            if (!$userId) {
                $linkModel = Link::find($cachedLink['id']);
                $currentViews = $linkModel->views ?? 0;

                // Click 1 (views=0): Always direct redirect - FREE PASS (no rate limit)
                if ($currentViews === 0) {
                    $linkModel->increment('views');
                    Log::info("🎟️ Guest Free Pass: Click 1 - Direct redirect", ['code' => $code]);
                    return redirect()->away($cachedLink['original_url']);
                }

                // Click 3+ (views>=2): Check against next_confirm_at for FREE PASS
                if ($currentViews >= 2) {
                    $nextConfirm = $linkModel->next_confirm_at ?? 2;
                    if ($currentViews < $nextConfirm) {
                        // Free pass - direct redirect (no rate limit)
                        $linkModel->increment('views');
                        Log::info("🎟️ Guest Free Pass: Click {$currentViews} - Direct redirect (next confirm at {$nextConfirm})", ['code' => $code]);
                        return redirect()->away($cachedLink['original_url']);
                    }
                }
                // If not a direct redirect, continue to rate limiter and confirmation page
            }

            // 🧩 3️⃣ Buat token unik & simpan berdasarkan IP dan User-Agent
            $token = Str::uuid()->toString();
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
                'status' => 'pending',
                'min_wait_seconds' => 5, // Reduced to 5 seconds
                'created_at' => now(),
                'activated_at' => null,
                // 🛡️ Step validation fields
                'completed_step' => 0,
                'max_steps' => $maxSteps,
                'ad_level' => $adLevel,
            ], now()->addSeconds(600)); // 10 minutes for multi-step

            // 🛡️ 4️⃣ Rate limiting (maks 3 request per menit per IP)
            $rateKey = "rate:{$ip}:{$code}";
            if (RateLimiter::tooManyAttempts($rateKey, 3)) {
                return $this->errorResponse('Too many requests. Please wait a moment.', 429);
            }
            RateLimiter::hit($rateKey, 60);

            // 📈 5️⃣ Catat tampilan awal (pre-view) ringan ke Redis
            Cache::increment("preview:{$code}:count");

            // 🟢 SKENARIO 1: Guest Link - CONFIRMATION PAGE (after rate limit)
            if (!$userId) {
                $linkModel = Link::find($cachedLink['id']);
                $currentViews = $linkModel->views ?? 0;

                // Click 2 or confirmation threshold reached - show confirmation page
                Cache::increment("view:guest:{$code}");
                $sessionId = $this->createUrlSession($code, $token, 1, 1, 1, true);
                $viewerUrl = "http://localhost:3001/go";
                Log::info("🎟️ Guest Free Pass: Click {$currentViews} - Show confirmation", ['code' => $code]);
                return redirect("{$viewerUrl}?s={$sessionId}");
            }

            // 🟡 SKENARIO 2: Member Link (Safelink)
            Log::info("🔍 DEBUG show(): ad_level={$adLevel}, maxSteps={$maxSteps}");

            // 🔐 Create session for clean URL
            $sessionId = $this->createUrlSession($code, $token, 1, $maxSteps, $adLevel, false);

            $viewerUrl = "http://localhost:3001/article/step1";
            return redirect("{$viewerUrl}?s={$sessionId}");
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
    // 🛡️ VALIDATE STEP — Check if user can access this step
    // ==============================
    public function validateStep($code, Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'step' => 'required|integer|min:1|max:5',
        ]);

        $inputToken = $request->input('token');
        $requestedStep = (int) $request->input('step');
        $ip = $request->ip();
        if (app()->environment('local') && $ip === '127.0.0.1') {
            $ip = '36.84.69.10';
        }
        $userAgent = $request->header('User-Agent');
        $tokenKey = "token:{$code}:" . md5("{$ip}-{$userAgent}");

        // 1️⃣ Cek token di cache
        $cachedToken = Cache::get($tokenKey);
        if (!$cachedToken) {
            return $this->errorResponse('Token tidak ditemukan. Silakan mulai dari awal.', 404, ['redirect' => true]);
        }

        // 2️⃣ Validasi token match
        if ($cachedToken['token'] !== $inputToken) {
            return $this->errorResponse('Token tidak valid.', 403, ['redirect' => true]);
        }

        // 3️⃣ Cek step yang sudah selesai
        $completedStep = $cachedToken['completed_step'] ?? 0;
        $maxSteps = $cachedToken['max_steps'] ?? 1;

        // 4️⃣ User hanya boleh akses step berikutnya (completedStep + 1)
        $allowedStep = $completedStep + 1;

        if ($requestedStep > $allowedStep) {
            Log::warning("🛡️ Step skip detected", [
                'code' => $code,
                'requested_step' => $requestedStep,
                'completed_step' => $completedStep,
                'allowed_step' => $allowedStep,
            ]);
            return $this->errorResponse('Anda harus menyelesaikan langkah sebelumnya.', 403, [
                'redirect' => true,
                'redirect_step' => $allowedStep,
            ]);
        }

        return $this->successResponse([
            'valid' => true,
            'current_step' => $requestedStep,
            'completed_step' => $completedStep,
            'max_steps' => $maxSteps,
        ], 'Step valid.');
    }


    // ==============================
    // 🛡️ COMPLETE STEP — Mark step as completed
    // ==============================
    public function completeStep($code, Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'step' => 'required|integer|min:1|max:5',
        ]);

        $inputToken = $request->input('token');
        $completingStep = (int) $request->input('step');
        $ip = $request->ip();
        if (app()->environment('local') && $ip === '127.0.0.1') {
            $ip = '36.84.69.10';
        }
        $userAgent = $request->header('User-Agent');
        $tokenKey = "token:{$code}:" . md5("{$ip}-{$userAgent}");

        // 1️⃣ Cek token di cache
        $cachedToken = Cache::get($tokenKey);
        if (!$cachedToken) {
            return $this->errorResponse('Token tidak ditemukan.', 404, ['redirect' => true]);
        }

        // 2️⃣ Validasi token match
        if ($cachedToken['token'] !== $inputToken) {
            return $this->errorResponse('Token tidak valid.', 403, ['redirect' => true]);
        }

        // 3️⃣ Cek tidak boleh skip step
        $currentCompleted = $cachedToken['completed_step'] ?? 0;
        if ($completingStep > $currentCompleted + 1) {
            return $this->errorResponse('Tidak bisa melewati langkah.', 403, ['redirect' => true]);
        }

        // 4️⃣ Update completed_step
        $cachedToken['completed_step'] = $completingStep;
        Cache::put($tokenKey, $cachedToken, now()->addSeconds(600));

        $maxSteps = $cachedToken['max_steps'] ?? 1;
        $isComplete = $completingStep >= $maxSteps;

        Log::info("🛡️ Step completed", [
            'code' => $code,
            'completed_step' => $completingStep,
            'max_steps' => $maxSteps,
            'all_complete' => $isComplete,
        ]);

        return $this->successResponse([
            'completed_step' => $completingStep,
            'max_steps' => $maxSteps,
            'is_complete' => $isComplete,
            'next_step' => $isComplete ? null : $completingStep + 1,
        ], "Langkah {$completingStep} selesai.");
    }


    // ==============================
    // 🛡️ CHECK STEP STATUS — Get current step completion status
    // ==============================
    public function checkStepStatus($code, Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $inputToken = $request->input('token');
        $ip = $request->ip();
        if (app()->environment('local') && $ip === '127.0.0.1') {
            $ip = '36.84.69.10';
        }
        $userAgent = $request->header('User-Agent');
        $tokenKey = "token:{$code}:" . md5("{$ip}-{$userAgent}");

        // Cek token di cache
        $cachedToken = Cache::get($tokenKey);
        if (!$cachedToken) {
            return $this->errorResponse('Token tidak ditemukan.', 404, ['all_complete' => false]);
        }

        // Validasi token match
        if ($cachedToken['token'] !== $inputToken) {
            return $this->errorResponse('Token tidak valid.', 403, ['all_complete' => false]);
        }

        $completedStep = $cachedToken['completed_step'] ?? 0;
        $maxSteps = $cachedToken['max_steps'] ?? 1;
        $allComplete = $completedStep >= $maxSteps;

        return $this->successResponse([
            'completed_step' => $completedStep,
            'max_steps' => $maxSteps,
            'all_complete' => $allComplete,
        ], $allComplete ? 'All steps completed.' : 'Steps not yet complete.');
    }


    // ==============================
    // 🔐 SESSION MANAGEMENT — Hide URL Parameters
    // ==============================

    /**
     * Generate short session ID
     */
    private function generateSessionId(): string
    {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 12);
    }

    /**
     * Create session - Store link data in Redis, return session ID
     * Called internally when redirecting to viewer
     */
    private function createUrlSession(string $code, string $token, int $step, int $maxSteps, int $adLevel, bool $isGuest = false): string
    {
        $sessionId = $this->generateSessionId();
        $sessionKey = "url_session:{$sessionId}";

        Cache::put($sessionKey, [
            'code' => $code,
            'token' => $token,
            'step' => $step,
            'max_steps' => $maxSteps,
            'ad_level' => $adLevel,
            'is_guest' => $isGuest,
            'created_at' => now()->toISOString(),
        ], now()->addMinutes(15)); // 15 minutes TTL

        return $sessionId;
    }

    /**
     * GET /links/session/{sid}
     * Get session data for frontend
     */
    public function getSession($sessionId)
    {
        $sessionKey = "url_session:{$sessionId}";
        $sessionData = Cache::get($sessionKey);

        if (!$sessionData) {
            return $this->errorResponse('Session tidak ditemukan atau sudah kadaluarsa.', 404);
        }

        return $this->successResponse($sessionData, 'Session retrieved.');
    }

    /**
     * PUT /links/session/{sid}/step
     * Update current step in session (when navigating between steps)
     */
    public function updateSessionStep($sessionId, Request $request)
    {
        $request->validate([
            'step' => 'required|integer|min:1|max:5',
        ]);

        $sessionKey = "url_session:{$sessionId}";
        $sessionData = Cache::get($sessionKey);

        if (!$sessionData) {
            return $this->errorResponse('Session tidak ditemukan atau sudah kadaluarsa.', 404);
        }

        $sessionData['step'] = (int) $request->input('step');
        Cache::put($sessionKey, $sessionData, now()->addMinutes(15)); // Refresh TTL

        return $this->successResponse($sessionData, 'Session step updated.');
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
            $viewerUrl = "http://localhost:3001/banned";
            $reason = urlencode($linkModel->ban_reason ?? '');
            return redirect("{$viewerUrl}?reason={$reason}");
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

        // ========================================
        // 🎟️ GUEST LINK: Free Pass Confirmation Logic
        // ========================================
        if ($isGuestLink) {
            // Increment views for guest link
            $linkModel->increment('views');

            // Set next_confirm_at = current views + random(1,4)
            $currentViews = $linkModel->views;
            $randomInterval = rand(1, 4);
            $nextConfirm = $currentViews + $randomInterval;
            $linkModel->update(['next_confirm_at' => $nextConfirm]);

            Log::info("🎟️ Guest Free Pass: views={$currentViews}, next_confirm_at={$nextConfirm}");
        }

        // 🛡️ STEP VALIDATION: Only for NON-guest links (member safelinks)
        if (!$isGuestLink) {
            $userAgent = $request->header('User-Agent') ?? '';
            $tokenKey = "token:{$code}:" . md5("{$ip}-{$userAgent}");
            $cachedToken = Cache::get($tokenKey);

            if ($cachedToken) {
                $completedStep = $cachedToken['completed_step'] ?? 0;
                $maxSteps = $cachedToken['max_steps'] ?? 1;

                if ($completedStep < $maxSteps) {
                    Log::warning("🛡️ Direct continue attempt blocked", [
                        'code' => $code,
                        'completed_step' => $completedStep,
                        'max_steps' => $maxSteps,
                    ]);
                    return $this->errorResponse('Anda harus menyelesaikan semua langkah terlebih dahulu.', 403, [
                        'completed_step' => $completedStep,
                        'max_steps' => $maxSteps,
                        'redirect' => true,
                    ]);
                }
            }
        }

        $validation = $linkService->validateToken($code, $inputToken, $ip, $userAgent, $isGuestLink);
        if (!$validation['valid']) {
            $this->logView($linkModel, $ip, $request, false, 0, $validation['error']);
            return $this->errorResponse($validation['error'], $validation['status'] ?? 403, ['remaining' => $validation['remaining'] ?? 0]);
        }

        // === 5️⃣ Hitung Earning via Service + Anti-Fraud
        $location = $linkService->getCountry($ip);

        // 🛡️ Get visitor_id (device fingerprint) from request
        $visitorId = $request->input('visitor_id');

        // Calculate earnings with anti-fraud protection
        $earningResult = $linkService->calculateEarnings($linkModel, $ip, $location['code'], $visitorId);

        $finalEarned = $earningResult['earning'];
        $isValidView = $earningResult['is_valid'];
        $rejectionReason = $earningResult['rejection_reason'];

        // isUnique is true only if it's a valid earning view
        $isUnique = $isValidView && $finalEarned > 0;
        $isOwnedByUser = !is_null($linkModel->user_id);

        // === 6️⃣ Log View & Update Balance (with Shadow Banning)

        // 🚀 FULL REDIS MODE: Set to true for startup (lighter storage)
        // Set to false when platform grows and you need detailed analytics
        $FULL_REDIS_MODE = true;

        DB::transaction(function () use ($linkModel, $ip, $request, $finalEarned, $isUnique, $isValidView, $rejectionReason, $isOwnedByUser, $location, $userAgent, $visitorId, $FULL_REDIS_MODE) {

            // ================================================================
            // 📊 VIEW RECORDING (Only in Hybrid Mode, skipped in Full Redis)
            // ================================================================
            // Uncomment/enable this when platform grows and you need:
            // - Detailed analytics (device, browser, referer per view)
            // - Fraud audit logs
            // - Historical view data
            // ================================================================
            if (!$FULL_REDIS_MODE) {
                // 1. Create View Record (ALWAYS CREATE, even if invalid - Shadow Banning)
                View::create([
                    'link_id' => $linkModel->id,
                    'ip_address' => $ip,
                    'visitor_id' => $visitorId, // 🛡️ Device Fingerprint
                    'user_agent' => $userAgent,
                    'referer' => $request->headers->get('referer'),
                    'country' => $location['name'],
                    'device' => $this->detectDevice($userAgent),
                    'browser' => $this->detectBrowser($userAgent),
                    'is_unique' => $isUnique,
                    'is_valid' => $isValidView, // 🛡️ False if fraud detected
                    'rejection_reason' => $rejectionReason, // 🛡️ Why it was rejected
                    'earned' => $finalEarned,
                    'publisher_earning' => $isValidView ? $finalEarned : 0, // 🛡️ Actual earning to publisher
                ]);
            }
            // ================================================================

            // 2. Update Link Stats
            // views = TOTAL semua klik (termasuk fraud) - untuk analytics
            // valid_views = klik valid yang menghasilkan earning
            $linkModel->increment('views'); // ALWAYS increment for all clicks

            if ($isValidView && $isUnique) {
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

        // Map 'alias' input to 'code' column
        if (isset($validated['alias'])) {
            $validated['code'] = $validated['alias'];
            unset($validated['alias']);
        }

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
