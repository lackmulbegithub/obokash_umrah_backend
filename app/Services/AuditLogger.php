<?php

namespace App\Services;

use App\Models\AuditLog;

class AuditLogger
{
    public static function log(
        ?int $actorUserId,
        string $auditableType,
        int $auditableId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?array $meta = null,
    ): void {
        AuditLog::query()->create([
            'actor_user_id' => $actorUserId,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'meta' => $meta,
        ]);
    }
}
