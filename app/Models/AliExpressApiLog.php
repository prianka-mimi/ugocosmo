<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of every AliExpress request/response.
 *
 * Persisted by AliExpressApiLogger — never written from controllers.
 * `param_keys` stores parameter KEYS only and `response_preview` is only
 * captured for failed calls, so no tokens can leak into these rows.
 */
class AliExpressApiLog extends Model
{
    // Immutable audit log — only created_at is meaningful.
    public const UPDATED_AT = null;

    protected $table = 'aliexpress_api_logs';

    protected $fillable = [
        'aliexpress_account_id',
        'method',
        'endpoint',
        'region',
        'http_status',
        'success',
        'is_sandbox',
        'error_code',
        'error_message',
        'param_keys',
        'response_preview',
        'signature_verification',
        'duration_ms',
        'created_at',
    ];

    protected $casts = [
        'http_status' => 'integer',
        'success' => 'boolean',
        'is_sandbox' => 'boolean',
        'param_keys' => 'array',
        'signature_verification' => 'array',
        'duration_ms' => 'integer',
    ];

    /** Sandbox/verification capture — the request was signed but NOT sent. */
    public function isSandbox(): bool
    {
        return (bool) $this->is_sandbox;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AliExpressAccount::class, 'aliexpress_account_id')
            ->withTrashed();
    }
}
