<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LivestreamItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'livestream_id',
        'product_id',
        'service_id',
        'job_id',
        'event_id',
        'display_order',
    ];

    public function livestream(): BelongsTo
    {
        return $this->belongsTo(Livestream::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobListing::class, 'job_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
