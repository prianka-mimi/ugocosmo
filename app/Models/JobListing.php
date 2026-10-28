<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobListing extends Model
{
    use HasFactory;

    protected $fillable = [
        'poster_id',
        'title',
        'description',
        'company',
        'location',
        'salary_min',
        'salary_max',
        'job_type',
        'is_remote',
        'is_active',
        'end_date',
    ];

    protected $casts = [
        'salary_min' => 'decimal:2',
        'salary_max' => 'decimal:2',
        'is_remote' => 'boolean',
        'is_active' => 'boolean',
        'end_date' => 'date',
    ];

    public function poster(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'poster_id');
    }

    public function qualifyingQuestions(): HasMany
    {
        return $this->hasMany(JobQualifyingQuestion::class, 'job_id')->ordered();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'job_id');
    }

    public function saves(): HasMany
    {
        return $this->hasMany(JobSave::class, 'job_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(JobTag::class, 'job_listing_tag', 'job_listing_id', 'job_tag_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByPoster($query, int $profileId)
    {
        return $query->where('poster_id', $profileId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    public function scopeExpired($query)
    {
        return $query->where('end_date', '<', now()->startOfDay());
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('end_date')->orWhere('end_date', '>=', now()->startOfDay());
        });
    }

    public function scopeRemote($query)
    {
        return $query->where('is_remote', true);
    }

    public function scopeByTag($query, string $tagName)
    {
        return $query->whereHas('tags', function ($q) use ($tagName) {
            $q->where('name', $tagName);
        });
    }
}
