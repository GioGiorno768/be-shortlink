<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'referral_code',
        'referred_by',
        'balance',
        'google_id',         // <-- TAMBAHKAN INI
        'provider_name',     // <-- TAMBAHKAN INI
        'email_verified_at', // <-- TAMBAHKAN INI (untuk membuat user baru)
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'balance' => 'integer',
        ];
    }
    public function referrals()
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    // Relasi: siapa yang mereferensikan user ini
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    // Helper: generate referral code unik
    public static function generateReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

    public function links()
    {
        return $this->hasMany(Link::class);
    }

    public function withdrawals()
    {
        return $this->hasMany(Withdrawal::class);
    }

    public function paymentMethods()
    {
        return $this->hasMany(\App\Models\PaymentMethod::class);
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class);
    }


}
