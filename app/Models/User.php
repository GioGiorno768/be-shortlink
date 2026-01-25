<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;
use App\Models\Level;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    const ROLE_USER = 'user';
    const ROLE_ADMIN = 'admin';
    const ROLE_SUPER_ADMIN = 'super_admin';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone_number', // <-- TAMBAHKAN INI
        'password',
        'referral_code',
        'referred_by',
        'balance',
        'google_id',         // <-- TAMBAHKAN INI
        'provider_name',     // <-- TAMBAHKAN INI
        'email_verified_at', // <-- TAMBAHKAN INI (untuk membuat user baru)
        'role',
        'is_banned',
        'ban_reason',        // <-- BAN REASON
        'total_earnings',
        'last_active_at', // <-- TAMBAHKAN INI
        'last_device_fingerprint', // Anti-fraud
        'last_login_ip',           // Anti-fraud
        'same_ip_referral_count',  // Anti-fraud: count referrals from same IP as referrer
        'avatar',                  // User avatar (avatar-1, avatar-2, etc.)
        'current_level_id',        // User's current level
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

    protected $appends = ['has_password'];

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
            'balance' => 'decimal:5', // 5 decimals for micro-transaction precision
            'is_banned' => 'boolean',
            'total_earnings' => 'decimal:4',
            'last_active_at' => 'datetime',
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



    public function paymentMethods()
    {
        return $this->hasMany(\App\Models\PaymentMethod::class);
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class);
    }

    public function currentLevel()
    {
        return $this->belongsTo(Level::class, 'current_level_id');
    }

    public function getCurrentLevelAttribute()
    {
        // Jika sudah ada di cache (relation loaded atau column not null), pakai itu
        if ($this->relationLoaded('currentLevel')) {
            return $this->getRelation('currentLevel');
        }

        if ($this->current_level_id) {
            return $this->currentLevel()->first();
        }

        // Fallback: Hitung manual jika belum diset (dan simpan)
        $levels = \Illuminate\Support\Facades\Cache::remember('all_levels', 3600, function () {
            return Level::orderBy('min_total_earnings', 'desc')->get();
        });

        $level = $levels->firstWhere('min_total_earnings', '<=', $this->total_earnings);

        if ($level && $level->id !== $this->current_level_id) {
            $this->forceFill(['current_level_id' => $level->id])->saveQuietly();
        }

        return $level;
    }

    /**
     * Update user level based on total earnings
     */
    /**
     * Update user level based on total earnings
     */
    public function checkAndUpdateLevel()
    {
        // Cache levels for 1 hour to reduce DB queries
        $levels = \Illuminate\Support\Facades\Cache::remember('all_levels', 3600, function () {
            return Level::orderBy('min_total_earnings', 'desc')->get();
        });

        // Find matching level from collection
        $level = $levels->firstWhere('min_total_earnings', '<=', $this->total_earnings);

        if ($level && $level->id !== $this->current_level_id) {
            $this->update(['current_level_id' => $level->id]);
        }
    }

    public function getBonusCpmPercentageAttribute()
    {
        $level = $this->current_level;
        return $level ? $level->bonus_percentage : 0;
    }

    public function getHasPasswordAttribute()
    {
        return !is_null($this->password);
    }

    public function loginHistories()
    {
        return $this->hasMany(LoginHistory::class);
    }

    /**
     * Check if user is admin or super admin
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN || $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Check if user is super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Scope a query to only include active users.
     * Active = interacted within last 30 days
     */
    public function scopeActive($query)
    {
        return $query->where('last_active_at', '>=', now()->subDays(30));
    }

    /**
     * Check if user is considered active
     */
    public function getIsActiveAttribute()
    {
        return $this->last_active_at && $this->last_active_at->diffInDays(now()) <= 30;
    }
}
