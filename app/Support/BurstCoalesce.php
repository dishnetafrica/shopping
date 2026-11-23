<?php

namespace App\Support;

/**
 * PURE. Burst coalescing — should this customer message stay silent because a newer one will answer it?
 *
 * The webhook numbers each plain-text message from a customer (1, 2, 3 …) and delays its job a few seconds;
 * the newest message answers the whole burst, reading the older ones from the chat history.
 *
 * That only works when the newest job runs LAST. Live, 23 Nov 2026: "Around Juba" (1) and "But how much does
 * everything cost?" (2) arrived in the same second; job 2 ran first and answered without seeing "Around Juba"
 * (its job had not logged it yet), then job 1 saw "a newer message exists" and stayed silent — the customer
 * waited six minutes for the missed-reply recovery. Six such cases in one day.
 *
 * So an older message stays silent only while the newest has NOT started: once it has, it can no longer see
 * this message, and this message is answered on its own.
 */
final class BurstCoalesce
{
    /**
     * @param int $mySeq   this message's number in the burst (0 = not coalesced)
     * @param int $latest  the newest number handed out for this customer
     * @param int $started the highest number whose job has already gone past this check
     */
    public static function silent(int $mySeq, int $latest, int $started): bool
    {
        if ($started > $latest) {
            $started = 0;                                   // left from an earlier burst (the numbering restarted)
        }

        return $mySeq > 0 && $latest > $mySeq && $started < $latest;
    }

    /**
     * The highest number that has started, after this one starts. A stale higher mark is replaced; a job
     * from an earlier burst (its number is above the current count) leaves this burst's mark alone. The
     * webhook also clears the mark whenever it starts a new count (Codex on #178).
     */
    public static function started(int $mySeq, int $started, int $latest): int
    {
        if ($mySeq > $latest) {
            return $started;
        }

        return $started > $latest ? $mySeq : max($mySeq, $started);
    }
}
