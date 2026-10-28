<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail for background AliExpress sync runs
 * (category sync, token refresh, product sync).
 */
class AliExpressSyncLog extends Model
{
    public const TYPE_CATEGORY_SYNC = 'category_sync';

    public const TYPE_TOKEN_REFRESH = 'token_refresh';

    public const TYPE_PRODUCT_SYNC = 'product_sync';

    public const STATUS_STARTED = 'started';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'aliexpress_sync_logs';

    protected $fillable = [
        'aliexpress_account_id',
        'type',
        'status',
        'message',
        'details',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'details' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(AliExpressAccount::class, 'aliexpress_account_id')
            ->withTrashed();
    }
}
