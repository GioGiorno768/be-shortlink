<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SelfClickSettingsSeeder extends Seeder
{
    /**
     * Seed the self-click settings.
     */
    public function run(): void
    {
        Setting::updateOrCreate(
            ['key' => 'self_click'],
            [
                'value' => [
                    'enabled' => true,
                    'cpc_percentage' => 30,
                    'daily_limit' => 1,
                ]
            ]
        );
    }
}
