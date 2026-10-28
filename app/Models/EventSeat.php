<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSeat extends Model
{
    use HasFactory;

    protected $fillable = [
        'section_id',
        'seat_number',
        'price',
        'status',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(EventSection::class, 'section_id');
    }

    public function scopeBySection($query, int $sectionId)
    {
        return $query->where('section_id', $sectionId);
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'available');
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
