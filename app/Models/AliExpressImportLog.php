<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AliExpressImportLog extends Model
{
    use HasFactory;

    protected $table = 'aliexpress_import_logs';

    public const ACTION_IMPORTED = 'imported';

    public const ACTION_UPDATED = 'updated';

    public const ACTION_SKIPPED = 'skipped';

    public const ACTION_FAILED = 'failed';

    protected $fillable = [
        'aliexpress_import_id',
        'aliexpress_account_id',
        'aliexpress_product_id',
        'product_title',
        'action',
        'reason',
        'error_message',
        'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(AliExpressImport::class, 'aliexpress_import_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AliExpressAccount::class, 'aliexpress_account_id');
    }
}
