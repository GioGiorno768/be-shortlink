<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Setting;
use App\Models\AdRate; // Moved here
use App\Models\Link;
use Illuminate\Support\Facades\Cache;

class AdminSettingController extends Controller
{
    // use App\Models\AdRate; // Removed from here

    public function getAdRates()
    {
        // Ambil semua rates dari database
        $rates = AdRate::all();

        // Jika kosong, buat default GLOBAL
        if ($rates->isEmpty()) {
            AdRate::create([
                'country' => 'GLOBAL',
                'rates' => [
                    'level_1' => 0.05,
                    'level_2' => 0.07,
                    'level_3' => 0.10,
                    'level_4' => 0.15,
                ]
            ]);
            $rates = AdRate::all();
        }

        return $this->successResponse($rates, 'Ad rates retrieved');
    }

    /**
     * Update rates untuk negara tertentu
     */
    public function updateAdRates(Request $request)
    {
        $request->validate([
            'country' => 'required|string',
            'rates' => 'required|array',
        ]);

        $rate = AdRate::updateOrCreate(
            ['country' => $request->country],
            ['rates' => $request->rates]
        );

        Cache::forget('ad_rates_all'); // Clear cache

        return $this->successResponse($rate, 'Tarif iklan berhasil diperbarui.');
    }

    /**
     * Hapus rate negara (kecuali GLOBAL)
     */
    public function deleteCountryRate($country)
    {
        if ($country === 'GLOBAL') {
            return $this->errorResponse('Tidak bisa menghapus tarif GLOBAL.', 403);
        }

        $rate = AdRate::where('country', $country)->first();
        if (!$rate) {
            return $this->errorResponse('Negara tidak ditemukan.', 404);
        }

        $rate->delete();
        Cache::forget('ad_rates_all');

        return $this->successResponse(null, "Tarif untuk $country berhasil dihapus.");
    }

    /**
     * Tambah Level Iklan Baru (Global & Semua Negara)
     */
    public function addAdLevelColumn()
    {
        $global = AdRate::where('country', 'GLOBAL')->first();
        $currentRates = $global->rates ?? [];
        
        // Cari level tertinggi saat ini
        $maxLevel = 0;
        foreach (array_keys($currentRates) as $key) {
            if (preg_match('/level_(\d+)/', $key, $matches)) {
                $maxLevel = max($maxLevel, (int)$matches[1]);
            }
        }
        
        $newLevel = $maxLevel + 1;
        $newKey = "level_{$newLevel}";

        // Tambahkan level baru ke semua negara
        $allRates = AdRate::all();
        foreach ($allRates as $rate) {
            $rates = $rate->rates ?? [];
            // Default value logic: copy from previous level or 0
            $prevKey = "level_" . ($newLevel - 1);
            $defaultValue = isset($rates[$prevKey]) ? $rates[$prevKey] : 0.01;
            
            $rates[$newKey] = $defaultValue;
            $rate->update(['rates' => $rates]);
        }
        
        Cache::forget('ad_rates_all');
        return $this->successResponse(['new_level' => $newKey], "Level $newLevel berhasil ditambahkan.");
    }

    /**
     * Hapus Level Iklan (Global & Semua Negara)
     */
    public function deleteAdLevelColumn($key)
    {
        // 1. Parse Level Number
        if (!preg_match('/level_(\d+)/', $key, $matches)) {
            return $this->errorResponse('Format level tidak valid.', 400);
        }
        $levelToDelete = (int)$matches[1];

        // 2. Prevent Deleting Level 1
        if ($levelToDelete <= 1) {
            return $this->errorResponse('Level 1 tidak dapat dihapus.', 403);
        }

        // 3. Downgrade Links (Level X -> Level X-1)
        // Cari semua link yang menggunakan level ini
        $affectedLinksCount = Link::where('ad_level', $levelToDelete)->count();
        
        if ($affectedLinksCount > 0) {
            Link::where('ad_level', $levelToDelete)
                ->update(['ad_level' => $levelToDelete - 1]);
        }

        // 4. Remove Level from Ad Rates
        $allRates = AdRate::all();
        foreach ($allRates as $rate) {
            $rates = $rate->rates ?? [];
            if (isset($rates[$key])) {
                unset($rates[$key]);
                $rate->update(['rates' => $rates]);
            }
        }
        
        Cache::forget('ad_rates_all');
        
        return $this->successResponse(null, "Level $key berhasil dihapus. $affectedLinksCount link telah diturunkan ke level " . ($levelToDelete - 1) . ".");
    }

    /**
     * Tambah negara baru (Alias untuk updateAdRates sebenarnya, tapi untuk konsistensi routing)
     */
    public function storeAdLevel(Request $request)
    {
        return $this->updateAdRates($request);
    }

