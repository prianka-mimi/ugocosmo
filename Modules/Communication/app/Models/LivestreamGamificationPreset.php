<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LivestreamGamificationPreset extends Model
{
    protected $table = 'communication_livestream_gamification_presets';

    protected $fillable = [
        'amount',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Scope query to only active presets ordered by sort_order.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('amount', 'asc');
    }
}
