<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    protected $table = 'communication_conversations';

    protected $fillable = [
        'is_group',
        'name',
        'avatar',
    ];

    protected function casts(): array
    {
        return [
            'is_group' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('created_at');
    }
}
