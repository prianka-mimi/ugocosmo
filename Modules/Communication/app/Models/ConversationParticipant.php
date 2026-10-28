<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationParticipant extends Model
{
    public $timestamps = false;

    protected $table = 'conversation_participants';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'status',
        'notification_enabled',
        'receive_audio_call_enabled',
        'history_cleared_message_id',
        'last_msg_delivery_id',
        'last_msg_delivery_at',
        'last_msg_read_id',
        'last_msg_read_at',
        'joined_at',
        'left_at',
        'deleted_from_list_at',
    ];

    protected function casts(): array
    {
        return [
            'conversation_id' => 'integer',
            'user_id' => 'integer',
            'notification_enabled' => 'boolean',
            'receive_audio_call_enabled' => 'boolean',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'deleted_from_list_at' => 'datetime',
            'last_msg_delivery_at' => 'datetime',
            'last_msg_read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
