<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'description',
        'reference_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payout()
    {
        return $this->belongsTo(Payout::class, 'reference_id');
    }
}