<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobQualifyingQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'question',
        'expected_answer',
        'is_required',
        'display_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'display_order' => 'integer',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'job_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order', 'asc')->orderBy('id', 'asc');
    }
}
