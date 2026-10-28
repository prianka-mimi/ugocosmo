<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryView extends Model
{
    use HasFactory;

    protected $fillable = [
        'story_id',
        'viewer_id',
        'viewed_at',
    ];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'viewer_id');
    }
}
