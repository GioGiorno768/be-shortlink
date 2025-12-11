<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str; // Import Str


class Payout extends Model
{

    use HasFactory;
    protected $fillable = [
        'transaction_id',
        'user_id',
        'amount',
        'method',
        'payment_method_id',
        'account',
        'fee',
        'status',
        'note',
        'processed_by', // ✅ Tambahkan ini
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Contoh Format: WD-251125-A1B2C
            // WD = Prefix Withdrawal
            // 251125 = Tanggal (y-m-d)
            // A1B2C = Random String 5 karakter uppercase
            $model->transaction_id = 'WD-' . date('ymd') . '-' . strtoupper(Str::random(5));
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

}
