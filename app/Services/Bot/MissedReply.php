<?php

namespace App\Services\Bot;

/**
 * Missed-reply recovery — WHEN a customer whose message got no reply should get a late one. PURE (no
 * framework), so the rules are proven by qa/missed_reply.php; bot:recover-missed does the I/O.
 *
 * A reply goes missing when WhatsApp drops while we answer (the send times out and the retry is
 * dropped as a duplicate on purpose — one message, one reply). The chat then stays "unread": no
 * bot, staff or phone reply has reached the customer since their last message. A late reply is sent
 * only when nobody else is handling the chat, the message is neither too fresh (the normal job may
 * still be working on it) nor too old, the number is not muted, it was not already retried, and it
 * holds words to answer. Each customer message is retried at most `max_tries` times.
 *
 * "Unread" alone is not enough: the bot may have CHOSEN not to answer ("ok thanks", a muted number, a
 * chat a person took over). A message that came in while the bot was paused for someone replying from the
 * phone (HELD) is different: once the pause ends with no reply from anyone, it is answered late too.
 * So a late reply is sent only when the bot's processing of that message never finished (`bot_finished`
 * false — no ai_replied / ai_no_reply / genuine skip in its step log).
 */
final class MissedReply
{
    public const DEFAULTS = ['wait_min' => 3, 'window_min' => 1440, 'max_tries' => 3];

    /**
     * The bot stood down because someone replied from the phone moments before (human_until). That is a
     * pause, not an answer: if nobody replies before the pause ends, the customer is answered late (DishNet,
     * 23 Nov 2026 — "Seralomin@gmail.com", sent by a customer ready to pay cash, waited two days).
     */
    public const HELD = 'owner is handling this chat (recent manual reply)';

    /**
     * Why this chat must NOT get a late reply now — or null when it should.
     *
     * @param array{unread:int, agent_active:bool, last_inbound_at:int, human_until:int, muted:bool,
     *              text:string, done_for:int, tries_for:int, tries:int, bot_finished:bool} $chat
     *        done_for / tries_for: the last_inbound_at a previous recovery attempt was about (0 = none).
     * @param array{wait_min?:int, window_min?:int, max_tries?:int} $cfg
     */
    public static function skipReason(array $chat, int $now, array $cfg = []): ?string
    {
        $cfg += self::DEFAULTS;
        $wait = max(2, (int) $cfg['wait_min']) * 60;
        $window = max((int) $cfg['wait_min'] + 1, (int) $cfg['window_min']) * 60;
        $in = (int) $chat['last_inbound_at'];

        if ((int) $chat['unread'] <= 0) {
            return 'answered';
        }
        if ($chat['agent_active']) {
            return 'a person is handling this chat';
        }
        if ((int) $chat['human_until'] > $now) {
            return 'replied from the phone recently';
        }
        if ($chat['muted']) {
            return 'number is muted';
        }
        if (! empty($chat['bot_finished'])) {
            return 'the bot already handled it (answered or chose not to)';
        }
        if ($in <= 0 || $in > $now - $wait) {
            return 'too recent — the normal reply may still be on its way';
        }
        if ($in < $now - $window) {
            return 'too old';
        }
        if ((int) $chat['done_for'] === $in) {
            return 'already recovered';
        }
        if ((int) $chat['tries_for'] === $in && (int) $chat['tries'] >= max(1, (int) $cfg['max_tries'])) {
            return 'gave up after ' . (int) $chat['tries'] . ' tries';
        }
        if (! self::hasWords((string) $chat['text'])) {
            return 'no text to answer';
        }

        return null;
    }

    /**
     * Did the bot finish handling the customer's latest message? From its step log (bot_events) since that
     * message: a reply, a deliberate no-reply, or a genuine skip. "duplicate message id" is NOT finishing —
     * it is the automatic retry of a reply that failed, dropped on purpose.
     *
     * @param array<int,array{stage:string, detail:?string}> $events
     */
    public static function botFinished(array $events): bool
    {
        foreach ($events as $e) {
            $stage = (string) ($e['stage'] ?? '');
            if (in_array($stage, ['ai_replied', 'ai_no_reply', 'paused', 'missed_replied'], true)) {
                return true;
            }
            // A pause for a person on the phone is not an answer (HELD): the pause and "answered since" decide.
            if ($stage === 'skipped' && ! in_array((string) ($e['detail'] ?? ''), ['duplicate message id', self::HELD], true)) {
                return true;
            }
        }

        return false;
    }

    /** Did the bot stand down for this message only because someone had just replied from the phone? */
    public static function held(array $events): bool
    {
        foreach ($events as $e) {
            if ((string) ($e['stage'] ?? '') === 'skipped' && (string) ($e['detail'] ?? '') === self::HELD) {
                return true;
            }
        }

        return false;
    }

    /** A real message, not empty and not only a placeholder such as "[image]" or "[voice note]". */
    public static function hasWords(string $text): bool
    {
        $t = trim($text);

        return $t !== '' && ! preg_match('/^\[[^\]]*\]$/u', $t);
    }
}
