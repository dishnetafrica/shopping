<?php
namespace App\Services\Bot;

use App\Jobs\NotifyOwner;
use App\Models\BusinessOpportunity;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\OpportunityTask;
use App\Models\Tenant;
use App\Services\Bot\Opportunity\OpportunityScorer;
use App\Services\Bot\Opportunity\OpportunityTypes;
use App\Services\Bot\Opportunity\SlotExtractor;
use App\Support\BotTrace;

/**
 * Business Opportunity Engine.
 *
 * Turns the platform from an order-taker into a B2B sales assistant. When a tenant has
 * opportunity_mode on and a customer trips a B2B signal (distributor / wholesale /
 * export / corporate supply / tender / ...), the engine takes over the conversation and
 * keeps it (sticky) until the lead is qualified and handed to a human. It never reverts
 * to "how many cartons?" mid-stream — that relapse was the original failure.
 *
 * Pipeline per turn: extract slots -> merge profile -> rescore + size -> persist ->
 * either ask the next qualifying question (Understand -> Qualify -> Value, price last)
 * or, once the core profile is complete, hand off to sales (lead + task + notify) and
 * stop negotiating.
 *
 * Nothing here throws into the message pipeline; on any error it returns '' and the
 * caller falls through to the normal brain.
 */
class BusinessOpportunityEngine
{
    public function __construct(
        private SlotExtractor $extractor = new SlotExtractor(),
        private OpportunityScorer $scorer = new OpportunityScorer(),
    ) {}

    /** Feature gate: per-tenant, on the existing settings JSON. */
    public function enabled(Tenant $tenant): bool
    {
        return (bool) $tenant->setting('opportunity_mode', false);
    }

    /** Is this conversation currently inside an opportunity flow? */
    public function isActive(Conversation $convo): bool
    {
        $st = is_array($convo->state) ? $convo->state : [];
        return ! empty($st['opportunity']['id']);
    }

    /**
     * Does this message trip a B2B opportunity trigger? Returns the type key or null.
     * Only considers the types the tenant has enabled.
     */
    public function detect(Tenant $tenant, string $text): ?string
    {
        $lc = ' ' . mb_strtolower(preg_replace('/\s+/', ' ', trim($text))) . ' ';
        if ($lc === '   ') return null;

        $enabled = OpportunityTypes::enabledFor((array) $tenant->setting('opportunity_types', []));
        $best = null; $bestLen = 0;
        foreach ($enabled as $type) {
            foreach ((OpportunityTypes::TRIGGERS[$type] ?? []) as $phrase) {
                if ($phrase !== '' && str_contains($lc, $phrase) && strlen($phrase) > $bestLen) {
                    $best = $type; $bestLen = strlen($phrase);
                }
            }
        }
        return $best;
    }

