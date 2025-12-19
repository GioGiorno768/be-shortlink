<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Transaction;
use App\Models\Setting;
use App\Models\Payout;
use Carbon\Carbon;

class ReferralSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure referral percentage setting exists
        Setting::updateOrCreate(
            ['key' => 'referral_percentage'],
            ['value' => ['percentage' => 20]] // 20% commission
        );

        // 2. Get user with ID 6 as the referrer
        $mainUser = User::find(6);

        if (!$mainUser) {
            $this->command->warn('User with ID 6 not found. Skipping referral seeding.');
            return;
        }

        // Ensure main user has referral code
        if (!$mainUser->referral_code) {
            $mainUser->update(['referral_code' => User::generateReferralCode()]);
        }

        // 3. Create referred users
        $referredUsers = [
            [
                'name' => 'Budi Santoso',
                'email' => 'budi@example.com',
                'days_ago' => 5, // joined 5 days ago (active)
                'earnings_for_referrer' => 12.50,
            ],
            [
                'name' => 'Siti Aminah',
                'email' => 'siti@example.com',
                'days_ago' => 45, // joined 45 days ago (inactive)
                'earnings_for_referrer' => 5.20,
            ],
            [
                'name' => 'Joko Widodo',
                'email' => 'joko@example.com',
                'days_ago' => 10, // joined 10 days ago (active)
                'earnings_for_referrer' => 25.00,
            ],
            [
                'name' => 'Dewi Lestari',
                'email' => 'dewi@example.com',
                'days_ago' => 60, // joined 60 days ago (inactive)
                'earnings_for_referrer' => 8.75,
            ],
            [
                'name' => 'Ahmad Dahlan',
                'email' => 'ahmad@example.com',
                'days_ago' => 2, // joined 2 days ago (active)
                'earnings_for_referrer' => 3.00,
            ],
        ];

        foreach ($referredUsers as $userData) {
            // Create or update referred user
            $referredUser = User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => bcrypt('password123'),
                    'referred_by' => $mainUser->id,
                    'referral_code' => User::generateReferralCode(),
                    'email_verified_at' => now(),
                    'created_at' => Carbon::now()->subDays($userData['days_ago']),
                    'updated_at' => $userData['days_ago'] <= 30
                        ? Carbon::now()->subDays(rand(0, min($userData['days_ago'], 5)))
                        : Carbon::now()->subDays($userData['days_ago']),
                    'last_active_at' => $userData['days_ago'] <= 30
                        ? Carbon::now()->subDays(rand(0, 5))
                        : null,
                ]
            );

            // Create a dummy payout for this user (needed for transaction reference)
            $payout = Payout::firstOrCreate(
                ['user_id' => $referredUser->id, 'status' => 'paid'],
                [
                    'amount' => $userData['earnings_for_referrer'] * 5, // Their total payout
                    'method' => 'DANA',
                    'account_details' => '0812xxxx' . rand(1000, 9999) . ' - ' . $userData['name'],
                    'created_at' => Carbon::now()->subDays(rand(1, $userData['days_ago'])),
                ]
            );

            // Create referral commission transaction for main user
            Transaction::updateOrCreate(
                [
                    'user_id' => $mainUser->id,
                    'type' => 'referral_commission',
                    'reference_id' => $payout->id,
                ],
                [
                    'amount' => $userData['earnings_for_referrer'],
                    'description' => "Referral commission from {$userData['name']}",
                ]
            );
        }

        $this->command->info('✅ Referral test data seeded successfully!');
        $this->command->info("   Main user: {$mainUser->email}");
        $this->command->info("   Referral code: {$mainUser->referral_code}");
        $this->command->info("   Referred users: " . count($referredUsers));
    }
}
