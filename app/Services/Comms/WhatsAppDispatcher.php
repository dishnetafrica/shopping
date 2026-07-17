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

        try {
            $res = $this->wa->forTenant($tenant)->sendText($inst, $m->to_email, $text);
            $waId = is_array($res) ? ($res['key']['id'] ?? ($res['id'] ?? null)) : null;

            $m->status              = CommMessage::ST_SENT;
            $m->provider_message_id = $waId ? (string) $waId : null;
            $m->sent_at             = now();
            $m->attempts            = $m->attempts + 1;
            $m->last_error          = null;
            $m->save();

            try {
                MessageLog::record($tenant->id, $m->to_email, $inst, 'out', 'system', $text, $waId ? (string) $waId : null);
            } catch (\Throwable $e) {
                // Transcript logging must never fail the send.
            }
        } catch (\Throwable $e) {
            $m->attempts = $m->attempts + 1;
            if ($m->attempts >= $m->max_attempts) {
                $this->fail($m, $e->getMessage());
                return;
            }
            $minutes = min(5 * (2 ** $m->attempts), 360);
            $m->status          = CommMessage::ST_RETRY;
            $m->next_attempt_at = now()->addMinutes($minutes);
            $m->last_error      = mb_substr($e->getMessage(), 0, 1000);
            $m->save();
        }
    }

    protected function fail(CommMessage $m, string $error): void
    {
        $m->status     = CommMessage::ST_FAILED;
        $m->last_error = mb_substr($error, 0, 1000);
        $m->save();
    }
}
