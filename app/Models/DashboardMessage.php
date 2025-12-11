<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DashboardMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'content',
        'link',
        'button_label',
        'icon',
        'theme_color',
        'type',
        'is_active',
        'published_at',
        'expired_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'expired_at' => 'datetime',
    ];
}
