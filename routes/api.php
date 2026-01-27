<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\AdminLoginController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
// use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LinkController;
use App\Http\Controllers\Api\StatsController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\Admin\AdminWithdrawalController;
use App\Http\Controllers\Api\Admin\AdminLinkController;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\PaymentMethodController;
use App\Http\Controllers\Api\PayoutController;
use App\Http\Controllers\Api\UserNotificationController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\SocialiteController;
use App\Http\Controllers\Api\Admin\AdminSettingController;
use App\Http\Controllers\Api\Admin\AdminLevelController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\Admin\AdminReportController;
use App\Http\Controllers\Api\ReferralController;
use Illuminate\Support\Facades\Notification; // Penting untuk pengiriman massal
use App\Notifications\GeneralNotification;   // Class notifikasi yang kita buat
use App\Models\User;

use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\LoginHistoryController;
use App\Http\Controllers\Api\UserLevelController;
use App\Http\Controllers\Api\AdLevelController;
use App\Http\Controllers\Api\Admin\AdminAdLevelController;
use App\Http\Controllers\Api\Admin\GlobalFeatureController;
use App\Http\Controllers\Api\Admin\AdminCpcRateController;
use App\Http\Controllers\Api\Admin\GlobalNotificationController;
use App\Http\Controllers\Api\EmailVerificationController;



// ✅ BREEZE ROUTES (auto-generated)
Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware('guest')
    ->name('register');

Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest')
    ->name('login');

// 🔐 Admin Backdoor Login (bypasses disable_login setting)
Route::post('/admin-login', [AdminLoginController::class, 'store'])
    ->middleware('guest')
    ->name('admin.login');

Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware('guest')
    ->name('password.email');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware('guest')
    ->name('password.store');

Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['auth:sanctum', 'signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:6,1'])
    ->name('verification.send');

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->name('logout');

// 📧 Email Verification (Custom - for frontend integration)
Route::post('/email/verify', [EmailVerificationController::class, 'verify'])
    ->middleware('throttle:6,1')
    ->name('email.verify.custom');

Route::post('/email/resend', [EmailVerificationController::class, 'resend'])
    ->middleware(['auth:sanctum', 'throttle:3,1']);

Route::get('/email/status', [EmailVerificationController::class, 'status'])
    ->middleware('auth:sanctum');



Route::post('/auth/google/callback', [SocialiteController::class, 'handleGoogleCallback']);


// -------------------- SHORTLINK (Public / Hybrid) --------------------
Route::middleware('throttle:10,1')->post('/links', [LinkController::class, 'store']); // guest & user
Route::middleware(['auth:sanctum', 'throttle:10,1'])->post('/links/mass', [LinkController::class, 'massStore']); // user only
Route::get('/links/{code}', [LinkController::class, 'show']);
Route::post('/links/{code}/activate-token', [LinkController::class, 'activateToken']); // Aktivasi token
Route::post('/links/{code}/validate-step', [LinkController::class, 'validateStep']); // 🛡️ Validate step access
Route::post('/links/{code}/complete-step', [LinkController::class, 'completeStep']); // 🛡️ Mark step as completed
Route::post('/links/{code}/check-step-status', [LinkController::class, 'checkStepStatus']); // 🛡️ Check if all steps done
Route::get('/links/session/{sid}', [LinkController::class, 'getSession']); // 🔐 Get session data
Route::put('/links/session/{sid}/step', [LinkController::class, 'updateSessionStep']); // 🔐 Update session step
Route::post('/links/{code}/continue', [LinkController::class, 'continue']);
Route::get('/check-alias/{alias}', [LinkController::class, 'checkAlias']);
Route::middleware('auth:sanctum')->patch('/links/{id}/toggle-status', [LinkController::class, 'toggleStatus']);
Route::post('/report', [ReportController::class, 'store']);

// Public referral info (no auth required)
Route::get('/referral/info', [ReferralController::class, 'getReferrerInfo']);
Route::post('/referral/check-eligibility', [ReferralController::class, 'checkEligibility'])
    ->middleware('throttle:10,1');

