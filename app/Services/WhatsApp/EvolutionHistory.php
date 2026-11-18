<?php

namespace App\Services\WhatsApp;

/**
 * Reads a WhatsApp line's full stored history from Evolution, one conversation per customer number —
 * including what was said before ShopBot was connected (DishNet: ~7,000 messages, Jan–Nov 2026).
 *
 * parse() is PURE: one Evolution record → [phone, unix time, sent by us, text, contact name, number hidden],
 * or null for what is not a customer message (status updates, groups, channels, reactions, system records,
 * WhatsApp's own account "0@…"). Messages wrapped as disappearing/view-once/edited are unwrapped.
 *
 * A chat WhatsApp keys by a hidden id (@lid) is put under the real number when Evolution gives it.
 * Without one it is kept under the hidden id's digits, exactly as ShopBot keys that chat when the customer
 * writes (EvolutionGateway::parseIncoming), so it is the same customer in both places; it is marked
 * hidden because that id can be messaged on WhatsApp but not dialled (DishNet, 17 Nov 2026: most of the
 * line's history is such chats).
 */
class EvolutionHistory
{
    private const SYSTEM = ['protocolMessage', 'reactionMessage', 'senderKeyDistributionMessage', 'empty', 'pollUpdateMessage', 'messageContextInfo'];

    public function __construct(private EvolutionAdmin $evo) {}

    /**
     * @return array<string,array{name:string,hidden:bool,rows:list<array{0:int,1:bool,2:string}>}> phone => conversation
     */
    public function chats(string $instance, int $maxPages = 400): array
    {
        $chats = [];
        $seen = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $recs = $this->evo->findMessages($instance, $page, 500);
            if (! $recs) {
                break;
            }
            $new = 0;
            foreach ($recs as $r) {
                $id = (string) data_get($r, 'key.id');
                if ($id !== '' && isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $new++;
                $m = self::parse(is_array($r) ? $r : []);
                if ($m === null) {
                    continue;
                }
                [$phone, $ts, $me, $text, $name, $hidden] = $m;
                $chats[$phone]['rows'][] = [$ts, $me, $text];
                $chats[$phone]['hidden'] = $hidden;
                if (! $me && $name !== '') {
                    $chats[$phone]['name'] = $name;
                }
                $chats[$phone]['name'] ??= '';
            }
            if (count($recs) < 500 || $new === 0) {
                break;                                      // last page, or Evolution repeating itself
            }
        }

        return $chats;
    }

    /** PURE. @return array{0:string,1:int,2:bool,3:string,4:string,5:bool}|null */
    public static function parse(array $r): ?array
    {
        $jid = (string) ($r['key']['remoteJid'] ?? '');
        if ($jid === '' || str_ends_with($jid, '@broadcast') || str_ends_with($jid, '@g.us') || str_ends_with($jid, '@newsletter')) {
            return null;
        }
        $hidden = false;
        if (str_ends_with($jid, '@lid')) {
            $alt = (string) (($r['key']['remoteJidAlt'] ?? '') ?: ($r['key']['senderPn'] ?? ''));
            if ($alt !== '') {
                $jid = $alt;
            } else {
                $hidden = true;                             // kept under the hidden id, as ShopBot keys it
            }
        }
        $phone = preg_replace('/\D/', '', explode('@', $jid)[0]) ?? '';
        if (strlen($phone) < 6) {
            return null;                                    // "0@s.whatsapp.net" is WhatsApp's own account, not a customer
        }
        $msg = (array) ($r['message'] ?? []);
        foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'documentWithCaptionMessage', 'editedMessage'] as $w) {
            if (isset($msg[$w]['message']) && is_array($msg[$w]['message'])) {
                $msg = $msg[$w]['message'];
            }
        }
        $text = $msg['conversation'] ?? $msg['extendedTextMessage']['text'] ?? $msg['imageMessage']['caption'] ?? $msg['videoMessage']['caption']
            ?? $msg['documentMessage']['caption'] ?? $msg['buttonsResponseMessage']['selectedDisplayText'] ?? $msg['listResponseMessage']['title']
            ?? $msg['templateButtonReplyMessage']['selectedDisplayText'] ?? null;
        if ($text === null || trim((string) $text) === '') {
            $type = (string) (($r['messageType'] ?? '') ?: (array_key_first(array_diff_key($msg, ['messageContextInfo' => 1])) ?? 'empty'));
            if (in_array($type, self::SYSTEM, true)) {
                return null;
            }
            $text = '[' . str_replace('Message', '', $type) . ']';   // a photo, voice note, location… kept as a marker
        }
        $ts = $r['messageTimestamp'] ?? 0;
        $ts = is_array($ts) ? (int) ($ts['low'] ?? 0) : (int) $ts;
        $me = (bool) ($r['key']['fromMe'] ?? false);

        // A contact name with no letter in it is WhatsApp's id or a number, not a name (live, 18 Nov 2026:
        // hidden-id chats came back with the id itself as the name).
        $name = $me ? '' : trim((string) ($r['pushName'] ?? ''));
        if (! preg_match('/\p{L}/u', $name)) {
            $name = '';
        }

        return [$phone, $ts, $me, trim((string) $text), $name, $hidden];
    }
}
