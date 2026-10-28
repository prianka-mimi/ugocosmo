<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryReaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'story_id',
        'reactor_id',
        'reaction_type',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function reactor(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'reactor_id');
    }
}
