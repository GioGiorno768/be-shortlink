<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Level extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'min_total_earnings',
        'bonus_percentage',
        'benefits',
        'icon_color',
        'bg_color',
        'border_color',
        // Feature locks
        'unlock_ad_level_3',
        'unlock_ad_level_4',
        'unlock_top_countries',
        'unlock_top_referrers',
        'max_referrals',
        'monthly_withdrawal_limit',
    ];

    protected $casts = [
        'benefits' => 'array',
        'min_total_earnings' => 'decimal:2',
        'bonus_percentage' => 'decimal:2',
        'unlock_ad_level_3' => 'boolean',
        'unlock_ad_level_4' => 'boolean',
        'unlock_top_countries' => 'boolean',
        'unlock_top_referrers' => 'boolean',
        'max_referrals' => 'integer',
        'monthly_withdrawal_limit' => 'decimal:2',
    ];
}
