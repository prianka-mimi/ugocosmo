<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'sort_order',
        'name',
        'slug',
        'price',
        'status',
        'description',
        'features',
    ];

    protected $casts = [
        'features' => 'array',
    ];

    public function userSubscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }
}
