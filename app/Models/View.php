<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class View extends Model
{
    use HasFactory;

    protected $fillable = [
        'link_id',
        'ip_address',
        'country',
        'device',
        'browser',
        'referer',
        'user_agent',
        'is_unique',
        'is_valid',
        'earned',
    ];

    // Relasi ke link
    public function link()
    {
        return $this->belongsTo(Link::class);
    }
}
