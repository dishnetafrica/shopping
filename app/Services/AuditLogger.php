<?php

namespace App\Services;

use App\Models\AuditLog;

/** Immutable audit trail. Best-effort: logging must never block or break a business action. */
class AuditLogger
{
    public static function log(int $tenantId, string $entityType, ?int $entityId, string $action,
        $actor = null, array $old = null, array $new = null, string $reason = null): void
    {
        try {
            $name = null; $uid = null;
            if (is_object($actor)) { $name = $actor->name ?? null; $uid = $actor->id ?? null; }
            elseif (is_string($actor)) { $name = $actor; }
            AuditLog::create([
                'tenant_id'=>$tenantId, 'entity_type'=>$entityType, 'entity_id'=>$entityId,
                'action'=>$action, 'actor'=>$name, 'actor_id'=>$uid,
                'old_values'=>$old ? json_encode($old) : null,
                'new_values'=>$new ? json_encode($new) : null,
                'reason'=>$reason, 'created_at'=>now(),
            ]);
        } catch (\Throwable $e) { /* never break the caller */ }
    }
}