// -------------------- AUTH PROTECTED (Only Logged In) --------------------
Route::middleware(['auth:sanctum', 'is_banned'])->group(function () {
    Route::get('/links', [LinkController::class, 'index']);
    Route::put('/links/{code}', [LinkController::class, 'update']);

    // ✅ Change Password
    Route::put('/user/password', [ProfileController::class, 'updatePassword']);
    Route::put('/user/profile', [ProfileController::class, 'updateProfile']);
    Route::get('/user/security', [ProfileController::class, 'getSecuritySettings']); // ✅ NEW: Get security settings
    Route::get('/user/login-history', [LoginHistoryController::class, 'index']);
    Route::get('/user/levels', [UserLevelController::class, 'index']);
    Route::get('/user/stats', [\App\Http\Controllers\Api\UserStatsController::class, 'headerStats']);

    // Ad Level Configs (public read for ads-info page + dropdown)
    Route::get('/ad-levels', [AdLevelController::class, 'index']);

    // 🔧 Link Settings (public read for mass_link_limit)
    Route::get('/settings/link', [LinkController::class, 'getLinkSettings']);

    // ✅ Get Current User Profile (for sidebar)
    Route::get('/user/me', function (\Illuminate\Http\Request $request) {
        $user = $request->user();
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'avatar' => $user->avatar ?? 'avatar-1',
            ]
        ]);
    });


    // Statistik & kontrol link (hanya user)
    Route::get('/dashboard/overview', [DashboardController::class, 'overview']);
    Route::get('/dashboard/trends', [DashboardController::class, 'trends']);

    // ✅ Analytics Stats Endpoints
    Route::get('/dashboard/summary/earnings', [StatsController::class, 'getEarnings']);
    Route::get('/dashboard/summary/clicks', [StatsController::class, 'getClicks']);
    Route::get('/dashboard/summary/referrals', [StatsController::class, 'getReferralStats']);
    Route::get('/dashboard/summary/cpm', [StatsController::class, 'getAverageCpm']);
    Route::get('/dashboard/analytics', [StatsController::class, 'analytics']);
    Route::get('/analytics/monthly-performance', [StatsController::class, 'monthlyPerformance']);
    Route::get('/analytics/top-countries', [StatsController::class, 'topCountries']);
    Route::get('/analytics/top-referrers', [StatsController::class, 'topReferrers']);


    // Payment Methods
    Route::get('/payment-methods', [PaymentMethodController::class, 'index']);
    Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
    Route::patch('/payment-methods/{id}/default', [PaymentMethodController::class, 'setDefault']);
    Route::delete('/payment-methods/{id}', [PaymentMethodController::class, 'destroy']);
    Route::put('/payment-methods/{id}', [PaymentMethodController::class, 'update']);


    // Withdrawals
    Route::get('/withdrawals', [PayoutController::class, 'index']);
    Route::post('/withdrawals', [PayoutController::class, 'store']);

    // Admin Withdrawal Stats
    Route::get('/admin/withdrawals/daily-stats', [AdminWithdrawalController::class, 'getDailyStats']);

    // Referral

    // Referral
    Route::get('/referrals', [ReferralController::class, 'index']);
    Route::delete('/withdrawals/{id}', [PayoutController::class, 'cancel']);
    Route::delete('/withdrawals/delete/{id}', [PayoutController::class, 'destroy']);

    // Ambil semua notifikasi user yang login
    Route::get('/notifications', [NotificationController::class, 'index']);

    // Ambil jumlah notifikasi yang belum dibaca
    Route::get('/notifications/unread', [NotificationController::class, 'unreadCount']);

    // Tandai satu notifikasi sebagai sudah dibaca
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);

    // Tandai semua notifikasi sebagai sudah dibaca
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    // Hapus satu notifikasi
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    // ✅ Dashboard Messages (User)
    Route::get('/dashboard/messages', [\App\Http\Controllers\Api\DashboardMessageController::class, 'activeMessages']);
});

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/withdrawals', [AdminWithdrawalController::class, 'index']);
    Route::put('/withdrawals/{id}/status', [AdminWithdrawalController::class, 'updateStatus']);
    Route::get('/dashboard/overview', [AdminDashboardController::class, 'overview']);
    Route::get('/dashboard/trends', [AdminDashboardController::class, 'trends']);

    // Analytics endpoints
    Route::get('/analytics/top-countries', [AdminDashboardController::class, 'topCountries']);
    Route::get('/analytics/revenue-chart', [AdminDashboardController::class, 'revenueChart']);
    Route::get('/analytics/active-users-chart', [AdminDashboardController::class, 'activeUsersChart']);

    Route::get('/links', [AdminLinkController::class, 'index']);
    Route::get('/links/stats', [AdminLinkController::class, 'stats']); // Stats for dashboard cards
    Route::post('/links/bulk-ban', [AdminLinkController::class, 'bulkBan']); // Legacy: by keyword
    Route::post('/links/bulk-action', [AdminLinkController::class, 'bulkAction']); // New: by IDs
    Route::put('/links/{id}', [AdminLinkController::class, 'update']);
    Route::delete('/links/{id}', [AdminLinkController::class, 'destroy']);

    Route::get('/settings/ad-rates', [AdminSettingController::class, 'getAdRates']);
    Route::put('/settings/ad-rates', [AdminSettingController::class, 'updateAdRates']);
    Route::post('/settings/ad-rates', [AdminSettingController::class, 'storeAdLevel']); // <-- New Route
    Route::delete('/settings/ad-rates/{level}', [AdminSettingController::class, 'deleteCountryRate']); // Renamed method

    // Dynamic Level Management
    Route::post('/settings/ad-rates/level', [AdminSettingController::class, 'addAdLevelColumn']);
    Route::delete('/settings/ad-rates/level/{key}', [AdminSettingController::class, 'deleteAdLevelColumn']);

    // Settings: Withdrawal (BARU ✅)
    Route::get('/settings/withdrawal', [AdminSettingController::class, 'getWithdrawalSettings']);
    Route::put('/settings/withdrawal', [AdminSettingController::class, 'updateWithdrawalSettings']);

    Route::get('/settings/bank-fees', [AdminSettingController::class, 'getBankFees']);
    Route::put('/settings/bank-fees', [AdminSettingController::class, 'updateBankFees']);

    // Settings: Registration (BARU ✅)
    Route::get('/settings/registration', [AdminSettingController::class, 'getRegistrationSettings']);
    Route::put('/settings/registration', [AdminSettingController::class, 'updateRegistrationSettings']);

    // Settings: Referral (BARU ✅)
    Route::get('/settings/referral', [AdminSettingController::class, 'getReferralSettings']);
    Route::put('/settings/referral', [AdminSettingController::class, 'updateReferralSettings']);

    Route::get('/settings/notification', [AdminSettingController::class, 'getNotificationSettings']);
    Route::put('/settings/notification', [AdminSettingController::class, 'updateNotificationSettings']);

    // Settings: Self-Click (BARU ✅)
    Route::get('/settings/self-click', [AdminSettingController::class, 'getSelfClickSettings']);
    Route::put('/settings/self-click', [AdminSettingController::class, 'updateSelfClickSettings']);

    // Settings: Link (Token Duration)
    Route::get('/settings/link', [AdminSettingController::class, 'getLinkSettings']);
    Route::put('/settings/link', [AdminSettingController::class, 'updateLinkSettings']);

    // Settings: Currency Rates (Manual Exchange Rates)
    Route::get('/settings/currency-rates', [AdminSettingController::class, 'getCurrencyRates']);
    Route::put('/settings/currency-rates', [AdminSettingController::class, 'updateCurrencyRates']);

    // Report Abuse
    Route::get('/reports', [AdminReportController::class, 'index']);
    Route::get('/reports/stats', [AdminReportController::class, 'stats']);
    Route::patch('/reports/{id}/resolve', [AdminReportController::class, 'resolve']);
    Route::patch('/reports/{id}/ignore', [AdminReportController::class, 'ignore']);

    // Global Notifications (BARU ✅)
    Route::get('/global-notifications', [GlobalNotificationController::class, 'index']);
    Route::post('/global-notifications', [GlobalNotificationController::class, 'store']);
    Route::delete('/global-notifications/{id}', [GlobalNotificationController::class, 'destroy']);
    Route::patch('/global-notifications/{id}/pin', [GlobalNotificationController::class, 'togglePin']);
    Route::patch('/reports/{id}/block-link', [AdminReportController::class, 'blockLink']);
    Route::delete('/reports/{id}', [AdminReportController::class, 'destroy']);

    // Level Management (User Progression Levels)
    Route::get('/levels', [AdminLevelController::class, 'index']);
    Route::get('/levels/stats', [AdminLevelController::class, 'stats']);
    Route::post('/levels', [AdminLevelController::class, 'store']);
    Route::get('/levels/{slug}', [AdminLevelController::class, 'show']);
    Route::put('/levels/{slug}', [AdminLevelController::class, 'update']);
    Route::delete('/levels/{slug}', [AdminLevelController::class, 'destroy']);

    // Ad Level Configs CRUD
    Route::apiResource('ad-levels', AdminAdLevelController::class);
    Route::patch('ad-levels/{id}/toggle', [AdminAdLevelController::class, 'toggleEnabled']);
    Route::post('ad-levels/{id}/set-default', [AdminAdLevelController::class, 'setDefault']);
    Route::post('ad-levels/{id}/set-recommended', [AdminAdLevelController::class, 'setRecommended']);

    // Global Features CRUD
    Route::apiResource('global-features', GlobalFeatureController::class);

    // CPC Rates Management
    Route::get('cpc-rates', [AdminCpcRateController::class, 'index']);
    Route::post('cpc-rates', [AdminCpcRateController::class, 'store']);
    Route::post('cpc-rates/country', [AdminCpcRateController::class, 'addCountry']);
    Route::delete('cpc-rates/country/{country}', [AdminCpcRateController::class, 'removeCountry']);

    Route::post('/notify', function (Request $request) {
        // Validasi Input
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'title' => 'required|string',
            'message' => 'required|string',
            'type' => 'in:info,success,warning,danger',
            'url' => 'nullable|url',
            'is_permanent' => 'boolean', // Default true
            'expires_in_days' => 'nullable|integer|min:1' // Opsional, default 14 hari jika is_permanent false
        ]);

        // Siapkan data notifikasi
        $type = $validated['type'] ?? 'info';
        $url = $validated['url'] ?? null;
        $isPermanent = $validated['is_permanent'] ?? true;

        // Logic Expiration
        $expiresAt = null;
        if (!$isPermanent) {
            $days = (int) ($validated['expires_in_days'] ?? 14); // Default 14 hari, cast ke int
            $expiresAt = now()->addDays($days);
        }

        $notification = new GeneralNotification(
            $validated['title'],
            $validated['message'],
            $type,
            $url,
            $expiresAt
        );

        if ($validated['user_id']) {
            // SKENARIO 1: Kirim ke SATU user spesifik
            $user = User::find($validated['user_id']);
            if ($user) {
                $user->notify($notification);
            }
        } else {
            // SKENARIO 2: Kirim ke SEMUA user (Broadcast)
            // Menggunakan Facade Notification::send jauh lebih cepat & hemat memori
            // daripada melakukan foreach manual.
            Notification::send(User::all(), $notification);
        }

        return response()->json(['message' => 'Notifikasi berhasil dikirim.']);
    });

    // User Management
    Route::prefix('users')->group(function () {
        Route::get('/stats', [\App\Http\Controllers\Api\Admin\AdminUserController::class, 'stats']);
        Route::post('/notify', [\App\Http\Controllers\Api\Admin\AdminUserController::class, 'notify']);
        Route::get('/', [\App\Http\Controllers\Api\Admin\AdminUserController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\Api\Admin\AdminUserController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\Api\Admin\AdminUserController::class, 'update']);
        Route::patch('/{id}/ban', [\App\Http\Controllers\Api\Admin\AdminUserController::class, 'ban']);
        Route::patch('/{id}/unban', [\App\Http\Controllers\Api\Admin\AdminUserController::class, 'unban']);
    });

    // ✅ Dashboard Messages (Admin)
    Route::apiResource('/dashboard-messages', \App\Http\Controllers\Api\DashboardMessageController::class);
});

