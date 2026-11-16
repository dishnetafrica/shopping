<?php

namespace App\Services\Sales\Insights;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\SalesCoachSuggestion;
use App\Models\SalesPipelineEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Isp\IspReplyGuard;
use App\Services\Isp\IspSalesTurn;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Sales\Pipeline\Pipeline;
use App\Services\Sales\Pipeline\PipelineService;
use App\Services\Sales\SalesOwnership;
use App\Services\Sales\Training\SalesTraining;
use App\Services\Travel\Conversation\ReplyGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales dashboard and quality monitoring (DishNet South Sudan plan, Phases 7–8). READ ONLY: it derives
 * everything from records other parts already write (leads, pipeline history, quotations, messages,
 * coach suggestions, training sittings, bot traces) and stores nothing of its own.
 *
 * Quality applies ONE standard to the bot and to people: the IspReplyGuard rules (no amount outside
 * the catalogue / published knowledge / the customer's own figures; no coverage claim for a place not
 * published as covered). The bot's replies are corrected before sending (counted from its trace); the
 * coach's suggestions likewise; people's replies are checked after sending and listed for review.
 */
class SalesInsights
{
    public function __construct(private SalesBrain $brain) {}

    /** One row per salesperson/manager (or just $onlyUserId) for the last $days days. */
    public function team(Tenant $tenant, ?int $onlyUserId = null, int $days = Metrics::DAYS): array
    {
        $since = now()->subDays($days);
        $users = User::where('tenant_id', $tenant->id)->whereIn('role', array_merge(SalesOwnership::SELLING_ROLES, SalesOwnership::MANAGING_ROLES))
            ->when($onlyUserId, fn ($q) => $q->whereKey($onlyUserId))->orderBy('name')->get(['id', 'name', 'role']);
        $ids = $users->pluck('id')->all();
        if (! $ids) {
            return [];
        }

        $leads = Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('intent', 'lead')->whereIn('owner_user_id', $ids)
            ->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhereNotIn('status', ['won', 'lost']))
            ->get(['id', 'owner_user_id', 'customer_phone', 'status', 'stage', 'created_at', 'next_followup_at']);
        $newLeads = $leads->filter(fn ($l) => $l->created_at && $l->created_at->gte($since));

        $phones = $newLeads->pluck('customer_phone')->unique()->values()->all();
        $replies = [];
        if ($phones) {
            foreach (Message::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('direction', 'out')->where('sender', 'agent')
                ->whereIn('customer_phone', $phones)->where('created_at', '>=', $since)->orderBy('id')->get(['customer_phone', 'created_at']) as $m) {
                $replies[(string) $m->customer_phone][] = $m->created_at->getTimestamp();
            }
        }

        $decided = PipelineService::ready()
            ? SalesPipelineEvent::withoutGlobalScopes()->where('sales_pipeline_events.tenant_id', $tenant->id)->where('sales_pipeline_events.created_at', '>=', $since)
                ->whereIn('to_stage', ['won', 'lost'])->join('leads', 'leads.id', '=', 'sales_pipeline_events.lead_id')
                ->whereIn('leads.owner_user_id', $ids)->get(['leads.owner_user_id', 'sales_pipeline_events.to_stage', 'sales_pipeline_events.lead_id'])
            : collect();
        $quotes = Quotation::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('created_at', '>=', $since)->whereIn('sales_exec_id', $ids)
            ->select('sales_exec_id', DB::raw('count(*) as n'))->groupBy('sales_exec_id')->pluck('n', 'sales_exec_id')->all();
        $coach = Schema::hasTable('sales_coach_suggestions')
            ? SalesCoachSuggestion::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('created_at', '>=', $since)->whereIn('user_id', $ids)
                ->select('user_id', DB::raw('count(*) as n'))->groupBy('user_id')->pluck('n', 'user_id')->all()
            : [];
        $training = SalesTraining::ready() ? app(SalesTraining::class) : null;
        $now = now();