     /**
     * Ambil settingan minimal penarikan
     */
    public function getWithdrawalSettings()
    {
        $setting = Setting::where('key', 'withdrawal_settings')->first();
        
        // Default jika belum diset di database
        $default = [
            'min_amount' => 1.00,
            'max_amount' => 0, // 0 means unlimited
            'limit_count' => 0, // 0 means unlimited
            'limit_days' => 1,
        ];

        return $this->successResponse($setting ? array_merge($default, $setting->value) : $default, 'Withdrawal settings retrieved');
    }

    /**
     * Update settingan minimal penarikan
     */
    public function updateWithdrawalSettings(Request $request)
    {
        $request->validate([
            'min_amount' => 'required|numeric|min:0.1',
            'max_amount' => 'nullable|numeric|min:0',
            'limit_count' => 'nullable|integer|min:0',
            'limit_days' => 'nullable|integer|min:1',
        ]);

        $data = [
            'min_amount' => $request->min_amount,
            'max_amount' => $request->max_amount ?? 0,
            'limit_count' => $request->limit_count ?? 0,
            'limit_days' => $request->limit_days ?? 1,
        ];

        Setting::updateOrCreate(
            ['key' => 'withdrawal_settings'],
            ['value' => $data]
        );

        return $this->successResponse($data, 'Pengaturan penarikan berhasil diperbarui.');
    }

     /**
     * Ambil Settingan Biaya Admin Bank
     */
    public function getBankFees()
    {
        $setting = Setting::where('key', 'bank_fees')->first();

        // Default value jika database kosong
        $defaults = [
            'BCA'     => 6000,
            'BRI'     => 4000,
            'MANDIRI' => 4000,
            'BNI'     => 4000,
            'JAGO'    => 0,
            'DANA'    => 1000,
            'OVO'     => 1500,
            'GOPAY'   => 1500,
            'OTHERS'  => 6500 // Fallback untuk bank lain
        ];

        return $this->successResponse($setting ? $setting->value : $defaults, 'Bank fees retrieved');
    }

    /**
     * Update Settingan Biaya Admin Bank
     */
    public function updateBankFees(Request $request)
    {
        $request->validate([
            'fees' => 'required|array',
            'fees.*' => 'numeric|min:0', // Validasi isi array harus angka
        ]);

        // Simpan ke database sebagai JSON
        Setting::updateOrCreate(
            ['key' => 'bank_fees'],
            ['value' => $request->fees]
        );

        return $this->successResponse($request->fees, 'Biaya admin bank berhasil diperbarui.');
    }
    /**
     * Ambil Settingan Registrasi
     */
    public function getRegistrationSettings()
    {
        $setting = Setting::where('key', 'registration_settings')->first();

        // Default: Enabled = true
        $defaults = [
            'enabled' => true,
            'message' => 'Registration is currently closed.',
        ];

        return $this->successResponse($setting ? $setting->value : $defaults, 'Registration settings retrieved');
    }

    /**
     * Update Settingan Registrasi
     */
    public function updateRegistrationSettings(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
            'message' => 'nullable|string',
        ]);

        $data = [
            'enabled' => $request->enabled,
            'message' => $request->message ?? 'Registration is currently closed.',
        ];

        Setting::updateOrCreate(
            ['key' => 'registration_settings'],
            ['value' => $data]
        );

        return $this->successResponse($data, 'Registration settings updated successfully.');
    }
    /**
     * Ambil Settingan Persentase Referral
     */
    public function getReferralSettings()
    {
        $setting = Setting::where('key', 'referral_percentage')->first();

        // Default: 10%
        $defaults = [
            'percentage' => 10,
        ];

        return $this->successResponse($setting ? $setting->value : $defaults, 'Referral settings retrieved');
    }

    /**
     * Update Settingan Persentase Referral
     */
    public function updateReferralSettings(Request $request)
    {
        $request->validate([
            'percentage' => 'required|numeric|min:0|max:100',
        ]);

        $data = [
            'percentage' => $request->percentage,
        ];

        Setting::updateOrCreate(
            ['key' => 'referral_percentage'],
            ['value' => $data]
        );

        return $this->successResponse($data, 'Referral percentage updated successfully.');
    }

    /**
     * Ambil Settingan Notifikasi (Default Expiry)
     */
    public function getNotificationSettings()
    {
        $setting = Setting::where('key', 'notification_settings')->first();

        // Default: 30 hari
        $defaults = [
            'expiry_days' => 30,
        ];

        return $this->successResponse($setting ? $setting->value : $defaults, 'Notification settings retrieved');
    }

    /**
     * Update Settingan Notifikasi
     */
    public function updateNotificationSettings(Request $request)
    {
        $request->validate([
            'expiry_days' => 'required|integer|min:1|max:365',
        ]);

        $data = [
            'expiry_days' => $request->expiry_days,
        ];

        Setting::updateOrCreate(
            ['key' => 'notification_settings'],
            ['value' => $data]
        );

        return $this->successResponse($data, 'Notification settings updated successfully.');
    }
}
