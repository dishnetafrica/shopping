<?php

namespace App\Services\Bot\Discovery;

/**
 * Business Discovery — WhatsApp chat-export parser. Pure logic, no framework deps.
 *
 * Parses a standard exported WhatsApp .txt chat into normalized messages. Handles the common
 * date/time/sender variants (US 12h, intl 24h, bracketed), folds continuation lines, flags media
 * placeholders, and drops system notices. Owner attribution is by matching the provided owner
 * name(s)/number against the sender.
 *
 * Output rows: ['ts'=>?string, 'sender'=>string, 'body'=>string, 'from_owner'=>bool, 'media'=>bool]
 */
class WhatsAppExportParser
{
    // 12/25/23, 9:15 AM - Sender: body    |    25/12/2023, 21:15 - Sender: body
    // [25/12/2023, 21:15:30] Sender: body
    private const LINE = '/^\x{200e}?\[?(\d{1,2}[\/.]\d{1,2}[\/.]\d{2,4}),?\s+(\d{1,2}:\d{2}(?::\d{2})?\s*(?:[AaPp][Mm])?)\]?\s*[-\x{2013}]?\s*([^:]{1,60}?):\s(.*)$/u';

    private const SYSTEM = [
        'messages and calls are end-to-end encrypted',
        'created group', 'added you', 'changed the subject', 'changed this group',
        'changed their phone number', 'left', 'you deleted this message',
        'this message was deleted', 'security code changed', 'joined using',
    ];

    /**
     * @param string $raw exported chat text
     * @param string[] $ownerNames display names / numbers that belong to the business owner
     * @return array<int,array{ts:?string,sender:string,body:string,from_owner:bool,media:bool}>
     */
    public static function parse(string $raw, array $ownerNames = []): array
    {
        $owner = array_map(fn ($n) => mb_strtolower(trim($n)), array_filter($ownerNames));
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $out   = [];

        foreach ($lines as $line) {
            if (preg_match(self::LINE, $line, $m)) {
                $sender = trim($m[3]);
                $body   = trim($m[4]);
                if (self::isSystem($body) || self::isSystem($sender)) continue;

                $out[] = [
                    'ts'         => trim($m[1] . ' ' . $m[2]),
                    'sender'     => $sender,
                    'body'       => self::isMedia($body) ? '' : $body,
                    'from_owner' => self::matchesOwner($sender, $owner),
                    'media'      => self::isMedia($body),
                ];
            } elseif ($out) {
                // continuation line of the previous message
                $i = count($out) - 1;
                $extra = trim($line);
                if ($extra !== '') {
                    $out[$i]['body'] = trim($out[$i]['body'] . ' ' . $extra);
                }
            }
        }

        return $out;
    }

    private static function matchesOwner(string $sender, array $owner): bool
    {
        $s = mb_strtolower(trim($sender));
        foreach ($owner as $o) {
            if ($o !== '' && (str_contains($s, $o) || str_contains($o, $s))) return true;
        }
        return false;
    }

    private static function isMedia(string $body): bool
    {
        $b = mb_strtolower($body);
        return str_contains($b, '<media omitted>') || str_contains($b, 'image omitted')
            || str_contains($b, 'video omitted') || str_contains($b, 'sticker omitted')
            || str_contains($b, 'audio omitted') || str_contains($b, 'document omitted');
    }

    private static function isSystem(string $text): bool
    {
        $t = mb_strtolower($text);
        foreach (self::SYSTEM as $needle) {
            if (str_contains($t, $needle)) return true;
        }
        return false;
    }
}
