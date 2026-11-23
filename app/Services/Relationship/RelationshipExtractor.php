<?php

namespace App\Services\Relationship;

use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

/**
 * V4.0 — the Memory Loop, ONE AI call per settled conversation, two analyses:
 *   customer: facts delta · stage proposal (transition-validated) · sentiment · rolling summary
 *   review:   the Conversation Guardian — did WE handle it well? (stored now, surfaced in V4.4)
 *
 * Rules baked in (V4-ROADMAP-FINAL):
 *   · numbers come from math — the AI here outputs ONLY categorical values
 *   · AI may propose only the conversation-derived stages; data floors always win
 *   · fail-soft everywhere; idempotent via conversations.summarized_at
 */
class RelationshipExtractor
{
    /** The stage machine. Data-derived floors marked ●, AI-proposable marked ○. */
    public const STAGES = ['NEW', 'INTERESTED', 'QUOTED', 'NEGOTIATING', 'WAITING_BUDGET',
        'PURCHASED', 'REPEAT', 'VIP', 'DORMANT', 'LOST'];

    public const AI_PROPOSABLE = ['INTERESTED', 'NEGOTIATING', 'WAITING_BUDGET'];

    /** Allowed AI transitions: only forward movement within the pre-purchase band. */
    private const ALLOWED = [
        'NEW'            => ['INTERESTED', 'NEGOTIATING', 'WAITING_BUDGET'],
        'INTERESTED'     => ['NEGOTIATING', 'WAITING_BUDGET'],
        'QUOTED'         => ['NEGOTIATING', 'WAITING_BUDGET'],
        'NEGOTIATING'    => ['WAITING_BUDGET'],
        'WAITING_BUDGET' => ['NEGOTIATING'],
        'DORMANT'        => ['INTERESTED', 'NEGOTIATING'],   // they came back talking business
    ];

    public function enabled(Tenant $t): bool
    {
        return (bool) $t->setting('relationship_memory', false)
            && trim((string) (config('openai.api_key') ?: env('OPENAI_API_KEY', ''))) !== '';
    }

    /** Process one settled conversation. Returns true if the profile was updated. */
    /** v4.85 — a bare international phone (digits only, 7–15). "web:<sid>" and friends fail. */
    public static function realPhone(string $p): bool
    {
        return preg_match('/^\d{7,15}$/', $p) === 1;
    }

    public function process(Tenant $tenant, Conversation $c): bool
    {
        try {
            $since = $c->summarized_at;
            $msgs = Message::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('customer_phone', $c->customer_phone)
                ->when($since, fn ($q) => $q->where('created_at', '>', $since))
                // The LATEST 40, oldest first (DishNet, 23 Nov 2026): taking the first 40 summarised a long
                // chat's oldest messages, then the stamp below skipped everything after them for good.
                ->orderByDesc('created_at')->orderByDesc('id')->limit(40)
                ->get(['direction', 'sender', 'body', 'created_at'])->reverse()->values();
            if ($msgs->where('direction', 'in')->isEmpty()) {
                $c->summarized_at = now();
                $c->save();
                return false;
            }

            // v4.85 — ONLY real phone identities become customer profiles. Web-channel
            // conversations are keyed "web:<session>" (ADR-018); before this guard the extractor
            // was quietly minting junk customers like phone="web:Kx8p…" — exactly the portal
            // pollution the owner flagged. A web visitor becomes a customer through the pre-chat
            // contact form (ChatController) with a canonical phone, never through this loop.
            if (! self::realPhone((string) $c->customer_phone)) {
                $c->summarized_at = now();
                $c->save();
                return false;
            }
            $profile = CustomerProfile::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'phone' => $c->customer_phone], []
            );

            $transcript = $msgs->map(function ($m) {
                $who = $m->direction === 'in' ? 'CUSTOMER' : ('SHOP('.($m->sender ?: 'bot').')');
                return $who.': '.mb_substr((string) $m->body, 0, 300);
            })->implode("\n");

            $out = $this->callAi($this->prompt($profile, $tenant), $transcript);
            if (! is_array($out)) return false;

            $this->apply($tenant, $profile, $c, $out);

