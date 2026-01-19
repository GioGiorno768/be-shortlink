<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Level;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'test@example.com'], // cek berdasarkan email
            [
                'name' => 'Test User',
                'password' => bcrypt('password123'),
            ]
        );



        $this->call([
            SettingSeeder::class,
            AdminSeeder::class,
            SuperAdminSeeder::class,
        ]);

        // Levels with full styling data
        $levels = [
            [
                'name' => 'Beginner',
                'slug' => 'beginner',
                'icon' => 'shield',
                'min_total_earnings' => 0,
                'bonus_percentage' => 0,
                'benefits' => ['Basic Analytics', 'Standard Support', 'Monthly Payout'],
                'icon_color' => 'text-gray-500',
                'bg_color' => 'bg-white',
                'border_color' => 'border-gray-200',
            ],
            [
                'name' => 'Rookie',
                'slug' => 'rookie',
                'icon' => 'star',
                'min_total_earnings' => 50,
                'bonus_percentage' => 5,
                'benefits' => ['+5% CPM Bonus', 'Priority Support', 'Faster Withdrawal'],
                'icon_color' => 'text-green-500',
                'bg_color' => 'bg-green-50',
                'border_color' => 'border-green-200',
            ],
            [
                'name' => 'Elite',
                'slug' => 'elite',
                'icon' => 'trophy',
                'min_total_earnings' => 250,
                'bonus_percentage' => 10,
                'benefits' => ['+10% CPM Bonus', 'Daily Payout Request', 'No Captcha for Users'],
                'icon_color' => 'text-blue-500',
                'bg_color' => 'bg-blue-50',
                'border_color' => 'border-blue-200',
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'icon' => 'gem',
                'min_total_earnings' => 1000,
                'bonus_percentage' => 15,
                'benefits' => ['+15% CPM Bonus', 'Dedicated Manager', 'Custom Alias Domain'],
                'icon_color' => 'text-purple-500',
                'bg_color' => 'bg-purple-50',
                'border_color' => 'border-purple-200',
            ],
            [
                'name' => 'Master',
                'slug' => 'master',
                'icon' => 'rocket',
                'min_total_earnings' => 5000,
                'bonus_percentage' => 25,
                'benefits' => ['+25% CPM Bonus', 'Instant Payout', 'Exclusive Events'],
                'icon_color' => 'text-red-500',
                'bg_color' => 'bg-red-50',
                'border_color' => 'border-red-200',
            ],
            [
                'name' => 'Mythic',
                'slug' => 'mythic',
                'icon' => 'crown',
                'min_total_earnings' => 20000,
                'bonus_percentage' => 40,
                'benefits' => ['+40% CPM Bonus', 'VIP Status', 'Revenue Share 100%'],
                'icon_color' => 'text-yellow-500',
                'bg_color' => 'bg-yellow-50',
                'border_color' => 'border-yellow-200',
            ],
        ];

        foreach ($levels as $levelData) {
            Level::updateOrCreate(
                ['slug' => $levelData['slug']],
                $levelData
            );
        }
    }
}
