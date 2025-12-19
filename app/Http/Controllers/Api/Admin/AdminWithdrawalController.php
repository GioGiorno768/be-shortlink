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

class AdminWithdrawalController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $status = $request->input('status'); // Ambil filter status
        $search = $request->input('search'); // Ambil keyword pencarian

        // Mulai Query
        $query = Payout::with(['user', 'paymentMethod']);

        // 1. Filter Berdasarkan Status
        if ($status && in_array($status, ['pending', 'approved', 'paid', 'rejected'])) {
            $query->where('status', $status);
        }

        // 2. Fitur Pencarian (Search)
        if ($search) {
            $query->where(function ($q) use ($search) {
                // Cari berdasarkan Transaction ID
                $q->where('transaction_id', 'like', "%{$search}%")
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

        // Eksekusi query dengan pagination
        $withdrawals = $query->latest()->paginate($perPage);

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

            // 🔥🔥 LOCKING MECHANISM 🔥🔥
            // Jika status saat ini APPROVED, pastikan yang memproses adalah admin yang sama
            if ($oldStatus === 'approved') {
                if ($payout->processed_by && $payout->processed_by != $request->user()->id) {
                    // Ambil nama admin yang mengunci (opsional, butuh relation processedBy di model Payout)
                    $processor = User::find($payout->processed_by);
                    $processorName = $processor ? $processor->name : 'Admin lain';

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
                            $setting = Setting::where('key', 'referral_percentage')->first();
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
            if ($request->notes) {
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
                '/user/withdrawals', // URL frontend user
                $expiresAt // ✅ Expiration Date
            ));

            DB::commit();

            return $this->successResponse($payout, 'Status penarikan berhasil diperbarui.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Error: ' . $e->getMessage(), 500);
        }
    }

    public function getDailyStats(Request $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = Payout::where('status', 'paid')
            ->selectRaw('DATE(updated_at) as date, COUNT(*) as total_count, SUM(amount) as total_amount')
            ->groupBy('date')
            ->orderBy('date', 'desc');

        if ($startDate) {
            $query->whereDate('updated_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->whereDate('updated_at', '<=', $endDate);
        }

        $stats = $query->get();

        return $this->successResponse($stats, 'Daily stats retrieved');
    }
}
