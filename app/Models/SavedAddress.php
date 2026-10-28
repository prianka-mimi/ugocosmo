<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'label',
        'name',
        'address_line1',
        'address_line2',
        'city_locality',
        'state_province',
        'postal_code',
        'country_code',
        'phone',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Convert to the ShipEngine-compatible address array format
     * for direct use in checkout or shipping rate requests.
     */
    public function toShippingArray(): array
    {
        return [
            'name' => $this->name,
            'address_line1' => $this->address_line1,
            'address_line2' => $this->address_line2,
            'city_locality' => $this->city_locality,
            'state_province' => $this->state_province,
            'postal_code' => $this->postal_code,
            'country_code' => $this->country_code,
            'phone' => $this->phone,
        ];
    }
}
