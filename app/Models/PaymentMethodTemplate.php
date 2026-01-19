<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethodTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'currency',
        'input_type',
        'input_label',
        'icon',
        'fee',
        'min_amount',
        'max_amount',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'fee' => 'decimal:8',
        'min_amount' => 'decimal:8',
        'max_amount' => 'decimal:8',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Get all user payment methods using this template
     */
    public function userMethods()
    {
        return $this->hasMany(PaymentMethod::class, 'template_id');
    }

    /**
     * Scope for active templates only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope ordered by sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc');
    }

    /**
     * Get templates by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
