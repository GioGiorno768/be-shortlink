<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ... (Kode lain, jika ada)

        // Pastikan Anda telah mendefinisikan APP_FRONTEND_URL di .env file
        $frontendUrl = config('app.frontend_url') ?? 'http://localhost:5173/';

        // Mengatur URL Reset Password agar mengarah ke frontend React
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) use ($frontendUrl) {
            // Kita harus mengambil email dari notifiable untuk dilewatkan ke frontend
            $email = $notifiable->getEmailForPasswordReset();
            
            // Format URL ke rute React Anda, misal: /reset-password?token=XXX&email=user@example.com
            return URL::to($frontendUrl . '/form-password?token=' . $token . '&email=' . urlencode($email));
        });
    }
}