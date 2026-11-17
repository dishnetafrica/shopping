<?php

namespace App\Services\WhatsApp;

/**
 * Reads a WhatsApp line's full stored history from Evolution, one conversation per customer number —
 * including what was said before ShopBot was connected (DishNet: ~7,000 messages, Jan–Nov 2026).
 *
 * parse() is PURE: one Evolution record → [phone, unix time, sent by us, text, contact name], or null for
 * what is not a customer message (status updates, groups, channels, reactions, system records). Messages
 * wrapped as disappearing/view-once/edited are unwrapped; a chat WhatsApp keys by a hidden id (@lid) is
 * put under the real number when Evolution gives it.
 */
class EvolutionHistory
{
    private const SYSTEM = ['protocolMessage', 'reactionMessage', 'senderKeyDistributionMessage', 'empty', 'pollUpdateMessage', 'messageContextInfo'];

    public function __construct(private EvolutionAdmin $evo) {}

    /**
     * @return array<string,array{name:string,rows:list<array{0:int,1:bool,2:string}>}> phone => conversation
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
                [$phone, $ts, $me, $text, $name] = $m;
                $chats[$phone]['rows'][] = [$ts, $me, $text];
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

    /** PURE. @return array{0:string,1:int,2:bool,3:string,4:string}|null */
    public static function parse(array $r): ?array
    {
        $jid = (string) ($r['key']['remoteJid'] ?? '');
        if ($jid === '' || str_ends_with($jid, '@broadcast') || str_ends_with($jid, '@g.us') || str_ends_with($jid, '@newsletter')) {
            return null;
        }
        if (str_ends_with($jid, '@lid')) {
            $alt = (string) (($r['key']['remoteJidAlt'] ?? '') ?: ($r['key']['senderPn'] ?? ''));
            if ($alt === '') {
                return null;                                // a hidden id with no number: cannot be contacted
            }
            $jid = $alt;
        }
        $phone = preg_replace('/\D/', '', explode('@', $jid)[0]) ?? '';
        if ($phone === '') {
            return null;
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

        return [$phone, $ts, $me, trim((string) $text), $me ? '' : trim((string) ($r['pushName'] ?? ''))];
    }
}