            return true;
        } catch (\Throwable $e) {
            return false;   // fail-soft: the webhook world never feels this
        }
    }

    /** Merge the AI output into profile + conversation. Public for tests. */
    public function apply(Tenant $tenant, CustomerProfile $profile, Conversation $c, array $out): void
    {
        $cust = (array) ($out['customer'] ?? []);

        // identity: fill EMPTY columns only (never overwrite what a human typed)
        $cn = trim((string) ($cust['customer_name'] ?? ''));
        if ($cn !== '' && ! trim((string) $profile->name)) $profile->name = mb_substr($cn, 0, 120);
        $sa = trim((string) ($cust['shop_area'] ?? ''));
        // Internet providers: where the SERVICE is wanted is its own fact, the latest place they gave — the
        // record's address stays what a person typed (Codex on #179).
        $isp = \App\Support\Vertical::of($tenant) === \App\Support\Vertical::ISP;
        if (! $isp && $sa !== '' && ! trim((string) $profile->address)) $profile->address = mb_substr($sa, 0, 200);

        // facts: merge (AI supplies deltas; humans can always overwrite via the 360 later)
        $facts = (array) ($profile->facts ?? []);
        if ($isp && $sa !== '') $facts[IspMemory::SITE] = mb_substr($sa, 0, 160);
        foreach ((array) ($cust['facts'] ?? []) as $k => $v) {
            $k = substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $k)), 0, 40);
            if ($k === '' || $v === null || $v === '') continue;
            if ($k === 'wanted_unavailable') {   // list-merge, capped (powers back-in-stock in V4.4)
                $facts[$k] = array_slice(array_values(array_unique(array_merge(
                    (array) ($facts[$k] ?? []), array_map('strval', (array) $v)))), -12);
            } else {
                $facts[$k] = is_scalar($v) ? mb_substr((string) $v, 0, 160) : $v;
            }
        }
        $profile->facts = $facts;

        // rolling summary (replace-style: the prompt writes the merged 5-8 lines)
        if (! empty($cust['summary']) && is_string($cust['summary'])) {
            $profile->ai_summary = mb_substr($cust['summary'], 0, 1200);
        }

        // stage: AI proposes, the transition table disposes
        $proposal = strtoupper(trim((string) ($cust['stage_proposal'] ?? '')));
        $current  = strtoupper((string) ($profile->stage ?: 'NEW'));
        if (in_array($proposal, self::AI_PROPOSABLE, true)
            && in_array($proposal, self::ALLOWED[$current] ?? [], true)) {
            $profile->stage = $proposal;
            $profile->stage_changed_at = now();
        } elseif ($profile->stage === null) {
            $profile->stage = 'NEW';
            $profile->stage_changed_at = now();
        }

        $profile->last_summarized_at = now();
        $profile->save();

        // conversation: sentiment + the Guardian review (categorical, evidence-carrying)
        $sent = strtolower(trim((string) ($cust['sentiment'] ?? '')));
        if (in_array($sent, ['positive', 'neutral', 'negative'], true)) $c->last_sentiment = $sent;

        $review = (array) ($out['review'] ?? []);
        if ($review) {
            $q = strtolower(trim((string) ($review['quality'] ?? '')));
            $c->quality = in_array($q, ['good', 'needs_attention', 'poor'], true) ? $q : null;
            $c->review = [
                'all_questions_answered' => (bool) ($review['all_questions_answered'] ?? true),
                'missed_items'   => array_slice((array) ($review['missed_items'] ?? []), 0, 8),
                'promises'       => array_slice((array) ($review['promises'] ?? []), 0, 6),
                'ended'          => (string) ($review['ended'] ?? ''),
                'quality_reasons' => array_slice((array) ($review['quality_reasons'] ?? []), 0, 6),
            ];
            $c->reviewed_at = now();
        }
        $c->summarized_at = now();
        $c->save();
    }

    private function prompt(CustomerProfile $p, ?Tenant $tenant = null): string
    {
        $known = json_encode([
            'name' => $p->name, 'segment' => $p->segment, 'stage' => $p->stage ?: 'NEW',
            'facts' => $p->facts ?: (object) [], 'summary' => $p->ai_summary ?: '',
        ], JSON_UNESCAPED_UNICODE);
        // Internet providers: remembered by service, place and need — never "which shop, so we deliver".
        if ($tenant && \App\Support\Vertical::of($tenant) === \App\Support\Vertical::ISP) {
            return IspMemory::extractorPrompt((string) $known);
        }

        return <<<SYS
You are the relationship analyst for a WhatsApp commerce shop in East Africa (customers often write Juba Arabic or mixed English). Analyze the NEW portion of one customer conversation and reply with ONLY valid JSON, no markdown:
{
 "customer": {
   "customer_name": "their name if stated or clearly inferable, else \"\"", "shop_area": "shop name/number + market or area if mentioned (e.g. \"Shop 307, Konyo Konyo\"), else \"\"", "facts": {  // ONLY newly learned/changed facts, snake_case keys. Useful keys: usual_products, brands_preferred, budget_band, decision_role, payment_behavior, delivery_prefs, preferred_contact_hours, current_goal, next_expected_action, current_objection, wanted_unavailable (array of product names they wanted but we lacked)
   },
   "stage_proposal": "INTERESTED|NEGOTIATING|WAITING_BUDGET or empty string",  // ONLY these three, only if the conversation clearly shows it
   "sentiment": "positive|neutral|negative",
   "summary": "5-8 short lines merging the PREVIOUS summary with what is new — the whole relationship at a glance"
 },
 "review": {  // judge OUR handling, not the customer
   "all_questions_answered": true|false,
   "missed_items": [ {"type":"unsent_quote|ignored_question|partial_answer|no_recommendation|unaddressed_complaint|repeated_question|callback_requested", "evidence":"short quote from the transcript", "suggested_action":"one line"} ],
   "promises": [ {"text":"what WE promised", "due_hint":"today|tomorrow|this_week|unspecified"} ],
   "ended": "resolved|abandoned|handed_to_human",
   "quality": "good|needs_attention|poor",
   "quality_reasons": ["short reason", "..."]
 }
}
Categorical values only — never invent numbers. Empty arrays/strings when nothing applies.
KNOWN SO FAR: {$known}
SYS;
    }

    /** Overridable in tests. Returns decoded JSON or null. Never throws. */
    protected function callAi(string $system, string $transcript): ?array
    {
        try {
            $resp = Http::withToken((string) (config('openai.api_key') ?: env('OPENAI_API_KEY', '')))
                ->timeout(20)->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini', 'temperature' => 0.1,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => mb_substr($transcript, 0, 12000)],
                    ],
                ]);
            if (! $resp->successful()) return null;
            $j = json_decode((string) $resp->json('choices.0.message.content'), true);

            return is_array($j) ? $j : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
