<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class JobTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function jobListings(): BelongsToMany
    {
        return $this->belongsToMany(JobListing::class, 'job_listing_tag', 'job_tag_id', 'job_listing_id');
    }
}
