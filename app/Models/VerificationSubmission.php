<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'stripe_payment_intent_id',
        'payment_status',
        'status',
        'first_name',
        'last_name',
        'business_name',
        'business_description',
        'address_line_1',
        'address_line_2',
        'city',
        'town',
        'country',
        'postal_code',
        'photo_id_url',
        'proof_of_address_url',
        'selfie_url',
        'business_insurance_url',
        'certification_url',
        'branding_license_url',
        'incorporation_certificate_url',
        'admin_notes',
        'rejection_reason',
        'reviewed_at',
        'reviewed_by',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    /**
     * The FK references profiles.id per the migration definition.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id');
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'reviewed_by');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
