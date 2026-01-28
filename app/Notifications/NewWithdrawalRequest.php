<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Withdrawal;

class NewWithdrawalRequest extends Notification implements ShouldQueue
{
    use Queueable;

    protected $withdrawal;

    /**
     * Buat instance notifikasi baru.
     */
    public function __construct(Withdrawal $withdrawal)
    {
        $this->withdrawal = $withdrawal;
    }

    /**
     * Tentukan saluran notifikasi (di sini hanya lewat email).
     */
    public function via($notifiable)
    {
        return ['database'];
    }



    /**
     * Format data untuk notifikasi database.
     * Disamakan dengan struktur GeneralNotification agar frontend konsisten.
     */
    public function toArray($notifiable)
    {
        $user = $this->withdrawal->user;
        
        return [
            'title' => 'New Withdrawal Request',
            'message' => "User {$user->name} requested withdrawal of $" . number_format($this->withdrawal->amount, 2),
            'type' => 'info',
            'action_url' => '/admin/withdrawals',
            'icon' => 'dollar-sign', // Icon helper
        ];
    }
}
