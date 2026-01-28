<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class UserCountryStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'country_code',
        'view_count',
    ];

    /**
     * Top countries for CPC rates (tracked individually)
     * Other countries go to "OTHER"
     */
    public const TOP_COUNTRIES = [
        'US',
        'GB',
        'CA',
        'AU',
        'DE',
        'FR',
        'NL',
        'SE',
        'NO',
        'DK',  // Tier 1
        'JP',
        'SG',
        'NZ',
        'IE',
        'BE',
        'AT',
        'CH',
        'FI',              // Tier 1-2
        'ID',
        'IN',
        'BR',
        'MX',
        'PH',
        'VN',
        'TH',
        'MY',
        'PK',
        'NG',  // Tier 3
        'BD',
        'RU',
        'TR',
        'EG',
        'ZA',
        'KE',
        'AR',
        'CO',
        'CL',
        'PE',  // Tier 3-4
    ];

    /**
     * Relationship: belongs to User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Normalize country code - returns tracked code or "OTHER"
     */
    public static function normalizeCountry(string $code): string
    {
        $code = strtoupper(trim($code));

        if (empty($code) || $code === 'UNKNOWN') {
            return 'OTHER';
        }

        return in_array($code, self::TOP_COUNTRIES) ? $code : 'OTHER';
    }

    /**
     * Increment view count for a user's country (UPSERT)
     * 
     * @param int $userId
     * @param string $countryCode Raw country code (will be normalized)
     * @return void
     */
    public static function incrementView(int $userId, string $countryCode): void
    {
        $normalizedCode = self::normalizeCountry($countryCode);

        // Use UPSERT pattern for atomic increment
        DB::table('user_country_stats')
            ->upsert(
                [
                    'user_id' => $userId,
                    'country_code' => $normalizedCode,
                    'view_count' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['user_id', 'country_code'], // Unique key
                ['view_count' => DB::raw('view_count + 1'), 'updated_at' => now()] // Update on conflict
            );
    }

    /**
     * Get top countries for a user
     * 
     * @param int $userId
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public static function getTopCountries(int $userId, int $limit = 7)
    {
        return self::where('user_id', $userId)
            ->orderByDesc('view_count')
            ->limit($limit)
            ->get();
    }
}
