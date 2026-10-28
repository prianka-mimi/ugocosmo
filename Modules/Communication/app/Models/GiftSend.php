<?php

declare(strict_types=1);

namespace Modules\Communication\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GiftSend extends Model
{
    use HasFactory;

    protected $table = 'gift_sends';

    protected $fillable = [
        'sender_id',
        'receiver_id',
        'gift_id',
        'quantity',
        'coin_cost',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'coin_cost' => 'integer',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    public function gift()
    {
        return $this->belongsTo(Gift::class);
    }
}
