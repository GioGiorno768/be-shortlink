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
    ];

    protected $casts = [
        'benefits' => 'array',
        'min_total_earnings' => 'decimal:2',
        'bonus_percentage' => 'decimal:2',
    ];
}
