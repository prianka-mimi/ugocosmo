<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'customer_id',
        'provider_id',
        'booking_date',
        'booking_time',
        'status',
        'notes',
        'subtotal',
        'buyer_fee',
        'seller_fee',
        'platform_fee_total',
        'total',
        'delivery_location',
        'customer_address',
        'package_id',
        'duration',
        'images',
        'delivery_text',
        'delivery_files',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'booking_time' => 'string',
        'subtotal' => 'decimal:2',
        'buyer_fee' => 'decimal:2',
        'seller_fee' => 'decimal:2',
        'platform_fee_total' => 'decimal:2',
        'total' => 'decimal:2',
        'duration' => 'integer',
        'images' => 'array',
        'delivery_files' => 'array',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'package_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'customer_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'provider_id');
    }

    public function transaction(): HasOne
    {
        return $this->hasOne(Transaction::class);
    }

    public function scopeByCustomer($query, int $profileId)
    {
        return $query->where('customer_id', $profileId);
    }

    public function scopeByProvider($query, int $profileId)
    {
        return $query->where('provider_id', $profileId);
    }
}
