<?php

namespace App\Services\Comms;

use App\Models\CommMessage;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use Illuminate\Support\Facades\DB;

/**
 * Delivers one channel='whatsapp' comm_messages row through the FROZEN
 * WhatsAppManager (public API only — forTenant()->sendText(), the exact call
 * pattern of NotifyCustomerOrderReceived). Same atomic claim + backoff
 * semantics as EmailDispatcher; the channel guard in the claim query means the
 * two dispatchers can never grab each other's rows.
 *
 * On success the outbound is also written to MessageLog so the Chats inbox
 * transcript stays complete — same as every other system send.
 */
class WhatsAppDispatcher
{
    public function __construct(protected WhatsAppManager $wa)
    {
    }

    public function attempt(int $messageId): void
    {
        $claimed = DB::table('comm_messages')
            ->where('id', $messageId)
            ->where('channel', 'whatsapp')
            ->whereIn('status', [CommMessage::ST_PENDING, CommMessage::ST_RETRY])
            ->update(['status' => CommMessage::ST_SENDING, 'updated_at' => now()]);
        if (! $claimed) return;

        $m = CommMessage::withoutGlobalScope('tenant')->find($messageId);
        if (! $m) return;

        $tenant = Tenant::find($m->tenant_id);
        if (! $tenant) { $this->fail($m, 'tenant not found'); return; }

        $inst = $tenant->sendInstance();
        if (! $inst) { $this->fail($m, 'tenant has no WhatsApp instance connected'); return; }

        $text = (string) $m->text_body;
        if ($text === '') { $this->fail($m, 'empty message body'); return; }

        // Marketing rows get a last-second safety re-check — opt-out, a paused campaign, or the daily
        // cap can all change between enqueue and the wire. Transactional rows are never gated here.
        if ($m->related_type === 'campaign') {
            $reason = $this->marketingSkip($m, $tenant);
            if ($reason !== null) {
                $m->status      = CommMessage::ST_SKIPPED;
                $m->skip_reason = $reason;
                $m->last_error  = 'skipped before send: ' . $reason;
                $m->save();
                return;
            }
        }

        try {
            $res      = $this->wa->forTenant($tenant)->sendText($inst, $m->to_email, $text);
            $rejected = is_array($res) && (($res['_ok'] ?? true) === false);
            $waId     = WhatsAppStatusIngest::sendId($res);

            if ($rejected) {
                // The gateway took the HTTP call but WhatsApp REFUSED the message. This is a failed
                // send, never a sent one: marking it sent would inflate the daily counter, move the
                // per-customer frequency clock, and corrupt delivery stats — i.e. corrupt the safety
                // controls, not just the dashboard. Treat it exactly like a thrown error.
                $this->failOrRetry($m, 'provider rejected send (HTTP ' . (int) ($res['_status'] ?? 0) . ')');
                return;
            }

            $m->status              = CommMessage::ST_SENT;
            $m->provider_message_id = $waId;
            $m->sent_at             = now();
            $m->attempts            = $m->attempts + 1;
            $m->last_error          = null;
            $m->save();

            try {
                MessageLog::record($tenant->id, $m->to_email, $inst, 'out', 'system', $text, $waId);
            } catch (\Throwable $e) {
                // Transcript logging must never fail the send.
            }
        } catch (\Throwable $e) {
            $this->failOrRetry($m, $e->getMessage());
        }
    }

    /** Shared retry/backoff/fail path for both a thrown error and a provider rejection. */
    protected function failOrRetry(CommMessage $m, string $error): void
    {
        $m->attempts = $m->attempts + 1;
        if ($m->attempts >= $m->max_attempts) {
            $this->fail($m, $error);
            return;
        }
        $minutes = min(5 * (2 ** $m->attempts), 360);
        $m->status          = CommMessage::ST_RETRY;
        $m->next_attempt_at = now()->addMinutes($minutes);
        $m->last_error      = mb_substr($error, 0, 1000);
        $m->save();
    }

    /** Last-second marketing safety check; returns a skip reason or null. */
    protected function marketingSkip(CommMessage $m, Tenant $tenant): ?string
    {
        $c = \App\Models\CommCampaign::withoutGlobalScope('tenant')->find($m->related_id);
        if (! $c) {
            return null;                    // no real campaign behind it — treat as an ordinary send
        }

        return \App\Services\Comms\Marketing\CampaignSafetyGuard::blockedReason(
            $tenant, $c, (string) $m->to_email, 'send'
        );
    }

    protected function fail(CommMessage $m, string $error): void
    {
        $m->status     = CommMessage::ST_FAILED;
        $m->last_error = mb_substr($error, 0, 1000);
        $m->save();
    }
}
