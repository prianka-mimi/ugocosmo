<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    protected $table = 'communication_messages';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'conversation_id',
        'sender_id',
        'type',
        'content',
        'attachments',
        'metadata',
        'status',
        'reactions_summary',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'sender_id' => 'integer',
            'attachments' => 'array',
            'metadata' => 'array',
            'reactions_summary' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function userDetails(): HasMany
    {
        return $this->hasMany(MessageUserDetail::class, 'message_id', 'id');
    }
}
