<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ServiceTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_service_tag', 'service_tag_id', 'service_id');
    }
}
