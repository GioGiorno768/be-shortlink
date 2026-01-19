<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GlobalNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'type',
        'body',
        'is_pinned',
        'created_by',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the admin who created this notification.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
