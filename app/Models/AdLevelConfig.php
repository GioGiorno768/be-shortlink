<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\AdRate;

class AdLevelConfig extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'demo_url',
        'color_theme',
        'revenue_share',
        'is_popular',
        'is_enabled',
        'is_default',
        'is_recommended',
        'features',
        'enabled_features',
        'feature_values',
        'display_order',
    ];

    protected $casts = [
        'features' => 'array',
        'enabled_features' => 'array',
        'feature_values' => 'array',
        'is_popular' => 'boolean',
        'is_enabled' => 'boolean',
        'is_default' => 'boolean',
        'is_recommended' => 'boolean',
        'revenue_share' => 'integer',
        'display_order' => 'integer',
    ];

    /**
     * Scope to order by display_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order', 'asc');
    }

    /**
     * Get CPM rate from AdRate GLOBAL table
     * Maps id to level_X key (id=1 -> level_1, id=2 -> level_2, etc.)
     */
    public function getCpmRateAttribute()
    {
        $globalRates = AdRate::where('country', 'GLOBAL')->first();

        if (!$globalRates || !$globalRates->rates) {
            // Fallback defaults
            $defaults = [1 => 0.05, 2 => 0.07, 3 => 0.10, 4 => 0.15];
            return $defaults[$this->id] ?? 0.05;
        }

        $levelKey = "level_{$this->id}";
        return $globalRates->rates[$levelKey] ?? 0.05;
    }

    /**
     * Get formatted CPM rate for display
     */
    public function getCpmRateDisplayAttribute()
    {
        $rate = $this->cpm_rate;
        return '$' . number_format($rate, 2) . '/1k views';
    }
}
