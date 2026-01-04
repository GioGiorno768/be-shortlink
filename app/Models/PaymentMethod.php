<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'template_id',
        'method_type',
        'account_name',
        'account_number',
        'bank_name',
        'fee',
        'is_verified',
        'is_default',
        // 'verification_token'
    ];

    /**
     * Get the template this payment method is based on
     */
    public function template()
    {
        return $this->belongsTo(PaymentMethodTemplate::class, 'template_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payouts()
    {
        return $this->hasMany(Payout::class);
    }
}
