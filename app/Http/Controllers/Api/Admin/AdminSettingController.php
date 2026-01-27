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
        Cache::forget('app_ad_cpc_rates'); // Clear CPC rates cache in LinkController

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
        Cache::forget('app_ad_cpc_rates');

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
        Cache::forget('app_ad_cpc_rates');
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
        Cache::forget('app_ad_cpc_rates');

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
     * Ambil Settingan Referral (Commission + Signup Bonus + Anti-Fraud)
     */
    public function getReferralSettings()
    {
        $setting = Setting::where('key', 'referral_settings')->first();

        // Default values
        $defaults = [
            'percentage' => 10,                    // Commission rate (% dari withdrawal referred user)
            'signup_bonus' => 0,                   // Bonus untuk user baru (dalam USD)
            'max_accounts_per_ip' => 2,            // Max accounts allowed per IP
            'fingerprint_check_enabled' => true,   // Enable/disable fingerprint check
            'ip_limit_enabled' => true,            // Enable/disable IP limit check
        ];

        // Merge dengan existing setting
        $data = $setting ? array_merge($defaults, $setting->value) : $defaults;

        // Also check legacy referral_percentage for backwards compatibility
        if (!$setting) {
            $legacySetting = Setting::where('key', 'referral_percentage')->first();
            if ($legacySetting) {
                $data['percentage'] = $legacySetting->value['percentage'] ?? 10;
            }
        }

        return $this->successResponse($data, 'Referral settings retrieved');
    }

    /**
     * Update Settingan Referral
     */
    public function updateReferralSettings(Request $request)
    {
        $request->validate([
            'percentage' => 'required|numeric|min:0|max:100',
            'signup_bonus' => 'nullable|numeric|min:0',
            'max_accounts_per_ip' => 'nullable|integer|min:1|max:100',
            'fingerprint_check_enabled' => 'nullable|boolean',
            'ip_limit_enabled' => 'nullable|boolean',
        ]);

        $data = [
            'percentage' => $request->percentage,
            'signup_bonus' => $request->signup_bonus ?? 0,
            'max_accounts_per_ip' => $request->max_accounts_per_ip ?? 2,
            'fingerprint_check_enabled' => $request->fingerprint_check_enabled ?? true,
            'ip_limit_enabled' => $request->ip_limit_enabled ?? true,
        ];

        Setting::updateOrCreate(
            ['key' => 'referral_settings'],
            ['value' => $data]
        );

        // Clear cache if exists
        Cache::forget('referral_settings');

        return $this->successResponse($data, 'Referral settings updated successfully.');
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

    /**
     * Ambil Settingan Self-Click
     */
    public function getSelfClickSettings()
    {
        $setting = Setting::where('key', 'self_click')->first();

        // Default settings
        $defaults = [
            'enabled' => true,
            'cpc_percentage' => 30,
            'daily_limit' => 1,
        ];

        // Clear cache when fetching to ensure fresh data
        Cache::forget('self_click_settings');

        return $this->successResponse($setting ? $setting->value : $defaults, 'Self-click settings retrieved');
    }

    /**
     * Update Settingan Self-Click
     */
    public function updateSelfClickSettings(Request $request)
    {
        $request->validate([
            'enabled' => 'required|boolean',
            'cpc_percentage' => 'required|numeric|min:0|max:100',
            'daily_limit' => 'required|integer|min:1|max:100',
        ]);

        $data = [
            'enabled' => $request->enabled,
            'cpc_percentage' => $request->cpc_percentage,
            'daily_limit' => $request->daily_limit,
        ];

        Setting::updateOrCreate(
            ['key' => 'self_click'],
            ['value' => $data]
        );

        // Clear cache so new settings take effect
        Cache::forget('self_click_settings');

        return $this->successResponse($data, 'Self-click settings updated successfully.');
    }

    /**
     * Ambil Settingan Link (Token Duration, dll)
     */
    public function getLinkSettings()
    {
        $setting = Setting::where('key', 'link_settings')->first();

        // Default settings
        $defaults = [
            'min_wait_seconds' => 12,
            'expiry_seconds' => 180,
            'mass_link_limit' => 20,
            'guest_link_limit' => 3, // Max links for guest (not logged in)
            'guest_link_limit_days' => 1, // Per X days
        ];

        // Clear cache when fetching to ensure fresh data
        Cache::forget('link_settings');

        return $this->successResponse($setting ? $setting->value : $defaults, 'Link settings retrieved');
    }

    /**
     * Update Settingan Link
     */
    public function updateLinkSettings(Request $request)
    {
        $request->validate([
            'min_wait_seconds' => 'required|integer|min:1|max:60',
            'expiry_seconds' => 'required|integer|min:60|max:600',
            'mass_link_limit' => 'required|integer|min:1|max:100',
            'guest_link_limit' => 'required|integer|min:0|max:50',
            'guest_link_limit_days' => 'required|integer|min:1|max:30',
        ]);

        $data = [
            'min_wait_seconds' => $request->min_wait_seconds,
            'expiry_seconds' => $request->expiry_seconds,
            'mass_link_limit' => $request->mass_link_limit,
            'guest_link_limit' => $request->guest_link_limit,
            'guest_link_limit_days' => $request->guest_link_limit_days,
        ];

        Setting::updateOrCreate(
            ['key' => 'link_settings'],
            ['value' => $data]
        );

        // Clear cache so new settings take effect
        Cache::forget('link_settings');

        return $this->successResponse($data, 'Link settings updated successfully.');
    }

    /**
     * Get Currency Exchange Rates
     */
    public function getCurrencyRates()
    {
        $setting = Setting::where('key', 'currency_rates')->first();

        // Default currencies
        $defaults = [
            'currencies' => [
                ['code' => 'USD', 'name' => 'US Dollar', 'flag' => 'us', 'symbol' => '$', 'rate' => 1],
                ['code' => 'IDR', 'name' => 'Indonesian Rupiah', 'flag' => 'id', 'symbol' => 'Rp', 'rate' => 16000],
                ['code' => 'EUR', 'name' => 'Euro', 'flag' => 'eu', 'symbol' => '€', 'rate' => 0.92],
                ['code' => 'GBP', 'name' => 'British Pound', 'flag' => 'gb', 'symbol' => '£', 'rate' => 0.79],
                ['code' => 'MYR', 'name' => 'Malaysian Ringgit', 'flag' => 'my', 'symbol' => 'RM', 'rate' => 4.50],
                ['code' => 'SGD', 'name' => 'Singapore Dollar', 'flag' => 'sg', 'symbol' => 'S$', 'rate' => 1.35],
            ],
            'last_updated' => now()->toISOString(),
        ];

        $data = $setting ? $setting->value : $defaults;

        return $this->successResponse($data, 'Currency rates retrieved');
    }

    /**
     * Update Currency Exchange Rates
     */
    public function updateCurrencyRates(Request $request)
    {
        $request->validate([
            'currencies' => 'required|array|min:1',
            'currencies.*.code' => 'required|string|max:10',
            'currencies.*.name' => 'required|string|max:100',
            'currencies.*.flag' => 'required|string|max:10',
            'currencies.*.symbol' => 'required|string|max:10',
            'currencies.*.rate' => 'required|numeric|min:0',
        ]);

        // Ensure USD is always present with rate = 1
        $currencies = collect($request->currencies);
        $hasUSD = $currencies->contains('code', 'USD');

        if (!$hasUSD) {
            $currencies->prepend([
                'code' => 'USD',
                'name' => 'US Dollar',
                'flag' => 'us',
                'symbol' => '$',
                'rate' => 1,
            ]);
        } else {
            // Force USD rate to be 1
            $currencies = $currencies->map(function ($c) {
                if ($c['code'] === 'USD') {
                    $c['rate'] = 1;
                }
                return $c;
            });
        }

        $data = [
            'currencies' => $currencies->values()->toArray(),
            'last_updated' => now()->toISOString(),
        ];

        Setting::updateOrCreate(
            ['key' => 'currency_rates'],
            ['value' => $data]
        );

        // Clear cache
        Cache::forget('currency_rates');

        return $this->successResponse($data, 'Currency rates updated successfully.');
    }
}
