<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\User;

/**
 * Activity logger: records who did what in the system. The site must never
 * delete log rows — the history of actions must stay complete.
 */
class ActivityLogger
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function log(
        string $action,
        string $summary,
        ?array $details = null,
        ?User $user = null,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $ip = null,
    ): ActivityLog {
        // خلاصه باید در ستون summary جا شود؛ جزئیات کامل در details می‌ماند
        $summary = mb_substr($summary, 0, 200);

        return ActivityLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'summary' => $summary,
            'details' => $details,
            'ip_address' => $ip,
        ]);
    }
}
