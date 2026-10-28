<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageUserDetail extends Model
{
    public $timestamps = false;

    protected $table = 'message_user_details';

    protected $fillable = [
        'message_id',
        'user_id',
        'delivered_at',
        'read_at',
        'reaction',
        'reaction_added_at',
    ];

    protected function casts(): array
    {
        return [
            'message_id' => 'string',
            'user_id' => 'integer',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'reaction_added_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'message_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
