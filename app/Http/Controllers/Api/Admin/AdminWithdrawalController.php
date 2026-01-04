<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Payout;
use App\Http\Controllers\Controller;

use App\Models\User;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Notifications\GeneralNotification;
use App\Models\Setting;
use App\Http\Controllers\Api\UserStatsController;

class AdminWithdrawalController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $status = $request->input('status'); // Ambil filter status
        $search = $request->input('search'); // Ambil keyword pencarian
        $sort = $request->input('sort', 'newest'); // Sort: newest/oldest
        $level = $request->input('level'); // User level filter

        // Mulai Query - include processedBy relation for admin name
        $query = Payout::with(['user', 'paymentMethod', 'processedBy:id,name']);

        // 1. Filter Berdasarkan Status
        if ($status && in_array($status, ['pending', 'approved', 'paid', 'rejected'])) {
            $query->where('payouts.status', $status);
        }

        // 2. Fitur Pencarian (Search)
        if ($search) {
            $query->where(function ($q) use ($search) {
                // Cari berdasarkan Transaction ID
                $q->where('payouts.transaction_id', 'like', "%{$search}%")
                    // ATAU Cari berdasarkan Nama/Email User
                    ->orWhereHas('user', function ($u) use ($search) {
                        $u->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    // ATAU Cari berdasarkan Nomor Rekening
                    ->orWhereHas('paymentMethod', function ($pm) use ($search) {
                        $pm->where('account_number', 'like', "%{$search}%")
                            ->orWhere('account_name', 'like', "%{$search}%");
                    });
            });
        }

        // 3. Apply Sorting
        if ($level && $level !== 'all') {
            // Sort by user level - join to levels table
            $query->join('users', 'payouts.user_id', '=', 'users.id')
                ->leftJoin('levels', 'users.current_level_id', '=', 'levels.id')
                ->select('payouts.*');

            if ($level === 'highest') {
                // Sort by level from HIGHEST to LOWEST (mythic first)
                // levels.min_total_earnings DESC = higher levels first
                // NULL levels (no level) go last
                $query->orderByRaw('COALESCE(levels.min_total_earnings, 0) DESC');
            } else {
                // 'lowest' - Sort by level from LOWEST to HIGHEST (beginner first)
                // levels.min_total_earnings ASC = lower levels first
                // NULL levels (no level) go first (treated as beginner)
                $query->orderByRaw('COALESCE(levels.min_total_earnings, 0) ASC');
            }

            // Secondary sort by date
            if ($sort === 'oldest') {
                $query->orderBy('payouts.created_at', 'asc');
            } else {
                $query->orderBy('payouts.created_at', 'desc');
            }
        } else {
            // No level sort - just sort by date
            if ($sort === 'oldest') {
                $query->oldest();
            } else {
                $query->latest();
            }
        }

        // Eksekusi query dengan pagination
        $withdrawals = $query->paginate($perPage);

        return $this->paginatedResponse($withdrawals, 'Withdrawals retrieved');
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected,paid',
            'notes' => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();

        try {
            $payout = Payout::with('user')->lockForUpdate()->findOrFail($id);
            $user = $payout->user;

            $oldStatus = $payout->status;
            $newStatus = $request->status;

            // Total Uang yang "ditahan" di pending_balance
            $totalLockedAmount = $payout->amount + $payout->fee;

            // 🔥🔥 RACE CONDITION CHECK 🔥🔥
            // Check 1: Jika withdrawal sudah BUKAN pending dan ada processed_by dari admin lain
            // Ini menangani kasus: Admin B coba approve tapi Admin A sudah approve duluan
            if ($oldStatus !== 'pending' && $payout->processed_by && $payout->processed_by != $request->user()->id) {
                $processor = User::find($payout->processed_by);
                $processorName = $processor ? $processor->name : 'Admin lain';

                DB::rollBack();
                return $this->errorResponse("Penarikan ini sudah diproses oleh {$processorName}. Status saat ini: {$oldStatus}.", 403);
            }

            // Check 2: Jika status saat ini APPROVED, pastikan yang memproses (paid/reject) adalah admin yang sama
            // Ini menangani kasus: Admin A approve, lalu Admin B coba pay/reject
            if ($oldStatus === 'approved') {
                if ($payout->processed_by && $payout->processed_by != $request->user()->id) {
                    $processor = User::find($payout->processed_by);
                    $processorName = $processor ? $processor->name : 'Admin lain';

                    DB::rollBack();
                    return $this->errorResponse("Penarikan ini sedang diproses oleh {$processorName}. Anda tidak dapat mengubah statusnya.", 403);
                }
            }

            // Update Payout
            $payout->update([
                'status' => $newStatus,
                'notes' => $request->notes ?? $payout->notes,
                'processed_by' => $request->user()->id, // ✅ Log siapa yang memproses
            ]);

            switch ($newStatus) {
                case 'approved':
                    // Status disetujui, tapi belum dibayar
                    // Commission akan diberikan saat status = 'paid'
                    break;

                case 'rejected':
                    // KEMBALIKAN SALDO PENUH (Nominal + Fee)
                    if ($oldStatus === 'pending' || $oldStatus === 'approved') {
                        // Cek apakah saldo pending mencukupi untuk dikembalikan
                        if ($user->pending_balance >= $totalLockedAmount) {
                            $user->decrement('pending_balance', $totalLockedAmount);
                            $user->increment('balance', $totalLockedAmount);
                        } else {
                            // Fallback jika pending balance tidak sinkron (jarang terjadi)
                            Log::warning("Pending balance user {$user->id} tidak cukup untuk refund payout #{$payout->id}");
                            $user->increment('balance', $totalLockedAmount);
                        }
                    }
                    break;

                case 'paid':
                    // Kurangi pending balance secara permanen (Uang keluar dari sistem)
                    if ($user->pending_balance >= $totalLockedAmount) {
                        $user->decrement('pending_balance', $totalLockedAmount);
                    }

                    // 🔥🔥 REFERRAL COMMISSION - Diberikan saat PAID (uang sudah dikirim) 🔥🔥
                    if ($user->referred_by) {
                        $referrer = User::find($user->referred_by);
                        if ($referrer) {
                            // 🔥 DYNAMIC REFERRAL PERCENTAGE 🔥
                            $setting = Setting::where('key', 'referral_settings')->first();
                            if (!$setting) {
                                $setting = Setting::where('key', 'referral_percentage')->first();
                            }
                            $percentage = $setting ? ($setting->value['percentage'] ?? 10) : 10;

                            $commissionAmount = $payout->amount * ($percentage / 100);

                            $referrer->increment('balance', $commissionAmount);

                            Transaction::create([
                                'user_id' => $referrer->id,
                                'type' => 'referral_commission',
                                'amount' => $commissionAmount,
                                'description' => "Komisi $percentage% dari withdrawal user " . $user->name,
                                'reference_id' => $payout->id,
                            ]);
                        }
                    }
                    break;
            }

            // Kirim Notifikasi ke User
            $notifType = match ($newStatus) {
                'approved' => 'success',
                'rejected' => 'danger',
                'paid' => 'success',
                default => 'info',
            };

            $notifMessage = "Your withdrawal request {$payout->transaction_id} has been {$newStatus}.";

            // Add rejection reason on a new line for better visibility
            if ($newStatus === 'rejected' && $request->notes) {
                $notifMessage .= "\n\nReason: {$request->notes}";
            } elseif ($request->notes) {
                $notifMessage .= " Note: {$request->notes}";
            }

            // 🔥🔥 Fetch Expiry Setting 🔥🔥
            $setting = Setting::where('key', 'notification_settings')->first();
            $expiryDays = $setting ? ($setting->value['expiry_days'] ?? 30) : 30;
            $expiresAt = now()->addDays($expiryDays);

            $user->notify(new GeneralNotification(
                'Withdrawal Status Update',
                $notifMessage,
                $notifType,
                'PAYMENT', // URL frontend user
                $expiresAt // ✅ Expiration Date
            ));

            // 🔄 Clear user's header stats cache so balance updates immediately
            UserStatsController::clearCache($user->id);

            DB::commit();

            return $this->successResponse($payout, 'Status penarikan berhasil diperbarui.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Error: ' . $e->getMessage(), 500);
        }
    }

    public function getDailyStats(Request $request)
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();
        $yesterdayEnd = now()->startOfDay();

        // 1. Paid Today Stats
        $paidToday = Payout::where('status', 'paid')
            ->where('updated_at', '>=', $today)
            ->selectRaw('COALESCE(SUM(amount), 0) as amount, COUNT(*) as count')
            ->first();

        // 2. Paid Yesterday (for trend calculation)
        $paidYesterday = Payout::where('status', 'paid')
            ->whereBetween('updated_at', [$yesterday, $yesterdayEnd])
            ->selectRaw('COUNT(DISTINCT user_id) as users')
            ->first();

        // 3. Unique Users Paid Today
        $usersPaidToday = Payout::where('status', 'paid')
            ->where('updated_at', '>=', $today)
            ->distinct('user_id')
            ->count('user_id');

        // 4. Unique Users Paid Yesterday
        $usersPaidYesterday = $paidYesterday->users ?? 0;

        // 5. Calculate Trend
        $trend = 0;
        if ($usersPaidYesterday > 0) {
            $trend = round((($usersPaidToday - $usersPaidYesterday) / $usersPaidYesterday) * 100, 1);
        } elseif ($usersPaidToday > 0) {
            $trend = 100;
        }

        // 6. Highest Withdrawal (All Time)
        $highestAllTime = Payout::with('user:id,name')
            ->where('status', 'paid')
            ->orderByDesc('amount')
            ->first();

        return $this->successResponse([
            'paid_today' => [
                'amount' => (float) ($paidToday->amount ?? 0),
                'count' => (int) ($paidToday->count ?? 0),
            ],
            'highest_withdrawal' => [
                'amount' => (float) ($highestAllTime->amount ?? 0),
                'user' => $highestAllTime->user?->name ?? 'N/A',
            ],
            'total_users_paid' => [
                'count' => $usersPaidToday,
                'trend' => $trend,
            ],
        ], 'Daily stats retrieved');
    }
}