Route::middleware(['auth:sanctum', 'super_admin'])->prefix('super-admin')->group(function () {
    // Admin Management
    Route::get('/admins', [\App\Http\Controllers\Api\SuperAdmin\SuperAdminController::class, 'index']);
    Route::get('/admins/stats', [\App\Http\Controllers\Api\SuperAdmin\SuperAdminController::class, 'stats']);
    Route::post('/admins', [\App\Http\Controllers\Api\SuperAdmin\SuperAdminController::class, 'store']);
    Route::put('/admins/{id}', [\App\Http\Controllers\Api\SuperAdmin\SuperAdminController::class, 'update']);
    Route::patch('/admins/{id}/toggle-status', [\App\Http\Controllers\Api\SuperAdmin\SuperAdminController::class, 'toggleStatus']);
    Route::delete('/admins/{id}', [\App\Http\Controllers\Api\SuperAdmin\SuperAdminController::class, 'destroy']);

    // ✅ Withdrawal Logs
    Route::get('/withdrawal-logs', [\App\Http\Controllers\Api\SuperAdmin\SuperAdminController::class, 'getWithdrawalLogs']);

    // ✅ Payment Method Templates Management
    Route::prefix('payment-templates')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\SuperAdmin\PaymentMethodTemplateController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\SuperAdmin\PaymentMethodTemplateController::class, 'store']);
        Route::put('/{template}', [\App\Http\Controllers\Api\SuperAdmin\PaymentMethodTemplateController::class, 'update']);
        Route::delete('/{template}', [\App\Http\Controllers\Api\SuperAdmin\PaymentMethodTemplateController::class, 'destroy']);
        Route::patch('/{template}/toggle', [\App\Http\Controllers\Api\SuperAdmin\PaymentMethodTemplateController::class, 'toggleActive']);
        Route::post('/reorder', [\App\Http\Controllers\Api\SuperAdmin\PaymentMethodTemplateController::class, 'reorder']);
    });

    // ✅ General Settings Management
    Route::prefix('settings')->group(function () {
        Route::get('/general', [\App\Http\Controllers\Api\SuperAdmin\GeneralSettingsController::class, 'getSettings']);
        Route::put('/general', [\App\Http\Controllers\Api\SuperAdmin\GeneralSettingsController::class, 'updateSettings']);
        Route::post('/force-logout', [\App\Http\Controllers\Api\SuperAdmin\GeneralSettingsController::class, 'forceLogout']);
        Route::post('/cleanup', [\App\Http\Controllers\Api\SuperAdmin\GeneralSettingsController::class, 'runCleanup']);
    });

    // ✅ Violation Referrer Management
    Route::prefix('violation-referrers')->group(function () {
        // Static routes FIRST (before {id} routes)
        Route::get('/settings', [\App\Http\Controllers\Api\Admin\ViolationReferrerController::class, 'getSettings']);
        Route::put('/settings', [\App\Http\Controllers\Api\Admin\ViolationReferrerController::class, 'updateSettings']);
        Route::get('/stats', [\App\Http\Controllers\Api\Admin\ViolationReferrerController::class, 'stats']);

        // CRUD routes
        Route::get('/', [\App\Http\Controllers\Api\Admin\ViolationReferrerController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\Admin\ViolationReferrerController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\Api\Admin\ViolationReferrerController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\ViolationReferrerController::class, 'destroy']);
    });
});

// ✅ Public API: Get active payment method templates (for user dropdown)
Route::middleware('auth:sanctum')->get('/payment-templates', [\App\Http\Controllers\Api\SuperAdmin\PaymentMethodTemplateController::class, 'getActive']);

// ✅ Public API: Get access settings (for landing page - no auth)
Route::get('/settings/access', [\App\Http\Controllers\Api\SuperAdmin\GeneralSettingsController::class, 'getAccessSettings']);

// 🔐 Public API: Verify backdoor access code (for admin backdoor login)
Route::post('/verify-backdoor-code', [\App\Http\Controllers\Api\SuperAdmin\GeneralSettingsController::class, 'verifyBackdoorCode']);

// 🚧 Public API: Get maintenance status (for middleware check)
Route::get('/settings/maintenance', [\App\Http\Controllers\Api\SuperAdmin\GeneralSettingsController::class, 'getMaintenanceStatus']);

// 💱 Public API: Get currency rates (for frontend display conversion)
Route::get('/settings/currency-rates', [AdminSettingController::class, 'getCurrencyRates']);
