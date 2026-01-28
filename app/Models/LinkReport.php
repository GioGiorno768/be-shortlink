<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LinkReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'link_url',
        'link_id',
        'reason',
        'email',
        'details',
        'ip_address',
        'status',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    /**
     * Relasi ke Link (jika ada)
     */
    public function link()
    {
        return $this->belongsTo(Link::class);
    }

    /**
     * Scope: pending reports
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: resolved reports
     */
    public function scopeResolved($query)
    {
        return $query->whereIn('status', ['resolved', 'ignored']);
    }
}
