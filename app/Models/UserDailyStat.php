<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UserDailyStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'views',
        'valid_views',
        'earnings',
    ];

    protected $casts = [
        // 🔧 NOTE: date is stored as string 'Y-m-d' to avoid timezone issues
        // Don't cast to 'date' as it converts to datetime with UTC offset
        'earnings' => 'decimal:4',
    ];

    /**
     * Relationship: belongs to User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Increment stats for a user's daily record (UPSERT)
     * Called on each view to update daily totals
     * 
     * @param int $userId
     * @param bool $isValidView
     * @param float $earnings
     * @return void
     */
    public static function incrementStats(int $userId, bool $isValidView, float $earnings): void
    {
        // 🔧 FIX: Use pure date string format (Y-m-d) to avoid timezone issues
        $today = Carbon::today()->format('Y-m-d');

        // Use UPSERT pattern for atomic increment
        DB::table('user_daily_stats')
            ->upsert(
                [
                    'user_id' => $userId,
                    'date' => $today, // Pure date string: "2026-01-01"
                    'views' => 1,
                    'valid_views' => $isValidView ? 1 : 0,
                    'earnings' => $isValidView ? $earnings : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                ['user_id', 'date'], // Unique key
                [ // Update on conflict
                    'views' => DB::raw('views + 1'),
                    'valid_views' => DB::raw('valid_views + ' . ($isValidView ? 1 : 0)),
                    'earnings' => DB::raw('earnings + ' . ($isValidView ? $earnings : 0)),
                    'updated_at' => now(),
                ]
            );
    }

    /**
     * Get stats sum for a user within a date range
     * 
     * @param int $userId
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array ['views' => int, 'valid_views' => int, 'earnings' => float]
     */
    public static function getStatsBetween(int $userId, Carbon $startDate, Carbon $endDate): array
    {
        $result = self::where('user_id', $userId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->selectRaw('
                COALESCE(SUM(views), 0) as total_views,
                COALESCE(SUM(valid_views), 0) as total_valid_views,
                COALESCE(SUM(earnings), 0) as total_earnings
            ')
            ->first();

        return [
            'views' => (int) ($result->total_views ?? 0),
            'valid_views' => (int) ($result->total_valid_views ?? 0),
            'earnings' => (float) ($result->total_earnings ?? 0),
        ];
    }

    /**
     * Get earnings for a user within a date range
     */
    public static function getEarningsBetween(int $userId, Carbon $startDate, Carbon $endDate): float
    {
        return (float) self::where('user_id', $userId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('earnings');
    }

    /**
     * Get valid views count for a user within a date range
     */
    public static function getValidViewsBetween(int $userId, Carbon $startDate, Carbon $endDate): int
    {
        return (int) self::where('user_id', $userId)
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('valid_views');
    }
}
