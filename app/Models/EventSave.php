<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSave extends Model
{
    protected $fillable = ['user_id', 'event_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }
}
