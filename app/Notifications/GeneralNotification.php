<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GeneralNotification extends Notification // implements ShouldQueue
{
    // use Queueable;

    public $title;
    public $message;
    public $type; // 'info', 'success', 'warning', 'danger'
    public $category; // 'system', 'payment', 'link', 'account', 'event'
    public $actionUrl;
    public $expiresAt;

    /**
     * Create a new notification instance.
     */
    public function __construct($title, $message, $type = 'info', $category = 'system', $actionUrl = null, $expiresAt = null)
    {
        $this->title = $title;
        $this->message = $message;
        $this->type = $type;
        $this->category = $category;
        $this->actionUrl = $actionUrl;
        $this->expiresAt = $expiresAt;
    }

    /**
     * Tentukan lewat jalur mana notifikasi dikirim.
     */
    public function via(object $notifiable): array
    {
        // Bisa ditambah ['mail', 'database'] jika ingin kirim email juga
        return ['database'];
    }

    /**
     * Format penyimpanan ke Database.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'message' => $this->message,
            'type' => $this->type,
            'category' => $this->category,
            'action_url' => $this->actionUrl,
            'url' => $this->actionUrl, // Alias for frontend compatibility
            'icon' => $this->getIconByType($this->type),
            'expires_at' => $this->expiresAt,
        ];
    }



    private function getIconByType($type)
    {
        return match ($type) {
            'success' => 'check-circle',
            'warning' => 'alert-triangle',
            'danger' => 'x-circle',
            default => 'info',
        };
    }
}