    /**
     * Handle one turn. Returns the reply to send the customer, or '' to defer to the
     * normal brain (e.g. nothing to do / engine disabled).
     *
     * @param string|null $detectedType set when this turn freshly tripped a trigger.
     */
    public function handle(Tenant $tenant, Conversation $convo, string $phone, string $text, ?string $name = null, ?string $detectedType = null): string
    {
        try {
            $phone = preg_replace('/[^0-9]/', '', $phone);
            $opp   = $this->resolve($tenant, $convo, $phone, $name, $detectedType);
            if (! $opp) return '';

            // Already handed off → stay calm, don't re-qualify, don't sell.
            if ($opp->handed_off_at) {
                $this->arm($convo, $opp, null);
                return $this->holdingReply($tenant, $opp);
            }

            // On a continuation turn (not the message that opened the flow) the customer
            // can opt out — so they are never trapped in qualification.
            if ($detectedType === null) {
                $exit = OpportunityTypes::detectExit($text);
                if ($exit === 'decline') {
                    $opp->update(['status' => 'lost', 'stage' => 'closed']);
                    $this->release($convo);
                    $this->trace($tenant->id, $phone, 'opportunity_declined', "#{$opp->id}");
                    return "No problem — I won't take up more of your time on that. How can I help you today?";
                }
                if ($exit !== null) {
                    // human | cancel → drop stickiness and let the normal pipeline handle it.
                    $this->release($convo);
                    $this->trace($tenant->id, $phone, 'opportunity_exit_' . $exit, "#{$opp->id}");
                    return '';
                }
            }

            $profile   = (array) ($opp->profile ?? []);
            $expecting = $this->expecting($convo);

            // If we were waiting on the buying-vs-selling clarification, capture it now.
            $st = is_array($convo->state) ? $convo->state : [];
            if (! empty($st['opportunity']['price_basis_pending'])) {
                $profile['price_basis'] = $this->readBasis($text);
                unset($st['opportunity']['price_basis_pending']);
                $convo->state = $st;
                $expecting = null; // basis answered; move on
            }

            // Extract whatever facts this message carries, merge into the profile.
            $found = $this->extractor->extract($text, $profile, $expecting);
            $newPrice = isset($found['buying_price']) && empty($profile['buying_price']);
            foreach ($found as $k => $v) {
                if ($k === 'interested_products') {
                    $profile[$k] = $this->mergeProducts($profile[$k] ?? [], $v);
                } else {
                    $profile[$k] = $v;
                }
            }
            // A decision-maker / contact answer also gives us the person's name.
            if (! $opp->customer_name && ! empty($profile['decision_maker']) && ! is_bool($profile['decision_maker'])) {
                $opp->customer_name = mb_substr((string) $profile['decision_maker'], 0, 120);
            }

            // Rescore + size.
            $scored = $this->scorer->score($opp->type, $profile);
            $opp->lead_score      = $scored['score'];
            $opp->score_breakdown = $scored['breakdown'];
            $opp->size_band       = $this->scorer->sizeBand($profile);
            $opp->profile         = $profile;
            $opp->missing         = $this->missingCore($profile);
            $opp->stage           = $opp->missing ? ($this->knowsBusiness($profile) ? 'qualify' : 'understand') : 'value';
            $opp->last_scored_at  = now();
            $opp->save();

            $this->trace($tenant->id, $phone, 'opportunity_turn', "#{$opp->id} {$opp->type} score={$opp->lead_score} missing=" . implode(',', $opp->missing ?? []));

            // First time a bare price shows up, clarify what it means before anything else.
            if ($newPrice && empty($profile['price_basis'])) {
                $st = is_array($convo->state) ? $convo->state : [];
                $st['opportunity']['price_basis_pending'] = true;
                $convo->state = $st;
                $this->arm($convo, $opp, 'buying_price');
                return OpportunityTypes::PRICE_BASIS_Q;
            }

            // Core profile complete → qualify and hand off. Stop negotiating.
            if (empty($opp->missing)) {
                return $this->handoff($tenant, $convo, $opp);
            }

            // Otherwise ask the next qualifying question.
            $step = $this->nextStep($profile, $convo);
            if ($step === null) {
                // Nothing left to usefully ask → hand off with what we have.
                return $this->handoff($tenant, $convo, $opp);
            }
            $this->arm($convo, $opp, $step['slot']);
            $this->markAsked($convo, $step['slot']);
            return $step['q'];
        } catch (\Throwable $e) {
            BotTrace::log($tenant->id, 'opp_err_' . substr(md5($e->getMessage()), 0, 8), $phone, 'opportunity_error', $e->getMessage());
            return '';
        }
    }

    // ----------------------------------------------------------------- resolve / state

    private function resolve(Tenant $tenant, Conversation $convo, string $phone, ?string $name, ?string $detectedType): ?BusinessOpportunity
    {
        $st = is_array($convo->state) ? $convo->state : [];
        $id = (int) ($st['opportunity']['id'] ?? 0);
        if ($id) {
            $opp = BusinessOpportunity::query()->where('id', $id)->where('tenant_id', $tenant->id)->first();
            if ($opp) return $opp;
        }

        // Reattach to an existing open opportunity for this phone (don't duplicate).
        $existing = BusinessOpportunity::query()
            ->where('tenant_id', $tenant->id)
            ->where('customer_phone', $phone)
            ->whereIn('status', BusinessOpportunity::OPEN_STATUSES)
            ->latest('id')->first();
        if ($existing) {
            $this->arm($convo, $existing, $this->expecting($convo));
            return $existing;
        }

        // Fresh opportunity (only on a real trigger).
        if (! $detectedType) return null;

        $opp = BusinessOpportunity::create([
            'tenant_id'      => $tenant->id,
            'conversation_id'=> $convo->id,
            'customer_phone' => $phone,
            'customer_name'  => $name ?: null,
            'type'           => $detectedType,
            'stage'          => 'understand',
            'status'         => 'qualifying',
            'lead_score'     => 0,
            'profile'        => [],
            'missing'        => $this->missingCore([]),
            'last_scored_at' => now(),
        ]);
        $this->trace($tenant->id, $phone, 'opportunity_started', "#{$opp->id} {$opp->type}");
        $this->arm($convo, $opp, null);
        return $opp;
    }

