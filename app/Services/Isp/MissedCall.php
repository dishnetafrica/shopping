<?php

namespace App\Services\Isp;

use App\Jobs\NotifyOwner;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sales\SalesOwnership;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\BotTrace;
use App\Support\MessageLog;
use Illuminate\Support\Facades\Cache;

/**
 * A customer tried to CALL an Internet provider's WhatsApp line (DishNet, 18 Nov 2026: "make a missed
 * WhatsApp call alert the customer's salesperson"). Nobody answers calls on a bot line, so a call used to
 * be lost: WhatsApp's own call auto-message went out (twice), the bot never knew, and nobody called back.
 *
 * Now, on the "call" event from Evolution:
 *   - the caller gets ONE message from the bot, logged in the inbox like any bot reply, saying calls are
 *     not taken here, to type their question, and that their salesperson will call back (when the office
 *     opens, by business_hours, if it is closed). Not sent while a person has the chat.
 *   - the customer's salesperson (or, with none, the owner alert numbers) gets an alert with the caller's
 *     name, number and their last message, to call them back.
 *   - staff and muted numbers are ignored; one alert per caller per 15 minutes (a caller who retries).
 *   - it waits RING_SECONDS first: a call someone answers on the phone ("accept") is not a missed call.
 *
 * Internet-provider businesses only (SalesOwnership::enabled); switch off with isp_call_alerts = false,
 * or keep the alert and drop the customer message with isp_call_reply = false. Whether calls are also
 * declined is the line's own Evolution setting (php artisan wa:call-handling).
 */
class MissedCall
{
    /** One alert per caller in this window: a caller who tries three times is one missed call. */
    public const WINDOW_MINUTES = 15;

    /** How long a call may ring before it counts as missed: someone may answer it on the phone. */
    public const RING_SECONDS = 60;

    public function __construct(private WhatsAppManager $wa, private SalesOwnership $owners) {}

    /**
     * PURE. The caller in an Evolution "call" webhook, or null when it is not a 1:1 call we act on.
     *
     * @return array{instance:string,phone:string,call_id:string,status:string,video:bool}|null
     */
    public static function parse(array $payload): ?array
    {
        $event = strtolower(str_replace('_', '.', (string) ($payload['event'] ?? '')));
        if (! in_array($event, ['call', 'call.upsert', 'calls.upsert'], true)) {
            return null;
        }
        $data = $payload['data'] ?? null;
        if (is_array($data) && array_is_list($data)) {
            $data = $data[0] ?? null;
        }
        if (! is_array($data) || ! empty($data['isGroup'])) {
            return null;
        }
        $jid = (string) ($data['from'] ?? $data['chatId'] ?? '');
        if ($jid === '' || str_contains($jid, '@g.us') || str_contains($jid, 'broadcast')) {
            return null;
        }
        // Keyed exactly as live messages are: the digits before "@" (an "@lid" id included), no ":device".
        $phone = preg_replace('/\D/', '', explode(':', explode('@', $jid)[0])[0]) ?? '';
        $status = strtolower((string) ($data['status'] ?? ''));
        if (strlen($phone) < 6) {
            return null;                                    // 0@…: WhatsApp itself
        }
        $instance = (string) ($payload['instance'] ?? $data['instance'] ?? '');

        return [
            'instance' => $instance,
            'phone'    => $phone,
            'call_id'  => (string) ($data['id'] ?? ($phone . ':' . ($data['date'] ?? ''))),
            'status'   => $status,
            'video'    => (bool) ($data['isVideo'] ?? false),
        ];
    }

    /** PURE. What the caller reads. No price, no time we cannot keep: "when the office opens" if closed. */
    public static function customerReply(?string $salesperson, ?string $opensAt): string
    {
        $who = trim((string) $salesperson) !== '' ? trim((string) $salesperson) . ' from our sales team' : 'Our sales team';

        return "Sorry we missed your call \u{1F64F} We cannot take calls on this WhatsApp number. "
            . 'Please type your question here and we will answer right away. '
            . $who . ' will also call you back' . ($opensAt !== null ? " when the office opens ({$opensAt})" : '') . '.';
    }

    /** PURE. The salesperson's alert. */
    public static function alertText(string $phone, ?string $name, bool $video, ?string $opensAt, ?string $lastText, ?string $lastDate, bool $replied, bool $hasOwner): string
    {
        $who = ($name !== null && preg_match('/\p{L}/u', $name) ? trim($name) . ' ' : '') . '+' . $phone;
        $lines = ["\u{1F4DE} *Missed WhatsApp " . ($video ? 'video ' : '') . "call* — {$who}"];
        $lines[] = 'They tried to call the business WhatsApp line.' . ($replied ? ' The bot told them you will call them back.' : '');
        if ($lastText !== null && trim($lastText) !== '') {
            $lines[] = 'Their last message' . ($lastDate ? " ({$lastDate})" : '') . ': "' . mb_substr(trim((string) preg_replace('/\s+/u', ' ', $lastText)), 0, 160) . '"';
        }
        $lines[] = 'Please call them back' . ($opensAt !== null ? " when the office opens ({$opensAt}) \u{1F319}" : '') . ($hasOwner ? '' : ' (no salesperson assigned yet)') . '.';

        return implode("\n", $lines);
    }

