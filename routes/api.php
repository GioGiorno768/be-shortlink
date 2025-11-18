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

 
// // -------------------- AUTH --------------------
// Route::post('/register', [AuthController::class, 'register']);
// Route::post('/login', [AuthController::class, 'login']);


// Route::get('/csrf-cookie', function () {
//     return response()->json(['csrf' => csrf_token()]);
// });

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

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [UserNotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [UserNotificationController::class, 'markAsRead']);
});

Route::post('/auth/google/callback', [SocialiteController::class, 'handleGoogleCallback']);


// -------------------- SHORTLINK (Public / Hybrid) --------------------
Route::middleware('throttle:10,1')->post('/links', [LinkController::class, 'store']); // guest & user
Route::get('/links/{code}', [LinkController::class, 'show']);
Route::post('/links/{code}/continue', [LinkController::class, 'continue']);
Route::get('/check-alias/{alias}', [LinkController::class, 'checkAlias']);
Route::middleware('auth:sanctum')->patch('/links/{id}/toggle-status', [LinkController::class, 'toggleStatus']);


// Route::middleware('auth:sanctum')->group(function () {
//     Route::post('/payment-methods', [PaymentMethodController::class, 'store']);
// });

// Route::get('/verify-payment/{token}', [PaymentMethodController::class, 'verify'])
//     ->name('payment.verify');


// -------------------- AUTH PROTECTED (Only Logged In) --------------------
Route::middleware('auth:sanctum')->group(function () {
    // Route::post('/logout', [AuthController::class, 'logout']);
    // Route::post('/links', [LinkController::class, 'store']); // guest & user
    Route::get('/links', [LinkController::class, 'index']);
    Route::put('/links/{code}', [LinkController::class, 'update']);
    // Route::patch('/links/{id}/toggle-status', [LinkController::class, 'toggleStatus']);



    // Statistik & kontrol link (hanya user)
    Route::get('/links/{code}/stats', [LinkController::class, 'stats']);
    Route::get('/stats', [StatsController::class, 'index']);
    Route::get('/user/dashboard', [StatsController::class, 'dashboard']);
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
    Route::delete('/withdrawals/{id}', [PayoutController::class, 'cancel']);
    Route::delete('/withdrawals/delete/{id}', [PayoutController::class, 'destroy']);



});

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {
    Route::get('/withdrawals', [AdminWithdrawalController::class, 'index']);
    Route::put('/withdrawals/{id}/status', [AdminWithdrawalController::class, 'updateStatus']);
    Route::get('/dashboard/overview', [AdminDashboardController::class, 'overview']);
    Route::get('/dashboard/trends', [AdminDashboardController::class, 'trends']);

    Route::get('/links', [AdminLinkController::class, 'index']);
    Route::put('/links/{id}', [AdminLinkController::class, 'update']);
    Route::delete('/links/{id}', [AdminLinkController::class, 'destroy']);
});


// admin push notification
Route::post('/admin/notify', function (Request $request) {
    $validated = $request->validate([
        'user_id' => 'nullable|exists:users,id',
        'title' => 'required|string',
        'message' => 'required|string',
        'type' => 'in:info,success,warning,danger'
    ]);

    // Kirim ke 1 user atau broadcast ke semua
    if ($validated['user_id']) {
        \App\Models\UserNotification::create($validated);
    } else {
        $users = \App\Models\User::pluck('id');
        foreach ($users as $uid) {
            \App\Models\UserNotification::create([
                'user_id' => $uid,
                'title' => $validated['title'],
                'message' => $validated['message'],
                'type' => $validated['type'] ?? 'info',
            ]);
        }
    }

    return response()->json(['message' => 'Notifikasi berhasil dikirim.']);
});
