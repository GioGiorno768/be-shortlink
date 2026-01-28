<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class UserReferrerStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'referrer_key',
        'view_count',
    ];

    /**
     * Known referrer mappings
     * Key => Display Label
     */
    public const REFERRER_LABELS = [
        'google' => 'Google',
        'facebook' => 'Facebook',
        'youtube' => 'YouTube',
        'twitter_x' => 'Twitter / X',
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'whatsapp' => 'WhatsApp',
        'telegram' => 'Telegram',
        'linkedin' => 'LinkedIn',
        'reddit' => 'Reddit',
        'pinterest' => 'Pinterest',
        'direct' => 'Direct / Email / SMS',
        'other' => 'Other',
    ];

    /**
     * Relationship: belongs to User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Parse referrer URL to get normalized key
     * 
     * @param string|null $referrerUrl
     * @return string Referrer key (e.g., "youtube", "direct", "other")
     */
    public static function parseReferrer(?string $referrerUrl): string
    {
        if (empty($referrerUrl)) {
            return 'direct';
        }

        $host = parse_url($referrerUrl, PHP_URL_HOST);
        if (!$host) {
            return 'direct';
        }

        $host = str_replace('www.', '', strtolower($host));

        // Match known referrers
        if (str_contains($host, 'google')) return 'google';
        if (str_contains($host, 'facebook') || str_contains($host, 'fb.com') || str_contains($host, 'fb.me')) return 'facebook';
        if (str_contains($host, 'youtube') || str_contains($host, 'youtu.be')) return 'youtube';
        if (str_contains($host, 't.co') || str_contains($host, 'twitter') || str_contains($host, 'x.com')) return 'twitter_x';
        if (str_contains($host, 'instagram')) return 'instagram';
        if (str_contains($host, 'tiktok')) return 'tiktok';
        if (str_contains($host, 'whatsapp') || str_contains($host, 'wa.me')) return 'whatsapp';
        if (str_contains($host, 'telegram') || str_contains($host, 't.me')) return 'telegram';
        if (str_contains($host, 'linkedin')) return 'linkedin';
        if (str_contains($host, 'reddit')) return 'reddit';
        if (str_contains($host, 'pinterest')) return 'pinterest';

        return 'other';
    }

    /**
     * Get display label for referrer key
     */
    public static function getLabel(string $key): string
    {
        return self::REFERRER_LABELS[$key] ?? ucfirst($key);
    }

    /**
     * Increment view count for a user's referrer (UPSERT)
     * 
     * @param int $userId
     * @param string|null $referrerUrl Raw referrer URL (will be parsed)
     * @return void
     */
    public static function incrementView(int $userId, ?string $referrerUrl): void
    {
        $referrerKey = self::parseReferrer($referrerUrl);

        // Use UPSERT pattern for atomic increment
        DB::table('user_referrer_stats')
            ->upsert(
                [
                    'user_id' => $userId,
                    'referrer_key' => $referrerKey,
                    'view_count' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['user_id', 'referrer_key'], // Unique key
                ['view_count' => DB::raw('view_count + 1'), 'updated_at' => now()] // Update on conflict
            );
    }

    /**
     * Get top referrers for a user
     * 
     * @param int $userId
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public static function getTopReferrers(int $userId, int $limit = 6)
    {
        return self::where('user_id', $userId)
            ->orderByDesc('view_count')
            ->limit($limit)
            ->get();
    }
}
