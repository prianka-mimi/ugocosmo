<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CommunicationLike extends Model
{
    protected $table = 'communication_likes';

    protected $fillable = [
        'user_id',
        'likeable_type',
        'likeable_id',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'likeable_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function likeable(): MorphTo
    {
        return $this->morphTo();
    }
}
