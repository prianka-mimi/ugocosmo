<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventSection extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'section_name',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function seats(): HasMany
    {
        return $this->hasMany(EventSeat::class, 'section_id');
    }

    public function scopeByEvent($query, int $eventId)
    {
        return $query->where('event_id', $eventId);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('created_at');
    }
}
