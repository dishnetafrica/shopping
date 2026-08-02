<?php

namespace App\Services\Bot;

use App\Models\Tenant;

/**
 * v4.9.2 — "ask my colleague" capture (the mechanic-human AI).
 *
 * When a customer wants a part we don't stock — on WhatsApp (the AI mechanic tells them it
 * will check with a colleague, or an order/quote line doesn't match the catalogue) or on the
 * storefront (search found nothing and they left their number) — this records ONE actionable
 * follow-up so the promise is actually kept:
 *
 *   1. a FollowupProposal (trigger `part_request`) on the AI Follow-ups screen, with a
 *      ready-to-edit draft the team sends once they have real options;
 *   2. an immediate WhatsApp alert to the owner (the "colleague" is real);
 *   3. a BotTrace so /panel/diagnostics shows the demand — over time this list IS the
 *      "what should we start stocking?" report.
 *
 * Dedupe: the same phone asking for the same part within 7 days is one request (the model can
 * re-emit its hidden line across turns; customers repeat themselves). Comparison is on a
 * normalised part key, so "Air Filter" and "air filter" collapse.
 *
 * Nothing here may ever break a reply: capture() swallows every Throwable and returns false.
 */
class PartRequestService
{
    /** Same phone + same part within this many days = one request. */
    public const DEDUPE_DAYS = 7;

    /**
     * Record a part request. Returns true when a NEW follow-up was created, false when it
     * deduped onto a recent one (or when input was unusable / capture failed).
     *
     * @param string $phone   customer's WhatsApp number (digits or raw — normalised here)
     * @param string $part    what they need, e.g. "Air filter"
     * @param string $source  'bot' | 'storefront'
     * @param string $context evidence — their message / search text (stored, max 300)
     * @param string $bike    their motorcycle if known, e.g. "TVS HLX 125"
     */
    public function capture(Tenant $tenant, string $phone, string $part, string $source, string $context = '', string $bike = ''): bool
    {
        try {
            $part  = trim(mb_substr(preg_replace('/\s+/u', ' ', (string) $part), 0, 160));
            $bike  = trim(mb_substr(preg_replace('/\s+/u', ' ', (string) $bike), 0, 60));
            $phone = preg_replace('/[^0-9]/', '', (string) $phone);
            if ($part === '' || self::normKey($part) === '' || strlen($phone) < 7) return false;

            // Dedupe — mirror of the email_request pattern, plus a per-part key (one customer may
            // legitimately request several different parts in the same week).
            $recent = \App\Models\FollowupProposal::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('customer_phone', $phone)
                ->where('trigger', 'part_request')
                ->whereIn('status', ['draft', 'pending', 'approved', 'sent'])
                ->where('created_at', '>=', now()->subDays(self::DEDUPE_DAYS))
                ->get(['trigger_reason']);
            $key = self::normKey($part);
            foreach ($recent as $r) {
                if (self::normKey(self::partFromReason((string) $r->trigger_reason)) === $key) {
                    \App\Support\BotTrace::log($tenant->id, 'part_request', $phone, 'deduped', $part);
                    return false;
                }
            }

            \App\Models\FollowupProposal::create([
                'tenant_id'      => $tenant->id,
                'customer_phone' => $phone,
                'source'         => 'bot',
                'trigger'        => 'part_request',
                'trigger_reason' => self::reason($part, $bike, $source),
                'evidence'       => mb_substr(trim($context) !== '' ? $context : $part, 0, 300),
                'draft'          => self::draft($part, $bike),
                // Numeric on purpose: the autosend command only auto-sends string confidences
                // ('high'/'medium'), so this ALWAYS waits for a human — the draft contains a
                // price/timing placeholder the team must fill in first.
                'confidence'     => 0.9,
                'status'         => 'draft',
            ]);
            \App\Support\BotTrace::log($tenant->id, 'part_request', $phone, 'captured',
                $part . ($bike !== '' ? ' | ' . $bike : '') . ' | via ' . $source);

            // The "colleague" is the owner — tell them NOW, while the customer is still warm.
            \App\Jobs\NotifyOwner::dispatch($tenant->id,
                "\u{1F50E} *Part request* — a customer wants something we don't list:\n"
                . "• Part: *{$part}*" . ($bike !== '' ? "\n• Bike: {$bike}" : '')
                . "\n• Customer: {$phone} (via " . ($source === 'storefront' ? 'the website' : 'WhatsApp') . ")\n"
                . "They were told we'll check with a colleague and come back with options. "
                . "A ready draft is waiting on the AI Follow-ups screen — fill in the price/timing and send.");

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('PartRequestService capture failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sweep an OrderCalculator quote for lines that didn't match the catalogue and record each
     * as a part request. Called at the AiBrain quote sites only (never POS — a seller typing a
     * loose name must not page the owner). Best-effort by construction: capture() never throws.
     */
    public function captureUnmatched(Tenant $tenant, ?string $phone, array $quote, string $context = ''): void
    {
        if (! $phone) return;
        foreach (($quote['lines'] ?? []) as $l) {
            if (! empty($l['matched'])) continue;
            $name = trim((string) ($l['name'] ?? ''));
            if ($name === '') continue;
            $qty  = max(1, (int) ($l['qty'] ?? 1));
            $this->capture($tenant, $phone, $name . ($qty > 1 ? " (qty {$qty})" : ''), 'bot', $context);
        }
    }

    /** Normalised comparison key: lowercase letters+digits only. Pure — harness-tested. */
    public static function normKey(string $part): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($part));
    }

    /** Machine-parseable reason line — partFromReason() must invert this. Pure. */
    public static function reason(string $part, string $bike, string $source): string
    {
        return 'Wants: ' . $part . ($bike !== '' ? ' | Bike: ' . $bike : '') . ' | via ' . $source;
    }

    /** Extract the part text back out of a reason() line (for dedupe). Pure — harness-tested. */
    public static function partFromReason(string $reason): string
    {
        if (! str_starts_with($reason, 'Wants: ')) return $reason;
        $rest = substr($reason, 7);
        $cut  = strpos($rest, ' | ');
        return $cut === false ? $rest : substr($rest, 0, $cut);
    }

    /**
     * Customer-facing draft for the AI Follow-ups screen. The team EDITS it before sending
     * (approve sends the draft to the customer on WhatsApp) — the [...] placeholder makes it
     * impossible to send absent-mindedly without real price/timing.
     */
    public static function draft(string $part, string $bike): string
    {
        return "Hi! \u{1F527} About the *{$part}*" . ($bike !== '' ? " for your {$bike}" : '')
            . " — I checked with my colleague and we can get it for you. "
            . "[PRICE + WHEN IT ARRIVES — fill in before sending] "
            . "Shall I reserve one for you?";
    }
}
