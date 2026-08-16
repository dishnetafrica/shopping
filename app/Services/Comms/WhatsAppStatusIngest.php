<?php declare(strict_types=1);

namespace App\Services\Comms;

use App\Models\CommEvent;
use App\Models\CommMessage;
use App\Models\CommSuppression;
use Illuminate\Support\Facades\DB;

/**
 * Joins WhatsApp delivery/read/reply signals back to the comm_messages row that sent them — the gap
 * that left delivered_at/opened_at/replied_at unpopulated and campaign stats misleading.
 *
 * The join key is `provider_message_id`, captured at send time. Both providers report it differently,
 * so `sendId()` normalises the send result and the two parsers normalise the status callbacks:
 *   - Evolution: messages.update events, id at key.id, status SERVER_ACK/DELIVERY_ACK/READ/ERROR.
 *   - Cloud API (Meta): entry[].changes[].value.statuses[], id at .id, status sent/delivered/read/failed.
 *
 * Idempotent: a timestamp is only stamped when it was null, so repeated webhooks don't rewrite history
 * and don't emit duplicate audit events. Status is never flipped sent→failed after the wire — the send
 * already happened and must keep counting against the daily cap; a failed *delivery* is recorded as an
 * event, not by un-sending the row.
 */
final class WhatsAppStatusIngest
{
    /** Normalise the provider id from a sendText() result across Evolution and Cloud API shapes. */
    public static function sendId($res): ?string
    {
        if (! is_array($res)) {
            return null;
        }
        $id = $res['key']['id']              // Evolution
            ?? ($res['messages'][0]['id'] ?? null)   // Cloud API (Meta)
            ?? ($res['id'] ?? null);          // defensive fallback

        return $id !== null && $id !== '' ? (string) $id : null;
    }

    /** Map a provider-specific status token to our vocabulary: delivered | read | failed | null(ignore). */
    public static function normaliseStatus(string $raw): ?string
    {
        return match (strtoupper($raw)) {
            'DELIVERY_ACK', 'DELIVERED' => 'delivered',
            'READ', 'PLAYED'            => 'read',
            'ERROR', 'FAILED'           => 'failed',
            default                     => null,          // SERVER_ACK / sent / pending — nothing to track
        };
    }

    /** Apply one normalised status to the matching comm_messages row (idempotent). */
    public static function apply(int $tenantId, string $providerId, string $normStatus, string $provider, array $raw = []): void
    {
        if ($providerId === '') {
            return;
        }
        $m = CommMessage::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('channel', 'whatsapp')
            ->where('provider_message_id', $providerId)
            ->first();
        if (! $m) {
            return;
        }

        $changed = false;
        if ($normStatus === 'delivered' && $m->delivered_at === null) {
            $m->delivered_at = now();
            $changed = true;
            self::event($tenantId, (int) $m->id, 'delivered', $provider, $raw);
        } elseif ($normStatus === 'read') {
            // Read implies delivered; backfill delivered_at if the delivered ack was missed.
            if ($m->delivered_at === null) {
                $m->delivered_at = now();
            }
            if ($m->opened_at === null) {
                $m->opened_at = now();     // WhatsApp "read" reuses the existing opened_at column
                $changed = true;
                self::event($tenantId, (int) $m->id, 'opened', $provider, $raw);
            }
        } elseif ($normStatus === 'failed' && $m->last_error === null) {
            $m->last_error = 'provider reported delivery failure';
            $changed = true;
            self::event($tenantId, (int) $m->id, 'failed', $provider, $raw);
        }

        if ($changed || $m->isDirty()) {
            $m->save();
        }
    }

    /** Evolution messages.update: a list (or single) of update objects already resolved to a tenant. */
    public static function fromEvolutionUpdate(int $tenantId, string $providerId, string $rawStatus, array $raw = []): void
    {
        $norm = self::normaliseStatus($rawStatus);
        if ($norm !== null) {
            self::apply($tenantId, $providerId, $norm, 'evolution', $raw);
        }
    }

    /** Meta Cloud API status callback: entry[].changes[].value.statuses[]. */
    public static function fromCloud(int $tenantId, array $payload): void
    {
        foreach ((array) data_get($payload, 'entry', []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                foreach ((array) data_get($change, 'value.statuses', []) as $st) {
                    if (! is_array($st)) {
                        continue;
                    }
                    $id   = (string) ($st['id'] ?? '');
                    $norm = self::normaliseStatus((string) ($st['status'] ?? ''));
                    if ($id !== '' && $norm !== null) {
                        self::apply($tenantId, $id, $norm, 'cloud', $st);
                    }
                }
            }
        }
    }

    /**
     * A customer replied — attribute it to the most recent marketing message we sent them (within a
     * short window) that hasn't already been credited a reply. Powers the campaign reply-rate metric.
     */
    public static function recordReply(int $tenantId, string $phone, int $windowDays = 7): void
    {
        $digits = CommSuppression::normalise($phone, 'whatsapp');
        if ($digits === '') {
            return;
        }

        $m = CommMessage::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('channel', 'whatsapp')
            ->where('related_type', 'campaign')
            ->where('to_email', $digits)
            ->whereNull('replied_at')
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', now()->subDays($windowDays))
            ->orderByDesc('sent_at')
            ->first();
        if (! $m) {
            return;
        }

        $m->replied_at = now();
        $m->save();
        self::event($tenantId, (int) $m->id, 'replied', 'inbound', []);
    }

    private static function event(int $tenantId, int $messageId, string $event, string $provider, array $payload): void
    {
        try {
            DB::table('comm_events')->insert([
                'tenant_id'   => $tenantId,
                'message_id'  => $messageId,
                'event'       => $event,
                'provider'    => $provider,
                'payload'     => json_encode($payload),
                'occurred_at' => now(),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            // Audit event best-effort — never fail status ingestion because the log write hiccuped.
        }
    }
}
