<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
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



// ✅ BREEZE ROUTES (auto-generated)
Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware('guest')
    ->name('register');

Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest')
    ->name('login');

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



Route::post('/auth/google/callback', [SocialiteController::class, 'handleGoogleCallback']);


// -------------------- SHORTLINK (Public / Hybrid) --------------------
Route::middleware('throttle:10,1')->post('/links', [LinkController::class, 'store']); // guest & user
Route::middleware(['auth:sanctum', 'throttle:10,1'])->post('/links/mass', [LinkController::class, 'massStore']); // user only
Route::get('/links/{code}', [LinkController::class, 'show']);
Route::post('/links/{code}/activate-token', [LinkController::class, 'activateToken']); // Aktivasi token
Route::post('/links/{code}/continue', [LinkController::class, 'continue']);
Route::get('/check-alias/{alias}', [LinkController::class, 'checkAlias']);
Route::middleware('auth:sanctum')->patch('/links/{id}/toggle-status', [LinkController::class, 'toggleStatus']);
Route::post('/report', [ReportController::class, 'store']);

// -------------------- AUTH PROTECTED (Only Logged In) --------------------
Route::middleware(['auth:sanctum', 'is_banned'])->group(function () {
    Route::get('/links', [LinkController::class, 'index']);
    Route::put('/links/{code}', [LinkController::class, 'update']);

    // ✅ Change Password
    Route::put('/user/password', [ProfileController::class, 'updatePassword']);
    Route::put('/user/profile', [ProfileController::class, 'updateProfile']);
    Route::get('/user/login-history', [LoginHistoryController::class, 'index']);
    Route::get('/user/levels', [UserLevelController::class, 'index']);



    // Statistik & kontrol link (hanya user)
    Route::get('/dashboard/overview', [DashboardController::class, 'overview']);
    Route::get('/dashboard/trends', [DashboardController::class, 'trends']);


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

    Route::get('/links', [AdminLinkController::class, 'index']);
    Route::post('/links/bulk-ban', [AdminLinkController::class, 'bulkBan']); // <-- New Route
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

    // Report Abuse
    Route::get('/reports', [AdminReportController::class, 'index']);
    Route::delete('/reports/{id}', [AdminReportController::class, 'destroy']);

    // Level Management
    Route::get('/levels', [AdminLevelController::class, 'index']);
    Route::put('/levels/{id}', [AdminLevelController::class, 'update']);

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
    Route::get('/admins', [\App\Http\Controllers\Api\SuperAdmin\SuperAdminController::class, 'index']);
    Route::post('/admins', [\App\Http\Controllers\Api\SuperAdmin\SuperAdminController::class, 'store']);
    Route::put('/admins/{id}', [\App\Http\Controllers\Api\SuperAdmin\SuperAdminController::class, 'update']);
    Route::delete('/admins/{id}', [\App\Http\Controllers\Api\SuperAdmin\SuperAdminController::class, 'destroy']);

    // ✅ Withdrawal Logs
    Route::get('/withdrawal-logs', [\App\Http\Controllers\Api\SuperAdmin\SuperAdminController::class, 'getWithdrawalLogs']);
});
