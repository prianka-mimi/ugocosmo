<?php

namespace App\Enums;

/**
 * Lifecycle of a background AliExpress import run.
 */
enum AliExpressImportStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Partial = 'partial';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Partial, self::Failed, self::Cancelled], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Queued, self::Processing], true);
    }
}
