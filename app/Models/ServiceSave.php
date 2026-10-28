<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceSave extends Model
{
    protected $fillable = ['user_id', 'service_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }
}
