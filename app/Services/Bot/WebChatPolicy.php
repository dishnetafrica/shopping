<?php declare(strict_types=1);

namespace App\Services\Bot;

/**
 * v4.78 (M-CHAT-1, ADR-018) — every web-chat decision that is a RULE, not IO.
 *
 * PURE. The controller reads settings/counters and does the HTTP; this class decides. That split
 * is what lets the caps, the human-takeover behaviour and the fallback be tested to death without
 * booting Laravel — the same discipline as LegacySource, PhotoCompare, ImagePolicy, TimeContext.
 *
 * Money rule: web chat spends real AI money on anonymous visitors, so the gate DEFAULTS OFF and
 * both caps fail CLOSED — over the line, the answer is a polite WhatsApp handoff, never a quiet
 * unlimited spend. Silence rule (the job's philosophy, kept): the customer never gets nothing —
 * if the brain declines to reply, a courtesy line goes back instead of an empty panel.
 */
final class WebChatPolicy
{
    public const SESSION_CAP_DEFAULT = 20;    // messages per visitor session
    public const DAY_CAP_DEFAULT     = 300;   // AI calls per tenant per day (research-cap pattern)

    /** A client-supplied session id we are willing to key storage by. */
    public static function sessionOk(string $sid): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{15,63}$/', $sid);
    }

    /** The conversation identity for a web visitor — same shape the WhatsApp flow uses. */
    public static function fromKey(string $sid): string
    {
        return 'web:' . $sid;
    }

    public const INSTANCE = 'web';

    /**
     * What to do with this message. Order matters: a disabled gate beats everything; a human
     * already handling the chat beats the caps (their conversation must not be rationed);
     * caps beat the AI call (that's the money).
     *
     * @return array{action:string, message:string, wa:bool}
     */
    public static function verdict(bool $enabled, string $botMode, bool $humanActive,
        int $sessionCount, int $sessionCap, int $dayCount, int $dayCap): array
    {
        if (! $enabled) {
            return ['action' => 'off', 'message' => '', 'wa' => false];
        }
        if ($botMode !== 'ai') {
            return ['action' => 'wrong_mode', 'wa' => true,
                'message' => 'Live chat isn\'t available right now — message us on WhatsApp and we\'ll help you there. 🙏'];
        }
        if ($humanActive) {
            return ['action' => 'human', 'wa' => false,
                'message' => 'A team member is handling your chat — replies will appear here shortly.'];
        }
        if ($sessionCount > $sessionCap) {
            return ['action' => 'session_cap', 'wa' => true,
                'message' => 'You\'ve reached today\'s chat limit here — let\'s continue on WhatsApp, your conversation carries on there. 🙏'];
        }
        if ($dayCount > $dayCap) {
            return ['action' => 'day_cap', 'wa' => true,
                'message' => 'Our chat assistant is very busy right now — message us on WhatsApp and the team will help you directly. 🙏'];
        }

        return ['action' => 'ai', 'message' => '', 'wa' => false];
    }

    /**
     * The HTTP response body. Empty collected output never reaches the customer as silence.
     *
     * @param array<int,array<string,mixed>> $collected
     */
    public static function shape(array $collected, bool $ok, array $cart, string $sid, bool $human, bool $wa, array $products = []): array
    {
        if ($collected === []) {
            $collected = [['type' => 'text',
                'text' => 'We received your message 🙏 A team member will follow up shortly.']];
        }

        return [
            'ok'         => true,
            'session_id' => $sid,
            'human'      => $human,
            'wa'         => $wa,
            'messages'   => array_values($collected),
            'cart'       => array_values($cart),
            'products'   => array_values($products),
        ];
    }

    /** v4.80 — the reply when a visitor taps "Talk to a person". WhatsApp stays offered. */
    public const HUMAN_REQUEST_MSG = 'Okay — a team member will join this chat shortly. You can keep typing here; they will see everything you write. 🙏';
}
