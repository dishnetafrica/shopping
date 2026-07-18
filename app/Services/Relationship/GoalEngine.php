<?php

namespace App\Services\Relationship;

use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\FollowupProposal;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;

/**
 * V4.1 — "Who needs me today?" The morning brain of the virtual salesperson.
 * Deterministic triggers decide WHEN (quote unanswered 48h · reorder lapse ·
 * dormant past buyer · a promise we made is due). AI drafts WHAT (with the
 * profile block; deterministic template if AI is unavailable — a candidate is
 * never lost to an outage). Humans decide WHO GETS SENT, in the inbox.
 *
 * Anti-spam is structural, not aspirational:
 *   · ONE open proposal per customer at a time, across ALL triggers
 *   · global touch cap: no candidate if ANY engine contacted them < N days ago
 *     (SalesCadence now stamps last_contacted_at too — the cap sees everything)
 */
class GoalEngine
{
    public function enabled(Tenant $t): bool
    {
        return (bool) $t->setting('followups_ai', false);
    }

    /** Generate today's proposals for a tenant. Returns count created. */
    public function plan(Tenant $t, int $cap = 20): int
    {
        $made = 0;
        foreach ($this->candidates($t) as $cand) {
            if ($made >= $cap) break;
            if ($this->blocked($t, $cand['phone'])) continue;

            $draft = $this->draft($t, $cand) ?? $this->template($t, $cand);
            FollowupProposal::create([
                'tenant_id' => $t->id, 'customer_phone' => $cand['phone'],
                'source' => 'goal_engine', 'trigger' => $cand['trigger'],
                'trigger_reason' => $cand['reason'], 'evidence' => $cand['evidence'] ?? null,
                'draft' => $draft, 'confidence' => $cand['confidence'],
                'expected_outcome' => $cand['outcome'] ?? null, 'status' => 'draft',
            ]);
            $made++;
        }

        return $made;
    }

    /** Deterministic candidate generators — the WHEN belongs to data. */
    public function candidates(Tenant $t): array
    {
        $out = [];

        // 1) QUOTE CHASE — a quotation went out, 2-14 days ago, never became an order.
        $quotes = Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->whereNull('order_id')
            ->whereBetween('created_at', [now()->subDays(14), now()->subHours(48)])
            ->orderByDesc('created_at')->limit(60)->get();
        foreach ($quotes as $q) {
            $phone = preg_replace('/[^0-9]/', '', (string) $q->customer_phone);
            if ($phone === '') continue;
            $days = (int) now()->diffInDays($q->created_at);
            $out[] = ['phone' => $phone, 'trigger' => 'quote_chase',
                'reason' => "Quotation {$q->quote_no} sent {$days}d ago — no order yet",
                'evidence' => "quote {$q->quote_no} · total {$q->total} {$q->currency} · {$days} days old",
                'confidence' => $days <= 5 ? 'high' : 'medium',
                'outcome' => 'Convert the open quotation'];
        }

        // 2) REORDER — repeat-family customers past their usual cycle (but not gone cold).
        $reorderDays = max(3, (int) $t->setting('sales_reorder_days', 21));
        $buyers = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->whereIn('stage', ['PURCHASED', 'REPEAT', 'VIP'])->limit(400)->get();
        foreach ($buyers as $p) {
            $last = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->where('customer_phone', $p->phone)->max('created_at');
            if (! $last) continue;
            $days = (int) now()->diffInDays($last);
            if ($days < $reorderDays || $days > 90) continue;
            $out[] = ['phone' => $p->phone, 'trigger' => 'reorder',
                'reason' => "Usually buys every ~{$reorderDays}d — last order {$days}d ago",
                'evidence' => "last order {$days} days ago · stage {$p->stage}",
                'confidence' => $p->stage === 'VIP' ? 'high' : 'medium',
                'outcome' => 'A routine reorder'];
        }

        // 3) WINBACK — dormant customers who HAVE bought before.
        $dormant = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->where('stage', 'DORMANT')->limit(200)->get();
        foreach ($dormant as $p) {
            $orders = Order::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->where('customer_phone', $p->phone)->count();
            if ($orders < 1) continue;
            $out[] = ['phone' => $p->phone, 'trigger' => 'winback',
                'reason' => 'Past buyer gone quiet (DORMANT)',
                'evidence' => "{$orders} past order(s), now dormant",
                'confidence' => 'low',
                'outcome' => 'Reopen the relationship'];
        }

        // 4) PROMISE DUE — the Guardian recorded a promise WE made; hold ourselves to it.
        $convos = Conversation::withoutGlobalScope('tenant')->where('tenant_id', $t->id)
            ->whereNotNull('review')->where('reviewed_at', '>', now()->subDays(7))->limit(200)->get();
        foreach ($convos as $c) {
            foreach ((array) (($c->review['promises'] ?? [])) as $pr) {
                $txt = trim((string) ($pr['text'] ?? ''));
                if ($txt === '') continue;
                $out[] = ['phone' => $c->customer_phone, 'trigger' => 'promise_due',
                    'reason' => 'We promised: "'.mb_substr($txt, 0, 120).'"',
                    'evidence' => 'promise from conversation review · due '.(string) ($pr['due_hint'] ?? 'unspecified'),
                    'confidence' => 'high',
                    'outcome' => 'Keep our word'];
                break;   // one promise-candidate per conversation
            }
        }

        return $out;
    }

