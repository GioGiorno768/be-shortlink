<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Setting::updateOrCreate(
            ['key' => 'ad_cpc_rates'],
            [
                'value' => [
                    1 => 0.05,
                    2 => 0.07,
                    3 => 0.10,
                    4 => 0.15,
                    // 5 => 0.20,
                ]
            ]
        );
        // 2. Setting Minimal Penarikan (BARU)
        Setting::updateOrCreate(
            ['key' => 'withdrawal_settings'],
            [
                'value' => [
                    'min_amount' => 1.00, // Default $5 atau Rp 5
                    'currency' => 'USD'   // Opsional: simpan mata uang juga
                ]
            ]
        );
    }
}
