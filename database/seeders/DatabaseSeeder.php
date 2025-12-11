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

        // Levels
        $levels = [
            ['Level 1', 0, 0],
            ['Level 2', 2000000, 5],
            ['Level 3', 4000000, 10],
            ['Level 4', 8000000, 15],
            ['Level 5', 16000000, 20],
            ['Level 6', 32000000, 25],
        ];

        foreach ($levels as [$name, $min_earnings, $bonus]) {
            Level::updateOrCreate(
                ['name' => $name],
                [
                    'min_total_earnings' => $min_earnings,
                    'bonus_percentage' => $bonus,
                ]
            );
        }
    }
}
