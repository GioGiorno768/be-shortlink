<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AdLevelConfig;

class AdLevelConfigSeeder extends Seeder
{
    public function run(): void
    {
        $levels = [
            [
                'id' => 1,
                'name' => 'Low',
                'slug' => 'low',
                'description' => 'User-friendly, minimal ads. Best for retention-focused traffic.',
                'demo_url' => 'https://demo.shortlinkmu.com/low',
                'color_theme' => 'green',
                'revenue_share' => 30,
                'is_popular' => false,
                'display_order' => 1,
                'features' => [
                    ['label' => 'Banner Ads', 'value' => true, 'included' => true],
                    ['label' => 'Interstitial', 'value' => false, 'included' => false],
                    ['label' => 'Popunder', 'value' => '1 / 24h', 'included' => true],
                    ['label' => 'Push Notif', 'value' => false, 'included' => false],
                    ['label' => 'Captcha', 'value' => 'Simple', 'included' => true],
                ],
            ],
            [
                'id' => 2,
                'name' => 'Medium',
                'slug' => 'medium',
                'description' => 'Balanced experience between earnings and visitor comfort.',
                'demo_url' => 'https://demo.shortlinkmu.com/medium',
                'color_theme' => 'blue',
                'revenue_share' => 50,
                'is_popular' => true,
                'display_order' => 2,
                'features' => [
                    ['label' => 'Banner Ads', 'value' => true, 'included' => true],
                    ['label' => 'Interstitial', 'value' => 'On Page Load', 'included' => true],
                    ['label' => 'Popunder', 'value' => '2 / 24h', 'included' => true],
                    ['label' => 'Push Notif', 'value' => false, 'included' => false],
                    ['label' => 'Captcha', 'value' => 'Standard', 'included' => true],
                ],
            ],
            [
                'id' => 3,
                'name' => 'High',
                'slug' => 'high',
                'description' => 'Maximized for earnings with aggressive ad formats.',
                'demo_url' => 'https://demo.shortlinkmu.com/high',
                'color_theme' => 'orange',
                'revenue_share' => 75,
                'is_popular' => false,
                'display_order' => 3,
                'features' => [
                    ['label' => 'Banner Ads', 'value' => 'Aggressive', 'included' => true],
                    ['label' => 'Interstitial', 'value' => 'Every Page', 'included' => true],
                    ['label' => 'Popunder', 'value' => '3 / 24h', 'included' => true],
                    ['label' => 'Push Notif', 'value' => true, 'included' => true],
                    ['label' => 'Captcha', 'value' => 'Double', 'included' => true],
                ],
            ],
            [
                'id' => 4,
                'name' => 'Aggressive',
                'slug' => 'aggressive',
                'description' => 'Highest revenue potential. Not for sensitive traffic.',
                'demo_url' => 'https://demo.shortlinkmu.com/aggressive',
                'color_theme' => 'red',
                'revenue_share' => 100,
                'is_popular' => false,
                'display_order' => 4,
                'features' => [
                    ['label' => 'Banner Ads', 'value' => 'Max', 'included' => true],
                    ['label' => 'Interstitial', 'value' => 'Multipoint', 'included' => true],
                    ['label' => 'Popunder', 'value' => 'Unlimited', 'included' => true],
                    ['label' => 'Push Notif', 'value' => 'High Freq', 'included' => true],
                    ['label' => 'Captcha', 'value' => 'Triple', 'included' => true],
                ],
            ],
        ];

        foreach ($levels as $level) {
            AdLevelConfig::updateOrCreate(
                ['id' => $level['id']],
                $level
            );
        }
    }
}
