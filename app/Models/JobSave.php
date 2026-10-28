<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobSave extends Model
{
    protected $fillable = ['user_id', 'job_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'user_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'job_id');
    }
}