    /** Someone picked the call up on the phone (its "accept" event): it is not a missed call. */
    public static function markAnswered(int $tenantId, array $call): void
    {
        Cache::put("wa_call_answered:{$tenantId}:" . ($call['call_id'] ?? ''), 1, now()->addMinutes(30));
        Cache::put("wa_call_answered:{$tenantId}:phone:" . ($call['phone'] ?? ''), 1, now()->addSeconds(self::RING_SECONDS * 2));
    }

    /** Reply to the caller and alert their salesperson (see the class comment). Runs RING_SECONDS after the call. */
    public function handle(Tenant $t, array $call): void
    {
        if (! SalesOwnership::enabled($t) || ! (bool) $t->setting('isp_call_alerts', true)) {
            return;
        }
        $digits = fn ($p) => preg_replace('/\D/', '', (string) $p) ?? '';
        $phone = $digits($call['phone'] ?? '');
        $skip = array_flip(array_filter(array_map($digits, array_merge(
            (array) $t->setting('bot_muted', []), User::where('tenant_id', $t->id)->pluck('phone')->all(), $t->ownerAlertNumbers()))));
        if ($phone === '' || isset($skip[$phone])) {
            BotTrace::log($t->id, 'call', $phone, 'call_ignored', 'staff or muted number');

            return;
        }
        if (Cache::has("wa_call_answered:{$t->id}:" . ($call['call_id'] ?? '')) || Cache::has("wa_call_answered:{$t->id}:phone:{$phone}")) {
            BotTrace::log($t->id, 'call', $phone, 'call_answered', 'someone took the call');

            return;
        }
        if (! Cache::add("isp_call:{$t->id}:{$phone}", 1, now()->addMinutes(self::WINDOW_MINUTES))) {
            return;                                         // the same caller again within 15 minutes
        }

        $instance = (string) (($call['instance'] ?? '') ?: $t->whatsapp_instance);
        $name = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', $phone)->value('name');
        $owner = $this->owners->ownerForEnquiry($t, $phone, $name ?: null);
        $opens = IspSalesTurn::opensAt($t);
        $last = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)->where('direction', 'in')
            ->orderByDesc('created_at')->orderByDesc('id')->first(['body', 'created_at']);

        // The caller: one message, unless a person is handling this chat right now.
        $replied = false;
        $convo = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)->where('instance', $instance)->first();
        $state = is_array($convo?->state) ? $convo->state : [];
        $humanHasIt = $convo && ($convo->agent_active || (int) ($state['human_until'] ?? 0) > time());
        if ((bool) $t->setting('isp_call_reply', true) && ! $humanHasIt) {
            $text = self::customerReply($owner?->name, $opens);
            try {
                // From the line the call came in on — the same rule as every live reply.
                $res = $this->wa->forTenant($t)->sendText(\App\Jobs\ProcessIncomingMessage::replyInstance($t, $instance), $phone, $text);
                $replied = ! (is_array($res) && ((($res['_ok'] ?? true) === false) || isset($res['error'])));
            } catch (\Throwable $e) {
                BotTrace::log($t->id, 'call', $phone, 'call_reply_failed', mb_substr($e->getMessage(), 0, 120));
            }
            if ($replied) {
                MessageLog::record($t->id, $phone, $instance, 'out', 'bot', $text, null, null, ['via' => 'missed_call']);
                // If WhatsApp echoes it back as "sent from this phone", it is the bot's, not a person taking over.
                $c = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)->where('instance', $instance)->first();
                if ($c) {
                    $st = is_array($c->state) ? $c->state : [];
                    $st['lg_last_out'] = strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));
                    $c->state = $st;
                    $c->save();
                }
            }
        }

        $alert = self::alertText($phone, $name ?: null, (bool) ($call['video'] ?? false), $opens,
            $last?->body, $last?->created_at?->format('j M'), $replied, $owner !== null);
        $to = $owner && $digits($owner->phone) !== '' ? [$digits($owner->phone)] : $t->ownerAlertNumbers();
        foreach ($to as $n) {
            NotifyOwner::dispatch($t->id, $alert, (string) $n);
        }
        BotTrace::log($t->id, 'call', $phone, 'missed_call', ($owner ? 'to user ' . $owner->id : 'to owner numbers') . ($replied ? ', caller told' : ''));
    }
}
