<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payout;
use App\Models\Setting;
use App\Models\PaymentMethod; // Tambahkan Model Setting
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;



class PayoutController extends Controller
{
    /**
     * 🏦 Ajukan permintaan penarikan
     */
    public function store(Request $request)
    {
        // 1. Ambil settingan minimal withdraw dari database
        $wdSetting = Setting::where('key', 'withdrawal_settings')->first();
        $settings = $wdSetting ? $wdSetting->value : [];

        $minAmount = $settings['min_amount'] ?? 10000;
        $maxAmount = $settings['max_amount'] ?? 0;
        $limitCount = $settings['limit_count'] ?? 0;
        $limitDays = $settings['limit_days'] ?? 1;

        $request->validate([
            'payment_method_id' => 'required|exists:payment_methods,id',
            'amount' => 'required|numeric|min:' . $minAmount,
        ]);

        // CEK MAX AMOUNT
        if ($maxAmount > 0 && $request->amount > $maxAmount) {
            return $this->errorResponse('Nominal melebihi batas maksimal.', 422, ['amount' => ['Maksimal penarikan adalah Rp ' . number_format($maxAmount)]]);
        }

        $user = $request->user();

        // CEK FREKUENSI PENARIKAN
        if ($limitCount > 0) {
            $startDate = now()->subDays($limitDays);
            $recentPayouts = Payout::where('user_id', $user->id)
                ->where('created_at', '>=', $startDate)
                ->count();

            if ($recentPayouts >= $limitCount) {
                return $this->errorResponse('Batas frekuensi penarikan tercapai.', 422, ['frequency' => ["Anda hanya dapat melakukan penarikan {$limitCount} kali dalam {$limitDays} hari."]]);
            }
        }

        // 2. Ambil Metode Pembayaran & Fee-nya
        $paymentMethod = PaymentMethod::where('id', $request->payment_method_id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        // 💵 All monetary values are in USD (consistent storage)
        $requestAmount = $request->amount;        // Dalam USD (dari frontend)
        $adminFee = $paymentMethod->fee;          // Fee dalam USD (dari DB)
        $totalDeduction = $requestAmount + $adminFee;  // Total dalam USD

        // Exchange rate for error message display only
        $usdToIdrRate = 15800;

        // 3. Cek Saldo (Harus Cukup untuk Nominal + Fee) - Semua dalam USD
        if ($user->balance < $totalDeduction) {
            // Format error message dalam IDR untuk user readability
            $balanceIdr = $user->balance * $usdToIdrRate;
            $totalIdr = $totalDeduction * $usdToIdrRate;
            $feeIdr = $adminFee * $usdToIdrRate;
            return $this->errorResponse('Saldo tidak mencukupi.', 422, [
                'balance' => ['Saldo Anda Rp ' . number_format($balanceIdr) . '. Total yang dibutuhkan Rp ' . number_format($totalIdr) . ' (Termasuk biaya admin Rp ' . number_format($feeIdr) . ').']
            ]);
        }

        DB::beginTransaction();
        try {
            // 4. Potong Saldo User & Pindahkan ke Pending Balance
            // Kita lakukan 'lock' saldo sekarang agar tidak bisa ditarik double
            $user->decrement('balance', $totalDeduction);
            $user->increment('pending_balance', $totalDeduction);

            // 5. Buat Record Payout (semua dalam USD)
            $payout = Payout::create([
                'user_id' => $user->id,
                'payment_method_id' => $paymentMethod->id,
                'amount' => $requestAmount,   // Dalam USD
                'fee' => $adminFee,           // Dalam USD
                'status' => 'pending',
            ]);

            DB::commit();

            // Clear header stats cache so balance updates immediately
            \App\Http\Controllers\Api\UserStatsController::clearCache($user->id);

            return $this->successResponse([
                'id' => $payout->id,
                'amount_to_receive' => $payout->amount,
                'admin_fee' => $payout->fee,
                'total_deducted' => $payout->amount + $payout->fee,
                'status' => 'pending'
            ], 'Permintaan penarikan berhasil dibuat.', 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Terjadi kesalahan sistem.', 500);
        }
    }
    /**
     * 📄 Lihat riwayat penarikan user dengan filter & pagination
     * Query params:
     * - per_page: items per page (default 8)
     * - sort: 'newest' (default) or 'oldest'
     * - method: payment method name (e.g., 'OVO', 'BCA')
     * - search: search by transaction_id
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $perPage = $request->get('per_page', 8);
        $sort = $request->get('sort', 'newest');
        $method = $request->get('method');
        $search = $request->get('search');

        $query = Payout::where('user_id', $user->id)
            ->with('paymentMethod');

        // Filter by payment method
        if ($method && $method !== 'all') {
            $query->whereHas('paymentMethod', function ($q) use ($method) {
                $q->where('bank_name', $method);
            });
        }

        // Search by transaction_id
        if ($search) {
            $query->where('transaction_id', 'like', "%{$search}%");
        }

        // Apply sorting
        if ($sort === 'oldest') {
            $query->oldest();
        } else {
            $query->latest();
        }

        $payouts = $query->paginate($perPage);

        // Sertakan info min_withdrawal agar Frontend tahu batasnya
        $wdSetting = Setting::where('key', 'withdrawal_settings')->first();
        $settings = $wdSetting ? $wdSetting->value : [];

        return $this->successResponse([
            'balance' => $user->balance,
            'min_withdrawal' => $settings['min_amount'] ?? 10000,
            'max_withdrawal' => $settings['max_amount'] ?? 0,
            'limit_count' => $settings['limit_count'] ?? 0,
            'limit_days' => $settings['limit_days'] ?? 1,
            'payouts' => $payouts,
        ], 'Withdrawal history retrieved');
    }


    /**
     * 🔧 Admin: ubah status payout (approve / reject / paid)
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:approved,rejected,paid',
            'note' => 'nullable|string',
        ]);

        $payout = Payout::findOrFail($id);
        $oldStatus = $payout->status;

        // Jika status sebelumnya bukan rejected, dan admin me-reject sekarang
        // Kembalikan saldo ke user
        if ($request->status === 'rejected' && $oldStatus !== 'rejected') {
            $payout->user->increment('balance', $payout->amount);
            $payout->user->decrement('pending_balance', $payout->amount);
        }

        // Jika status berubah jadi Paid/Approved, kurangi pending balance (karena uang sudah keluar/final)
        if (($request->status === 'paid' || $request->status === 'approved') && $oldStatus === 'pending') {
            $payout->user->decrement('pending_balance', $payout->amount);
        }

        $payout->update([
            'status' => $request->status,
            'note' => $request->note,
        ]);

        Cache::forget("dashboard:{$payout->user_id}");

        return $this->successResponse(null, "Status penarikan diubah menjadi {$request->status}.");
    }


    public function cancel(Request $request, $id)
    {
        $user = Auth::user();

        // 1. Cari data payout yang statusnya masih 'pending' milik user tersebut
        $payout = Payout::where('user_id', $user->id)
            ->where('id', $id)
            ->where('status', 'pending')
            ->first();

        if (!$payout) {
            return $this->errorResponse('Penarikan tidak ditemukan atau sudah diproses.', 404);
        }

        DB::beginTransaction();
        try {
            // 2. HITUNG TOTAL REFUND (POKOK + FEE)
            // Ini adalah kunci perbaikannya. Kita harus kembalikan Fee juga.
            $totalRefund = $payout->amount + $payout->fee;

            // 3. Kembalikan Saldo ke User
            $user->increment('balance', $totalRefund);

            // 4. Kurangi Pending Balance (Bersihkan saldo tertahan)
            // Kita cek dulu biar tidak minus (walaupun secara logika pasti ada)
            if ($user->pending_balance >= $totalRefund) {
                $user->decrement('pending_balance', $totalRefund);
            } else {
                // Fallback jika terjadi ketidaksesuaian data lama
                $user->pending_balance = 0;
                $user->save();
            }

            // 5. Hapus data payout (Hard Delete)
            // Atau Anda bisa gunakan Soft Delete / Update status ke 'cancelled' jika ingin simpan history
            $payout->delete();

            DB::commit();

            // Clear header stats cache so balance updates immediately
            \App\Http\Controllers\Api\UserStatsController::clearCache($user->id);

            return $this->successResponse(null, 'Penarikan berhasil dibatalkan. Dana sebesar Rp ' . number_format($totalRefund, 0, ',', '.') . ' telah dikembalikan ke saldo utama.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Gagal membatalkan penarikan: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Hapus History (Jika status rejected/paid)
     * Method ini tidak mengembalikan saldo karena saldo sudah diproses di AdminWithdrawalController
     */
    public function destroy($id)
    {
        $user = Auth::user();

        // Hanya boleh hapus yang SUDAH SELESAI (paid/rejected) atau Cancelled
        // Yang 'pending' harus lewat fungsi cancel() di atas agar saldo balik.
        $payout = Payout::where('user_id', $user->id)
            ->where('id', $id)
            ->whereIn('status', ['paid', 'rejected', 'cancelled'])
            ->first();

        if (!$payout) {
            return $this->errorResponse('Data tidak ditemukan atau masih dalam proses (pending). Gunakan fitur Cancel untuk membatalkan pending.', 400);
        }

        $payout->delete();

        return $this->successResponse(null, 'Riwayat penarikan berhasil dihapus.');
    }
}
