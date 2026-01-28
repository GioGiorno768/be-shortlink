<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LinkViolation extends Model
{
    use HasFactory;

    protected $fillable = [
        'link_id',
        'user_id',
        'referrer_domain',
        'violation_count',
        'first_detected_at',
        'last_detected_at',
    ];

    protected $casts = [
        'first_detected_at' => 'datetime',
        'last_detected_at' => 'datetime',
    ];

    /**
     * Get the link that was violated.
     */
    public function link()
    {
        return $this->belongsTo(Link::class);
    }

    /**
     * Get the user who owns the violated link.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
