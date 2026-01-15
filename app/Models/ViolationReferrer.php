<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ViolationReferrer extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain',
        'name',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the admin who created this violation referrer.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope to get only active violation referrers.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
