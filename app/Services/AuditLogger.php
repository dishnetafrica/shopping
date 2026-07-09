<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Immutable audit trail. Best-effort: logging must never block or break a business action,
 *  but a failure IS reported to the application log so operations can detect audit outages.
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
                    $ip = $req->ip();
                    $ua = mb_substr((string) $req->userAgent(), 0, 255);
                    // Prefer the id the RequestId middleware assigned for THIS request, so the
                    // audit row correlates with log lines, Sentry, and the X-Request-Id response.
                    $rid = (string) ($req->attributes->get('request_id')
                        ?: $req->headers->get('X-Request-Id')
                        ?: Str::uuid());
                }
            } catch (\Throwable $e) { /* console / no request context */ }

            AuditLog::create([
                'tenant_id'=>$tenantId, 'entity_type'=>$entityType, 'entity_id'=>$entityId,
                'action'=>$action, 'actor'=>$name, 'actor_id'=>$uid,
                'old_values'=>$old, 'new_values'=>$new, 'reason'=>$reason,
                'ip_address'=>$ip, 'user_agent'=>$ua, 'request_id'=>$rid,
                'created_at'=>now(),
            ]);
        } catch (\Throwable $e) {
            // Never break the caller — but make the failure DISCOVERABLE in the logs.
            try {
                Log::error('Audit logging failed', [
                    'exception' => $e->getMessage(),
                    'entity'    => $entityType,
                    'entity_id' => $entityId,
                    'action'    => $action,
                    'tenant_id' => $tenantId,
                ]);
            } catch (\Throwable $ignore) { /* logging itself unavailable */ }
        }
    }
}
