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
    ];

    /**
     * Relasi ke Link (jika ada)
     */
    public function link()
    {
        return $this->belongsTo(Link::class);
    }
}