    /** Store the opportunity pointer + the slot we're expecting on the conversation. */
    private function arm(Conversation $convo, BusinessOpportunity $opp, ?string $expecting): void
    {
        $st = is_array($convo->state) ? $convo->state : [];
        $st['opportunity'] = array_merge($st['opportunity'] ?? [], [
            'id'        => $opp->id,
            'type'      => $opp->type,
            'stage'     => $opp->stage,
            'expecting' => $expecting,
        ]);
        $st['opportunity']['asked'] = $st['opportunity']['asked'] ?? [];
        $convo->state = $st;
    }

    /** Drop the sticky pointer so normal shopping can resume after handoff. */
    private function release(Conversation $convo): void
    {
        $st = is_array($convo->state) ? $convo->state : [];
        unset($st['opportunity']);
        $convo->state = $st;
    }

    private function expecting(Conversation $convo): ?string
    {
        $st = is_array($convo->state) ? $convo->state : [];
        $v = $st['opportunity']['expecting'] ?? null;
        return is_string($v) && $v !== '' ? $v : null;
    }

    private function markAsked(Conversation $convo, string $slot): void
    {
        $st = is_array($convo->state) ? $convo->state : [];
        $asked = (array) ($st['opportunity']['asked'] ?? []);
        $asked[$slot] = (int) ($asked[$slot] ?? 0) + 1;
        $st['opportunity']['asked'] = $asked;
        $convo->state = $st;
    }

    private function askedCount(Conversation $convo, string $slot): int
    {
        $st = is_array($convo->state) ? $convo->state : [];
        return (int) (($st['opportunity']['asked'] ?? [])[$slot] ?? 0);
    }

    // ----------------------------------------------------------------- qualification

    /** Core slots still missing (honours any_of groups). */
    private function missingCore(array $profile): array
    {
        $missing = [];
        foreach (OpportunityTypes::SCRIPT as $step) {
            if (empty($step['core'])) continue;
            if ($this->filled($profile, $step)) continue;
            $missing[] = $step['slot'];
        }
        return $missing;
    }

    private function filled(array $profile, array $step): bool
    {
        $slots = $step['any_of'] ?? [$step['slot']];
        foreach ($slots as $s) {
            if (! empty($profile[$s])) return true;
        }
        return false;
    }

    /** The next question to ask: first unfilled script slot we haven't exhausted. */
    private function nextStep(array $profile, Conversation $convo): ?array
    {
        foreach (OpportunityTypes::SCRIPT as $step) {
            if ($this->filled($profile, $step)) continue;
            // Give up on a slot after asking twice so we never loop forever.
            if ($this->askedCount($convo, $step['slot']) >= 2) continue;
            return $step;
        }
        return null;
    }

    private function knowsBusiness(array $profile): bool
    {
        return ! empty($profile['business_name']) || ! empty($profile['area']);
    }

    // ----------------------------------------------------------------- handoff

    private function handoff(Tenant $tenant, Conversation $convo, BusinessOpportunity $opp): string
    {
        $rep = $tenant->nextRoundRobin($tenant->leadRecipients('sales'));
        $assigned = $rep['phone'] ?? null;

        // Create the lightweight Lead record (reuses the existing sales inbox / pipeline).
        $lead = Lead::create([
            'tenant_id'       => $tenant->id,
            'customer_phone'  => $opp->customer_phone,
            'customer_name'   => $opp->customer_name,
            'company'         => $opp->profile['business_name'] ?? null,
            'intent'          => 'lead',
            'interest'        => OpportunityTypes::label($opp->type) . ' — ' . $this->profileLine($opp),
            'lead_score'      => $opp->lead_score,
            'message'         => $this->salesCard($opp),
            'source'          => 'opportunity',
            'conversation_id' => $convo->id,
            'status'          => $assigned ? 'assigned' : 'new',
            'assigned_to'     => $assigned,
        ]);

        $opp->lead_id         = $lead->id;
        $opp->assigned_to     = $assigned;
        $opp->status          = 'qualified';
        $opp->stage           = 'handoff';
        $opp->handed_off_at   = now();
        $opp->next_followup_at = $this->nextBusinessMorning();
        $opp->save();

        // A concrete task so sales opens Today's Priorities and knows whom to call first.
        OpportunityTask::create([
            'tenant_id'      => $tenant->id,
            'opportunity_id' => $opp->id,
            'title'          => 'Call ' . ($opp->customer_name ?: $opp->profile['business_name'] ?? $opp->customer_phone),
            'reason'         => $this->taskReason($opp),
            'assigned_to'    => $assigned,
            'priority'       => $opp->lead_score,
            'status'         => 'open',
            'due_at'         => $opp->next_followup_at,
        ]);

        // Notify the assigned rep (or the whole sales team if unassigned).
        $card = $this->salesCard($opp);
        if ($assigned) {
            NotifyOwner::dispatch($tenant->id, "🔥 New {$this->bandLabel($opp)} opportunity (#{$opp->id})\n\n" . $card, $assigned);
        } else {
            foreach ($tenant->leadRecipients('sales') as $r) {
                NotifyOwner::dispatch($tenant->id, "🔥 New {$this->bandLabel($opp)} opportunity (#{$opp->id}) — please assign\n\n" . $card, $r['phone']);
            }
        }

        $this->trace($tenant->id, $opp->customer_phone, 'opportunity_handed_off', "#{$opp->id} lead#{$lead->id} score={$opp->lead_score} size={$opp->size_band}");
        $this->release($convo);

        $who = $opp->customer_name ? (' ' . $opp->customer_name) : '';
        return "Thank you{$who}. You look like a serious partner — I've collected what our Sales Manager needs. "
             . "They'll review your volume and prepare the best pricing for you, and reach out very shortly.";
    }

