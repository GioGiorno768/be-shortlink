<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AdRate;

class AdRateSeeder extends Seeder
{
    /**
     * Seed ad rates for countries.
     * These are the CPM rates per country per ad level.
     */
    public function run(): void
    {
        $rates = [
            [
                'country' => 'GLOBAL',
                'rates' => [
                    'level_1' => 0.50,
                    'level_2' => 1.00,
                    'level_3' => 1.50,
                    'level_4' => 2.00,
                ],
            ],
            [
                'country' => 'US',
                'rates' => [
                    'level_1' => 2.00,
                    'level_2' => 3.50,
                    'level_3' => 5.00,
                    'level_4' => 7.50,
                ],
            ],
            [
                'country' => 'UK',
                'rates' => [
                    'level_1' => 1.80,
                    'level_2' => 3.00,
                    'level_3' => 4.50,
                    'level_4' => 6.50,
                ],
            ],
            [
                'country' => 'ID',
                'rates' => [
                    'level_1' => 0.30,
                    'level_2' => 0.60,
                    'level_3' => 0.90,
                    'level_4' => 1.20,
                ],
            ],
        ];

        foreach ($rates as $rate) {
            AdRate::updateOrCreate(
                ['country' => $rate['country']],
                $rate
            );
        }

        $this->command->info('Ad rates seeded successfully!');
    }
}
