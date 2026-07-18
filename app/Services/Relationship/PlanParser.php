<?php

namespace App\Services\Relationship;

/**
 * V4.2 — parse the model's <<PLAN {json}>> preamble (same hidden machine-block
 * idiom as <<ORDER>> and <<QUOTE>>). One call thinks AND speaks:
 *   {"purpose":"sell|solve|ask|escalate|wait|silent","escalate_reason":"...","note":"..."}
 * No plan block → passthrough (fully backward compatible). Invalid values → the
 * block is stripped and ignored (a malformed plan can never break a reply).
 */
class PlanParser
{
    public const PURPOSES = ['sell', 'solve', 'ask', 'escalate', 'wait', 'silent'];

    public const ESCALATION_REASONS = ['angry_customer', 'negotiation', 'credit_request',
        'large_quote', 'vip_active', 'technical_problem', 'repeated_misunderstanding'];

    /** @return array{plan: ?array, reply: string} */
    public static function parse(string $raw): array
    {
        if (! preg_match('/<<PLAN\s*(\{.*?\})\s*>>/s', $raw, $m)) {
            return ['plan' => null, 'reply' => $raw];
        }
        $reply = trim(str_replace($m[0], '', $raw));
        $j = json_decode($m[1], true);
        if (! is_array($j)) return ['plan' => null, 'reply' => $reply];

        $purpose = strtolower(trim((string) ($j['purpose'] ?? '')));
        if (! in_array($purpose, self::PURPOSES, true)) return ['plan' => null, 'reply' => $reply];

        $plan = ['purpose' => $purpose];
        $reason = strtolower(trim((string) ($j['escalate_reason'] ?? '')));
        if ($purpose === 'escalate') {
            $plan['escalate_reason'] = in_array($reason, self::ESCALATION_REASONS, true)
                ? $reason : 'repeated_misunderstanding';
        }
        $note = trim((string) ($j['note'] ?? ''));
        if ($note !== '') $plan['note'] = mb_substr($note, 0, 200);

        return ['plan' => $plan, 'reply' => $reply];
    }

    /** The system-prompt addendum that teaches the model to think first. */
    public static function instructions(): string
    {
        return "\n\nBEFORE your reply, on its own line, output a hidden plan block (the customer never sees it):\n"
            ."<<PLAN {\"purpose\":\"sell|solve|ask|escalate|wait|silent\",\"escalate_reason\":\"only when escalating: angry_customer|negotiation|credit_request|large_quote|vip_active|technical_problem|repeated_misunderstanding\",\"note\":\"optional, max 15 words\"}>>\n"
            ."Choose the purpose honestly: sell (move a sale forward), solve (help/answer), ask (one clarifying question), "
            ."escalate (a human must take this — then write a short polite handoff as your reply), "
            ."wait (the ball is in their court — output the plan block and NOTHING else), "
            ."silent (any message would hurt the relationship — plan block and NOTHING else). "
            ."Silence is a professional choice, not a failure.";
    }
}
