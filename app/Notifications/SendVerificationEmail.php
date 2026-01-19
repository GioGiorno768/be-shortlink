<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;

class SendVerificationEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->mailer('resend') // Use Resend mailer
            ->subject('Verifikasi Email Akun Anda - ShortlinkMu')
            ->greeting('Halo ' . $notifiable->name . '!')
            ->line('Terima kasih sudah mendaftar di ShortlinkMu.')
            ->line('Silakan klik tombol di bawah untuk memverifikasi alamat email Anda:')
            ->action('Verifikasi Email', $verificationUrl)
            ->line('Link verifikasi ini akan kadaluarsa dalam 60 menit.')
            ->line('Jika Anda tidak mendaftar di ShortlinkMu, abaikan email ini.')
            ->salutation('Salam, Tim ShortlinkMu');
    }

    /**
     * Get the verification URL for the given notifiable.
     */
    protected function verificationUrl(object $notifiable): string
    {
        // Create a signed URL that expires in 60 minutes
        $signedUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        // Replace backend URL with frontend URL for redirect
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        // Extract the signature and params, redirect to frontend
        $parsedUrl = parse_url($signedUrl);
        parse_str($parsedUrl['query'] ?? '', $queryParams);

        return $frontendUrl . '/verify-email?' . http_build_query([
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
            'expires' => $queryParams['expires'] ?? '',
            'signature' => $queryParams['signature'] ?? '',
        ]);
    }
}