        $rows = [];
        foreach ($users as $u) {
            $mineNew = $newLeads->where('owner_user_id', $u->id);
            $open = $leads->where('owner_user_id', $u->id)->filter(fn ($l) => in_array(Pipeline::stageOf($l->stage, $l->status), Pipeline::OPEN, true));
            $dec = $decided->where('owner_user_id', $u->id);
            $won = $dec->where('to_stage', 'won')->pluck('lead_id')->unique()->count();
            $lost = $dec->where('to_stage', 'lost')->pluck('lead_id')->unique()->count();
            $mins = Metrics::firstReplyMinutes($mineNew->map(fn ($l) => ['phone' => (string) $l->customer_phone, 'created' => $l->created_at->getTimestamp()])->values()->all(), $replies);
            $st = $training?->status($tenant, (int) $u->id);
            $rows[] = [
                'user' => $u,
                'new_leads' => $mineNew->count(),
                'open' => $open->count(),
                'overdue' => $open->filter(fn ($l) => $l->next_followup_at && $l->next_followup_at->lte($now))->count(),
                'won' => $won, 'lost' => $lost, 'conversion' => Metrics::conversion($won, $lost),
                'quotes' => (int) ($quotes[$u->id] ?? 0),
                'first_reply_median' => Metrics::median($mins),
                'unanswered' => count(array_filter($mins, fn ($m) => $m === null)),
                'coach' => (int) ($coach[$u->id] ?? 0),
                'certified' => $st['certified'] ?? null, 'last_score' => $st['last']?->score ?? null,
            ];
        }

        return $rows;
    }

    /** Quality findings for the last $days days: the bot, the coach, people's replies, customers waiting. */
    public function quality(Tenant $tenant, int $days = Metrics::DAYS): array
    {
        $since = now()->subDays($days);

        // The bot: what its guard removed, and the unapproved settings it was kept away from.
        $bot = ['isp_price_stripped' => 0, 'isp_cover_stripped' => 0, 'isp_unapproved_skipped' => 0];
        foreach (DB::table('bot_events')->where('tenant_id', $tenant->id)->where('created_at', '>=', $since)->whereIn('stage', array_keys($bot))->get(['stage', 'detail']) as $e) {
            $bot[$e->stage] += preg_match('/^x(\d+)$/', (string) $e->detail, $m) ? (int) $m[1] : 1;
        }

        // The coach: suggestions whose wording had to be corrected.
        $coach = Schema::hasTable('sales_coach_suggestions')
            ? SalesCoachSuggestion::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('created_at', '>=', $since)
                ->selectRaw('count(*) as total, sum(case when guard_removed > 0 then 1 else 0 end) as corrected')->first()
            : null;

        // People: the same rules, applied to what they sent.
        $published = $this->brain->published($tenant);
        [$amounts, $places] = IspSalesTurn::allowed($published);
        $amounts = array_merge($amounts, Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->where('price', '>', 0)
            ->limit(500)->pluck('price')->map(fn ($p) => (float) $p)->all());
        $sent = Message::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('direction', 'out')->where('sender', 'agent')
            ->where('created_at', '>=', $since)->orderByDesc('id')->limit(1000)->get(['id', 'customer_phone', 'body', 'sender_user_id', 'created_at']);
        $customerAmounts = [];
        $phones = $sent->pluck('customer_phone')->unique()->values()->all();
        if ($phones) {
            foreach (Message::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('direction', 'in')->whereIn('customer_phone', $phones)
                ->where('created_at', '>=', $since)->get(['customer_phone', 'body']) as $m) {
                foreach (ReplyGuard::amounts((string) $m->body) as $a) {
                    $customerAmounts[(string) $m->customer_phone][] = $a;
                }
            }
        }
        $flags = [];
        foreach ($sent as $m) {
            foreach (IspReplyGuard::findings((string) $m->body, array_merge($amounts, $customerAmounts[(string) $m->customer_phone] ?? []), $places) as $f) {
                $flags[] = $f + ['message_id' => $m->id, 'phone' => (string) $m->customer_phone, 'user_id' => $m->sender_user_id, 'at' => $m->created_at];
            }
        }

        // Customers whose message is the last one in the chat, unanswered for WAITING_HOURS or more.
        $waiting = [];
        $now = now()->getTimestamp();
        foreach (Conversation::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotNull('last_inbound_at')->where('last_inbound_at', '>=', now()->subDays(7))
            ->get(['id', 'customer_phone', 'last_inbound_at', 'last_message_at', 'owner_user_id', 'agent_active']) as $c) {
            if (Metrics::waiting($c->last_inbound_at?->getTimestamp(), ($c->last_message_at ?? $c->last_inbound_at)?->getTimestamp(), $now)) {
                $waiting[] = ['conversation_id' => $c->id, 'phone' => (string) $c->customer_phone, 'user_id' => $c->owner_user_id,
                    'since' => $c->last_inbound_at, 'hours' => round(($now - $c->last_inbound_at->getTimestamp()) / 3600, 1)];
            }
        }

        return [
            'bot' => $bot,
            'coach_total' => (int) ($coach->total ?? 0), 'coach_corrected' => (int) ($coach->corrected ?? 0),
            'checked_replies' => $sent->count(), 'flags' => $flags, 'waiting' => $waiting,
        ];
    }
}
