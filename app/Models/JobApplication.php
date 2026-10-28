<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'applicant_id',
        'status',
        'answers',
        'is_qualified',
        'is_shortlisted',
        'cover_letter',
        'cv_resume',
    ];

    protected $casts = [
        'answers' => 'array',
        'is_qualified' => 'boolean',
        'is_shortlisted' => 'boolean',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'job_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'applicant_id');
    }
}
