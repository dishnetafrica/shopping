<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Str;

/** Immutable audit trail. Best-effort: logging must never block or break a business action.
 *  Captures actor + request metadata (IP, user agent, request id) for forensic investigation. */
class AuditLogger
{
    public static function log(int $tenantId, string $entityType, ?int $entityId, string $action,
        $actor = null, array $old = null, array $new = null, string $reason = null): void
    {
        try {
            $name = null; $uid = null;
            if (is_object($actor)) { $name = $actor->name ?? null; $uid = $actor->id ?? null; }
            elseif (is_string($actor)) { $name = $actor; }

            $ip = null; $ua = null; $rid = null;
            try {
                $req = request();
                if ($req) {
                    $ip  = $req->ip();
                    $ua  = mb_substr((string) $req->userAgent(), 0, 255);
                    $rid = $req->headers->get('X-Request-Id') ?: (string) Str::uuid();
                }
            } catch (\Throwable $e) { /* console / no request */ }

            AuditLog::create([
                'tenant_id'=>$tenantId, 'entity_type'=>$entityType, 'entity_id'=>$entityId,
                'action'=>$action, 'actor'=>$name, 'actor_id'=>$uid,
                'old_values'=>$old,          // arrays — the JSONB cast encodes them
                'new_values'=>$new,
                'reason'=>$reason,
                'ip_address'=>$ip, 'user_agent'=>$ua, 'request_id'=>$rid,
                'created_at'=>now(),
            ]);
        } catch (\Throwable $e) { /* never break the caller */ }
    }
}
