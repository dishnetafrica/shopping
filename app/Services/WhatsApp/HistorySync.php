<?php

namespace App\Services\WhatsApp;

use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\Tenant;

/**
 * The ONE writer that copies a WhatsApp line's stored history (Evolution) into ShopBot's transcript, so
 * the inbox shows every past conversation (DishNet, 18 Nov 2026: "if we sync old chats to our platform,
 * we get all the messages the phone has"). Used by the inbox "Sync" button (/papi/chats/sync) and by
 * `php artisan wa:sync-history {tenant}`.
 *
 * Writes rows directly, never through MessageLog::record: that is for LIVE messages and would push a
 * notification per inbound, stamp every chat "now" and mark it unread.
 *
 *   - Every row keeps its original WhatsApp time and is marked meta.imported = 'evolution'. The AI never
 *     reads imported rows as its own earlier replies (AiBrain::historyMessages): an old bot's answers —
 *     old prices, old promises — must not become today's.
 *   - Idempotent: a WhatsApp message id already stored is skipped, and so is a message ShopBot logged
 *     live without an id (same chat, direction and text within 3 minutes), so re-running, or syncing
 *     after a webhook outage, never duplicates.
 *   - A chat is keyed exactly as live messages key it: the digits of the WhatsApp chat id (a hidden
 *     "@lid" id included — EvolutionGateway::parseIncoming), so history and live chat are one customer.
 *   - A conversation it creates is marked summarised, so years of dormant chats do not all go to the
 *     AI summariser at once; a contact with no messages is listed without jumping to the top as "now".
 *   - A contact "name" with no letter in it (an id or a number) is not stored as a name.
 */
class HistorySync
{
    public function __construct(private EvolutionAdmin $evo) {}

