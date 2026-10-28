<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;

class LivestreamAmountPreset extends Model
{
    protected $table = 'communication_livestream_amount_presets';

    protected $fillable = [
        'amount',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('amount', 'asc');
    }
}
