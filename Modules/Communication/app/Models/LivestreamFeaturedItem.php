<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivestreamFeaturedItem extends Model
{
    protected $table = 'communication_livestream_featured_items';

    protected $fillable = [
        'call_log_id',
        'item_type',
        'item_id',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'call_log_id' => 'integer',
            'item_id' => 'integer',
            'is_featured' => 'boolean',
        ];
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class, 'call_log_id');
    }
}
