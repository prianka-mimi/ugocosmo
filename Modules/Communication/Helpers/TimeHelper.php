<?php

declare(strict_types=1);

namespace Modules\Communication\Helpers;

use Carbon\Carbon;

class TimeHelper
{
    public static function formatChatTimestamp(string $timestamp): string
    {
        $time = Carbon::parse($timestamp);
        $now = now();

        if ($time->isSameDay($now)) {
            return $time->format('g:i A');
        }

        if ($time->isYesterday()) {
            return 'Yesterday';
        }

        if ($time->greaterThanOrEqualTo($now->copy()->subDays(6))) {
            return $time->format('D');
        }

        if ($time->isSameYear($now)) {
            return $time->format('M j');
        }

        return $time->format('Y');
    }
}