    /** The structural anti-spam gate. */
    public function blocked(Tenant $t, string $phone): bool
    {
        $open = FollowupProposal::where('tenant_id', $t->id)->where('customer_phone', $phone)
            ->where(function ($q) {
                $q->where('status', 'draft')
                  ->orWhere(fn ($q2) => $q2->where('status', 'snoozed')->where('snoozed_until', '>', now()))
                  ->orWhere(fn ($q3) => $q3->where('status', 'sent')->where('updated_at', '>', now()->subDays(7)));
            })->exists();
        if ($open) return true;

        $capDays = max(1, (int) $t->setting('followup_touch_cap_days', 4));
        $p = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', $phone)->first();

        return $p && $p->last_contacted_at && $p->last_contacted_at->gt(now()->subDays($capDays));
    }

    /** AI drafts the words (profile-aware). Overridable in tests; null on any failure. */
    protected function draft(Tenant $t, array $cand): ?string
    {
        try {
            $key = (string) (config('openai.api_key') ?: env('OPENAI_API_KEY', ''));
            if ($key === '') return null;
            $profile = ProfileContext::block($t, $cand['phone']);
            $resp = Http::withToken($key)->timeout(12)->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini', 'temperature' => 0.4,
                    'messages' => [
                        ['role' => 'system', 'content' =>
                            'Write ONE short, warm, professional WhatsApp follow-up (2-4 sentences, no signature) from '
                            .$t->name.' to a customer in East Africa. Natural, never pushy, never robotic. '
                            .'Reply with ONLY the message text.'.$profile],
                        ['role' => 'user', 'content' => 'Reason for contacting them: '.$cand['reason']
                            .'. Goal: '.($cand['outcome'] ?? 'be helpful').'.'],
                    ],
                ]);
            $txt = trim((string) $resp->json('choices.0.message.content'));

            return ($resp->successful() && $txt !== '') ? mb_substr($txt, 0, 900) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Deterministic fallback — a candidate is never lost to an AI outage. */
    private function template(Tenant $t, array $cand): string
    {
        return match ($cand['trigger']) {
            'quote_chase' => "Hello! Just checking in on the quotation we sent — happy to adjust quantities or answer any questions. — {$t->name}",
            'reorder'     => "Hello! It's about the time you usually restock — shall we prepare your regular order? — {$t->name}",
            'winback'     => "Hello! It's been a while — we've missed serving you. Anything we can get ready for you this week? — {$t->name}",
            default       => "Hello! Following up as promised — please let us know how we can help. — {$t->name}",
        };
    }
}
