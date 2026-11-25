<?php

namespace App\Services\WhatsApp;

/**
 * PURE. Is a WhatsApp line receiving messages late? (DishNet, 24 Nov 2026: for a day every customer message
 * reached ShopBot about 7 hours after it was sent. The line said "open" the whole time, so the watchdog saw
 * nothing; re-linking the line fixed it.)
 *
 * The line's own store (Evolution findMessages) holds what WhatsApp delivered to it. A customer's plain text
 * message that it holds but ShopBot never received (no "queued" step for its id) after LATE_MINUTES is
 * late. MIN_MISSING or more in the last WINDOW_HOURS = the line is late: alert the business.
 *
 * Only plain customer text counts — what the webhook always passes on. Stickers, reactions, edits, disappearing
 * messages, groups, statuses and our own messages are never counted, so they can never raise a false alarm.
 */
final class DeliveryLag
{
    public const LATE_MINUTES = 10;

    public const WINDOW_HOURS = 3;

    public const MIN_MISSING = 2;

    /** Most pages of the line's store read per check (Codex on #180: a busy line has more than one). */
    public const MAX_PAGES = 8;

    /**
     * PURE. Read another page? Only while the page was full of records and its newest is still inside the
     * window (the store lists newest first).
     *
     * @param list<array> $page
     */
    public static function morePages(array $page, int $pageSize, int $now): bool
    {
        if (count($page) < $pageSize) {
            return false;
        }
        $newest = 0;
        foreach ($page as $m) {
            $newest = max($newest, is_array($m) ? self::seconds($m['messageTimestamp'] ?? null) : 0);
        }

        return $newest >= $now - self::WINDOW_HOURS * 3600;
    }

    /**
     * PURE. The customers' plain text messages in the line's store.
     *
     * @param list<array> $records Evolution findMessages records
     * @return list<array{id:string,ts:int}>
     */
    public static function customerTexts(array $records): array
    {
        $out = [];
        foreach ($records as $m) {
            if (! is_array($m)) {
                continue;
            }
            $jid = (string) ($m['key']['remoteJid'] ?? '');
            if ($jid === '' || ! empty($m['key']['fromMe']) || str_contains($jid, '@g.us') || str_contains($jid, 'broadcast') || str_contains($jid, '@newsletter')) {
                continue;
            }
            // Exactly what the webhook reads (EvolutionGateway::parseIncoming): top-level text only. A disappearing
            // message wraps its text, the webhook never passes it on, so it must not count (Codex on #180).
            $msg = is_array($m['message'] ?? null) ? $m['message'] : [];
            $text = $msg['conversation'] ?? $msg['extendedTextMessage']['text'] ?? null;
            $id = (string) ($m['key']['id'] ?? '');
            $ts = self::seconds($m['messageTimestamp'] ?? null);
            if (! is_string($text) || trim($text) === '' || $id === '' || $ts <= 0) {
                continue;
            }
            $out[$id] = ['id' => $id, 'ts' => $ts];         // the store can hold a message twice
        }

        return array_values($out);
    }

    /**
     * PURE. Of the messages old enough to have arrived (LATE_MINUTES) and recent enough to matter (WINDOW_HOURS),
     * how many never reached ShopBot, and how long the oldest of them has waited.
     *
     * @param list<array{id:string,ts:int}> $messages
     * @param array<string,bool>            $received ids ShopBot received
     * @return array{checked:int,missing:int,oldest_min:int}
     */
    public static function assess(array $messages, array $received, int $now): array
    {
        $checked = 0;
        $missing = 0;
        $oldest = 0;
        foreach ($messages as $m) {
            $age = $now - (int) $m['ts'];
            if ($age < self::LATE_MINUTES * 60 || $age > self::WINDOW_HOURS * 3600) {
                continue;
            }
            $checked++;
            if (! isset($received[$m['id']])) {
                $missing++;
                $oldest = max($oldest, intdiv($age, 60));
            }
        }

        return ['checked' => $checked, 'missing' => $missing, 'oldest_min' => $oldest];
    }

    /** PURE. Late enough to tell the business. */
    public static function late(array $a): bool
    {
        return (int) ($a['missing'] ?? 0) >= self::MIN_MISSING;
    }

    /** PURE. What the business reads. */
    public static function alertText(string $business, string $line, array $a): string
    {
        $wait = (int) $a['oldest_min'] >= 120 ? round($a['oldest_min'] / 60, 1) . ' hours' : $a['oldest_min'] . ' minutes';

        return "\u{26A0}\u{FE0F} *{$business}: WhatsApp {$line} is receiving messages late.*\n"
            . "{$a['missing']} customer messages from the last " . self::WINDOW_HOURS . " hours are on the line but have not reached ShopBot (the oldest has waited {$wait}), so the bot has not answered them.\n"
            . "Fix: restart the line in the Evolution manager. If messages are still late after 15 minutes, re-link it: log it out and scan the QR code again from the phone (WhatsApp → Linked devices).";
    }

    /** PURE. WhatsApp's time in seconds (numbers, milliseconds, or the {low,high} shape). */
    private static function seconds($v): int
    {
        if (is_array($v)) {
            return (int) ($v['low'] ?? 0);
        }
        if (is_numeric($v)) {
            $n = (int) $v;

            return $n > 20000000000 ? intdiv($n, 1000) : $n;
        }

        return 0;
    }
}
