<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EtsyImportLog extends Model
{
    use HasFactory;

    protected $table = 'etsy_import_logs';

    public const ACTION_IMPORTED = 'imported';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_SKIPPED = 'skipped';

    public const ACTION_FAILED = 'failed';

    protected $fillable = [
        'etsy_import_id',
        'profile_id',
        'etsy_listing_id',
        'product_title',
        'action',
        'reason',
        'error_message',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(EtsyImport::class, 'etsy_import_id');
    }
}
