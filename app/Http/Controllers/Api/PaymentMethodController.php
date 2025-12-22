<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\PaymentMethodVerificationMail;

class PaymentMethodController extends Controller
{
    /**
     * 🧾 List semua metode pembayaran user
     */
    public function index()
    {
        $methods = auth()->user()->paymentMethods()->get();
        return $this->successResponse($methods, 'Payment methods retrieved');
    }

    /**
     * Helper Private untuk Mendapatkan Fee dari Setting
     * NOTE: Settings store fees in IDR, but we store in USD for consistency
     */
    private function getFeeForBank($bankName)
    {
        $setting = Setting::where('key', 'bank_fees')->first();

        // Default hardcode jika setting belum dibuat admin (dalam IDR)
        $fees = $setting ? $setting->value : ['OTHERS' => 6500];

        $bankKey = strtoupper($bankName); // Ubah ke huruf kapital: "bca" -> "BCA"

        // Get fee in IDR from settings
        $feeIdr = $fees[$bankKey] ?? ($fees['OTHERS'] ?? 6500);

        // Convert to USD for storage (same rate as PayoutController)
        $usdToIdrRate = 15800;
        $feeUsd = round($feeIdr / $usdToIdrRate, 4);

        return $feeUsd;
    }

    public function store(Request $request)
    {
        $request->validate([
            'method_type' => 'required|in:bank_transfer,ewallet',
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'bank_name' => 'required|string|max:255',
        ]);

        $user = Auth::user();

        // Hitung Fee Otomatis
        $fee = $this->getFeeForBank($request->bank_name);

        $payment = PaymentMethod::create([
            'user_id' => $user->id,
            'method_type' => $request->method_type,
            'account_name' => $request->account_name,
            'account_number' => $request->account_number,
            'bank_name' => strtoupper($request->bank_name), // Simpan format kapital
            'fee' => $fee, // Simpan fee
            'is_verified' => true,
        ]);

        return $this->successResponse($payment, 'Metode pembayaran berhasil ditambahkan.', 201);
    }

    public function update(Request $request, $id)
    {
        $user = Auth::user();

        // Cek apakah metode ini sedang dipakai untuk withdrawal pending
        if ($user->payouts()->where('payment_method_id', $id)->where('status', 'pending')->exists()) {
            return $this->errorResponse('Tidak bisa mengubah rekening yang sedang dalam proses penarikan.', 400);
        }

        $request->validate([
            'method_type' => 'required|in:bank_transfer,ewallet',
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'bank_name' => 'required|string|max:255',
        ]);

        $paymentMethod = $user->paymentMethods()->findOrFail($id);

        // Hitung Fee Baru (jika bank berubah)
        $newFee = $this->getFeeForBank($request->bank_name);

        $paymentMethod->update([
            'method_type' => $request->method_type,
            'account_name' => $request->account_name,
            'account_number' => $request->account_number,
            'bank_name' => strtoupper($request->bank_name),
            'fee' => $newFee, // Update fee
        ]);

        return $this->successResponse($paymentMethod, 'Metode pembayaran diperbarui.');
    }

    /**
     * 🧩 Set sebagai default
     */
    public function setDefault($id)
    {
        $user = auth()->user();

        $user->paymentMethods()->update(['is_default' => false]);
        $method = $user->paymentMethods()->findOrFail($id);
        $method->update(['is_default' => true]);

        return $this->successResponse(null, 'Default payment method updated');
    }



    public function destroy($id)
    {
        $method = auth()->user()->paymentMethods()->findOrFail($id);
        $method->delete();

        return $this->successResponse(null, 'Payment method deleted');
    }
}