    /** @return array{imported:int,scanned:int,skipped_known:int,contacts:int} */
    public function run(Tenant $t, int $maxPages = 40, int $pageSize = 500): array
    {
        $instance = (string) $t->whatsapp_instance;
        $existing = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->whereNotNull('wa_message_id')->pluck('wa_message_id')->flip()->all();
        $live = $this->liveIndex($t);
        $imported = 0;
        $scanned = 0;
        $known = 0;
        $convoLast = [];
        $convoIn = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $records = $this->evo->findMessages($instance, $page, $pageSize);
            if (! $records) {
                break;
            }
            $rows = [];
            foreach ($records as $m) {
                $scanned++;
                $remote = (string) data_get($m, 'key.remoteJid', '');
                if ($remote === '' || str_contains($remote, '@g.us') || str_contains($remote, 'broadcast') || str_contains($remote, '@newsletter')) {
                    continue;
                }
                $phone = preg_replace('/\D/', '', explode('@', $remote)[0]) ?? '';
                if (strlen($phone) < 6) {
                    continue;                               // 0@s.whatsapp.net: WhatsApp's own account
                }
                $waId = (string) data_get($m, 'key.id', '');
                if ($waId !== '' && isset($existing[$waId])) {
                    $known++;
                    continue;
                }
                $text = self::text(is_array($m) ? $m : []);
                if (trim($text) === '') {
                    continue;
                }
                $fromMe = (bool) data_get($m, 'key.fromMe', false);
                $ts = self::timestamp(data_get($m, 'messageTimestamp'));
                if ($ts <= 0) {
                    continue;                               // no time: cannot be placed in the thread
                }
                $dir = $fromMe ? 'out' : 'in';
                if (self::loggedLive($live, $phone, $dir, $text, $ts)) {
                    $known++;
                    continue;
                }
                $when = date('Y-m-d H:i:s', $ts);
                $rows[] = [
                    'tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => $instance,
                    'direction' => $dir, 'sender' => $fromMe ? 'bot' : 'customer', 'body' => $text,
                    'wa_message_id' => $waId ?: null, 'status' => null,
                    'meta' => json_encode(['imported' => 'evolution']),
                    'created_at' => $when, 'updated_at' => $when,
                ];
                if ($waId !== '') {
                    $existing[$waId] = true;
                }
                $imported++;
                if (! isset($convoLast[$phone]) || $convoLast[$phone] < $when) {
                    $convoLast[$phone] = $when;
                }
                if (! $fromMe && (! isset($convoIn[$phone]) || $convoIn[$phone] < $when)) {
                    $convoIn[$phone] = $when;
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                Message::withoutGlobalScopes()->insert($chunk);
            }
            if (count($records) < $pageSize) {
                break;
            }
        }

        foreach ($convoLast as $phone => $when) {
            $c = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', (string) $phone)->where('instance', $instance)->first();
            if (! $c) {
                $c = Conversation::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'customer_phone' => (string) $phone, 'instance' => $instance, 'state' => [], 'cart' => []]);
                $c->summarized_at = now();                  // dormant history: not queued for the AI summariser
            }
            if (! $c->last_message_at || $c->last_message_at->lt($when)) {
                $c->last_message_at = $when;
            }
            if (isset($convoIn[$phone]) && (! $c->last_inbound_at || $c->last_inbound_at->lt($convoIn[$phone]))) {
                $c->last_inbound_at = $convoIn[$phone];
            }
            $c->save();
        }

        // The contact list too, so every number shows in the inbox — at its own time, never "now".
        $contacts = 0;
        foreach ($this->evo->findChats($instance) as $ch) {
            $remote = (string) (data_get($ch, 'remoteJid') ?? data_get($ch, 'id') ?? '');
            if ($remote === '' || str_contains($remote, '@g.us') || str_contains($remote, 'broadcast')) {
                continue;
            }
            $phone = preg_replace('/\D/', '', explode('@', $remote)[0]) ?? '';
            if (strlen($phone) < 6) {
                continue;
            }
            $c = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)->where('instance', $instance)->first();
            if (! $c) {
                $c = Conversation::withoutGlobalScopes()->create(['tenant_id' => $t->id, 'customer_phone' => $phone, 'instance' => $instance, 'state' => [], 'cart' => []]);
                $c->summarized_at = now();
                $at = self::timestamp(data_get($ch, 'updatedAt') ?? data_get($ch, 'conversationTimestamp'));
                $c->last_message_at = $at > 0 ? date('Y-m-d H:i:s', $at) : null;
                $c->save();
            }
            $contacts++;
            $name = trim((string) (data_get($ch, 'pushName') ?? data_get($ch, 'name') ?? ''));
            if (preg_match('/\p{L}/u', $name)) {
                $cp = CustomerProfile::withoutGlobalScopes()->firstOrNew(['tenant_id' => $t->id, 'phone' => $phone]);
                if (! $cp->name) {
                    $cp->name = mb_substr($name, 0, 120);
                    $cp->save();
                }
            }
        }

        return ['imported' => $imported, 'scanned' => $scanned, 'skipped_known' => $known, 'contacts' => $contacts];
    }

    /** PURE. A WhatsApp time as unix seconds: a number, a numeric string, {low,high}, or an ISO date. */
    public static function timestamp($v): int
    {
        if (is_array($v)) {
            return (int) ($v['low'] ?? 0);
        }
        if (is_numeric($v)) {
            $n = (int) $v;

            return $n > 20000000000 ? intdiv($n, 1000) : $n;   // milliseconds → seconds
        }
        if (is_string($v) && $v !== '') {
            $t = strtotime($v);

            return $t === false ? 0 : $t;
        }

        return 0;
    }

    /** PURE. The text of one stored message; media without a caption becomes a labelled placeholder. */
    public static function text(array $m): string
    {
        $msg = $m['message'] ?? [];
        if (! is_array($msg)) {
            return '';
        }
        foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'documentWithCaptionMessage', 'editedMessage'] as $w) {
            if (isset($msg[$w]['message']) && is_array($msg[$w]['message'])) {
                $msg = $msg[$w]['message'];
            }
        }
        $t = $msg['conversation'] ?? $msg['extendedTextMessage']['text'] ?? $msg['imageMessage']['caption'] ?? $msg['videoMessage']['caption']
            ?? $msg['documentMessage']['caption'] ?? $msg['buttonsResponseMessage']['selectedDisplayText'] ?? $msg['listResponseMessage']['title']
            ?? $msg['templateButtonReplyMessage']['selectedDisplayText'] ?? null;
        if (is_string($t) && trim($t) !== '') {
            return $t;
        }

        return match (true) {
            isset($msg['imageMessage'])    => '📷 Photo',
            isset($msg['videoMessage'])    => '🎬 Video',
            isset($msg['audioMessage'])    => '🎤 Voice message',
            isset($msg['stickerMessage'])  => '🌟 Sticker',
            isset($msg['documentMessage']) => '📄 ' . ((string) (($msg['documentMessage']['fileName'] ?? '') ?: 'Document')),
            isset($msg['locationMessage']) => '📍 Location',
            isset($msg['contactMessage'])  => '👤 Contact',
            default                        => '',
        };
    }

    /** Messages ShopBot logged live (not imported): phone|direction|text => times, for the 3-minute match. */
    private function liveIndex(Tenant $t): array
    {
        $idx = [];
        foreach (Message::withoutGlobalScopes()->where('tenant_id', $t->id)->whereNull('wa_message_id')
            ->get(['customer_phone', 'direction', 'body', 'created_at', 'meta']) as $m) {
            if ((($m->meta ?? [])['imported'] ?? null) !== null) {
                continue;
            }
            $idx[self::liveKey((string) $m->customer_phone, (string) $m->direction, (string) $m->body)][] = $m->created_at?->getTimestamp() ?? 0;
        }

        return $idx;
    }

    /** PURE. Was this message already logged live (same chat, direction and text within 3 minutes)? */
    public static function loggedLive(array $live, string $phone, string $dir, string $text, int $ts): bool
    {
        foreach ($live[self::liveKey($phone, $dir, $text)] ?? [] as $at) {
            if (abs($at - $ts) <= 180) {
                return true;
            }
        }

        return false;
    }

    private static function liveKey(string $phone, string $dir, string $text): string
    {
        return $phone . '|' . $dir . '|' . md5(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }
}
