<?php

namespace Database\Seeders;

use App\Models\User;
use App\Notifications\GeneralNotification;
use Illuminate\Database\Seeder;

class NotificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get ALL users
        $users = User::all();

        if ($users->isEmpty()) {
            $this->command->warn('No users found. Please create a user first.');
            return;
        }

        $notifications = [
            // Payment Success
            [
                'title' => 'Payout Approved',
                'message' => 'Penarikan dana $15.50 berhasil dikirim ke PayPal.',
                'type' => 'success',
                'category' => 'payment',
                'url' => '/wallet',
            ],
            // System Warning
            [
                'title' => 'Maintenance Scheduled',
                'message' => 'Sistem akan maintenance pada jam 02:00 - 04:00 WIB.',
                'type' => 'warning',
                'category' => 'system',
                'url' => null,
            ],
            // Event Info
            [
                'title' => 'Event Double CPM!',
                'message' => 'Nikmati kenaikan CPM 20% khusus weekend ini!',
                'type' => 'info',
                'category' => 'event',
                'url' => '/dashboard',
            ],
            // Account Alert
            [
                'title' => 'Login Mencurigakan',
                'message' => 'Login dari IP tidak dikenal (Russia). Segera cek akun.',
                'type' => 'danger',
                'category' => 'account',
                'url' => '/settings/security',
            ],
            // Link Info
            [
                'title' => 'Link Populer',
                'message' => 'Link short.link/xyz tembus 1000 view hari ini!',
                'type' => 'info',
                'category' => 'link',
                'url' => '/links',
            ],
            // Payment Pending
            [
                'title' => 'Withdrawal Pending',
                'message' => 'Permintaan penarikan $25.00 sedang diproses.',
                'type' => 'warning',
                'category' => 'payment',
                'url' => '/wallet',
            ],
        ];

        $count = 0;
        foreach ($users as $user) {
            foreach ($notifications as $notif) {
                $user->notify(new GeneralNotification(
                    $notif['title'],
                    $notif['message'],
                    $notif['type'],
                    $notif['category'],
                    $notif['url'],
                    null // expiresAt
                ));
            }

            // Mark some as read
            $user->unreadNotifications()->limit(2)->get()->each->markAsRead();
            $count++;
        }

        $this->command->info('Created ' . count($notifications) . ' sample notifications for ' . $count . ' users');
    }
}