    private function holdingReply(Tenant $tenant, BusinessOpportunity $opp): string
    {
        return "Thanks — your request is already with our Sales Manager and they'll contact you shortly. "
             . "Anything you'd like me to add to your file in the meantime?";
    }

    // ----------------------------------------------------------------- formatting

    private function salesCard(BusinessOpportunity $opp): string
    {
        $p = (array) $opp->profile;
        $lines = [
            'Type: ' . OpportunityTypes::label($opp->type),
            'Priority: ' . $this->bandLabel($opp) . " ({$opp->lead_score})",
            'Size: ' . ($opp->size_band ? ucfirst($opp->size_band) : '—'),
            'Name: ' . ($opp->customer_name ?: '—'),
            'Phone: +' . $opp->customer_phone,
            'Business: ' . ($p['business_name'] ?? '—'),
            'Area: ' . ($p['area'] ?? '—'),
            'Monthly volume: ' . (isset($p['monthly_volume']) ? $p['monthly_volume'] . ' cartons' : '—'),
            'Order frequency: ' . ($p['order_frequency'] ?? '—'),
            'Current brand: ' . ($p['current_brand'] ?? '—'),
            'Their price: ' . (isset($p['buying_price']) ? number_format((int) $p['buying_price']) . ($p['price_basis'] ? ' (' . $p['price_basis'] . ')' : '') : '—'),
            'Products: ' . (! empty($p['interested_products']) ? implode(', ', (array) $p['interested_products']) : '—'),
        ];
        return implode("\n", $lines);
    }

    private function profileLine(BusinessOpportunity $opp): string
    {
        $p = (array) $opp->profile;
        $bits = array_filter([
            $p['business_name'] ?? null,
            $p['area'] ?? null,
            isset($p['monthly_volume']) ? $p['monthly_volume'] . ' cartons/mo' : null,
        ]);
        return $bits ? implode(', ', $bits) : 'B2B enquiry';
    }

    private function taskReason(BusinessOpportunity $opp): string
    {
        $p = (array) $opp->profile;
        $bits = [];
        if (! empty($p['current_brand']) || ! empty($p['buying_price'])) {
            $bits[] = 'Competitor' . (! empty($p['current_brand']) ? ' ' . $p['current_brand'] : '') . (! empty($p['buying_price']) ? ' at ' . number_format((int) $p['buying_price']) : '');
        }
        if (! empty($p['monthly_volume'])) $bits[] = 'Potential ' . $p['monthly_volume'] . ' cartons/month';
        return $bits ? implode('. ', $bits) : 'New ' . OpportunityTypes::label($opp->type) . ' prospect';
    }

    private function bandLabel(BusinessOpportunity $opp): string
    {
        $b = $this->scorer->band((int) $opp->lead_score);
        return $b === 'Hot' ? '🔥 Hot' : $b;
    }

    private function mergeProducts($existing, $new): array
    {
        $out = array_map('strval', (array) $existing);
        foreach ((array) $new as $n) {
            $n = trim((string) $n);
            if ($n !== '' && ! in_array($n, $out, true)) $out[] = $n;
        }
        return array_values($out);
    }

    private function readBasis(string $text): string
    {
        $lc = mb_strtolower($text);
        if (str_contains($lc, 'sell') || str_contains($lc, 'customer') || str_contains($lc, 'retail')) return 'selling price';
        return 'buying price';
    }

    private function nextBusinessMorning(): \Illuminate\Support\Carbon
    {
        $t = now()->addDay()->setTime(10, 0);
        return $t;
    }

    private function trace(int $tenantId, string $phone, string $stage, string $detail): void
    {
        BotTrace::log($tenantId, 'opp_' . substr(md5($detail . microtime()), 0, 8), $phone, $stage, $detail);
    }
}
