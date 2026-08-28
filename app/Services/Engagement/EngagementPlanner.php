<?php declare(strict_types=1);

namespace App\Services\Engagement;

use App\Models\CommSuppression;
use App\Models\CustomerProfile;
use App\Models\EngagementCandidate;
use App\Models\Tenant;
use App\Services\Growth\CrossSellService;
use App\Services\Growth\GrowthSignalsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 — the daily re-engagement planner. Orchestration only; it invents no data and sends nothing.
 *
 * It UNIFIES the existing systems rather than duplicating them:
 *   · past-buyer audience + cadence/priority ....... GrowthSignalsService (→ ReorderSignals, pure)
 *   · RFM health, lifecycle flags, VIP/DORMANT ..... customer_profiles (nightly relationship:stages)
 *   · consent + do-not-contact .................... marketing_opted_in/out_at + comm_suppressions
 *   · what to offer ............................... CrossSellService::forCustomer
 *   · score / eligibility / campaign type ......... ContactScore / EngagementEligibility / CampaignType (pure)
 *
 * Output is one engagement_candidates snapshot per (tenant, plan_date): every past buyer with an
 * eligibility verdict, the eligible ones scored and ranked, the top `target` marked `chosen`. Re-running
 * a day rebuilds that day's rows. No message is ever sent here.
 */
final class EngagementPlanner
{
    public const TARGET_DEFAULT   = 40;
    public const TARGET_MAX       = 50;
    public const COOLDOWN_DEFAULT = 14;

    public function __construct(private ?GrowthSignalsService $growth = null, private ?CrossSellService $cross = null)
    {
        $this->growth ??= new GrowthSignalsService();
        $this->cross  ??= new CrossSellService();
    }

    /**
     * Build (and persist) today's plan for one tenant.
     *
     * @return array{plan_date:string, target:int, cooldown_days:int, counts:array<string,int>, blocked:array<string,int>}
     */
    public function plan(Tenant $tenant, ?Carbon $date = null): array
    {
        $date ??= Carbon::today();
        $planDate = $date->toDateString();

        $target   = (int) $tenant->setting('engagement_daily_target', self::TARGET_DEFAULT);
        $target   = max(1, min(self::TARGET_MAX, $target));
        $cooldown = (int) $tenant->setting('engagement_cooldown_days', self::COOLDOWN_DEFAULT);
        $cooldown = max(0, min(60, $cooldown));

        // ── 1. The audience: every past buyer, with cadence + collected value + priority already done.
        $signals = $this->growth->forTenant($tenant, true)['rows'] ?? [];

        // ── 2. Batch context (one query each — never N+1 over 700 customers). ─────────────────────
        $profiles    = $this->profileMap($tenant);
        $suppressed  = $this->suppressedSet($tenant);
        $marketing   = $this->marketingHistory($tenant);       // digits => [count30, replied]
        $activeConvo = $this->activeConversationSet($tenant);  // digits present = live thread
        $today       = (int) floor($date->getTimestamp() / 86400);

        // ── 3. Score + gate every customer. ──────────────────────────────────────────────────────
        $rows = [];
        foreach ($signals as $r) {
            $digits = preg_replace('/[^0-9]/', '', (string) ($r['phone'] ?? ''));
            if ($digits === '' || strlen($digits) < 7) {
                continue;
            }
            $p     = $profiles[$digits] ?? null;
            $flags = $p ? array_values(array_filter((array) ($p->health_flags ?? []))) : [];
            $mk    = $marketing[$digits] ?? ['count30' => 0, 'replied' => false];

            $lastTouch = $this->lastTouch($p);
            $sinceC    = $lastTouch !== null ? max(0, $today - (int) floor($lastTouch / 86400)) : null;

            $sig = [
                'phone'                => $digits,
                'health'               => $p ? (int) ($p->health ?? 0) : 0,
                'flags'                => $flags,
                'stage'                => $p ? (string) ($p->stage ?? '') : '',
                'reorder_state'        => (string) ($r['state'] ?? 'unknown'),
                'reorder_confidence'   => (float) ($r['confidence'] ?? 0),
                'reorder_priority'     => (float) ($r['priority'] ?? 0),
                'cadence_days'         => $r['cadenceDays'] ?? null,
                'days_since_order'     => $r['daysSince'] ?? null,
                'orders'               => (int) ($r['orders'] ?? 0),
                'value'                => (float) ($r['value'] ?? 0),
                'days_since_contacted' => $sinceC,
                'replied_before'       => (bool) $mk['replied'],
                'marketed_30d'         => (int) $mk['count30'],
                'opted_in'             => $p ? ($p->marketing_opted_in_at !== null) : false,
                'opted_out'            => $p ? ($p->marketing_opted_out_at !== null) : false,
                'suppressed'           => isset($suppressed[$digits]),
                'active_conversation'  => isset($activeConvo[$digits]),
            ];

            $elig = EngagementEligibility::decide($sig, $cooldown);
            $sc   = $elig['eligible'] ? ContactScore::score($sig) : ['score' => 0, 'reasons' => []];

            $nextEligible = null;
            if (! $elig['eligible'] && $elig['blocked'] === EngagementEligibility::COOLDOWN) {
                $nextEligible = $date->copy()->addDays($elig['next_eligible_in_days'])->toDateString();
            }

            $rows[] = [
                'digits'   => $digits,
                'profile'  => $p,
                'name'     => $p ? (string) ($p->name ?: ($r['name'] ?? $digits)) : (string) ($r['name'] ?? $digits),
                'sig'      => $sig,
                'elig'     => $elig,
                'score'    => $sc['score'],
                'reasons'  => $sc['reasons'],
                'type'     => CampaignType::decide($sig),
                'next'     => $nextEligible,
            ];
        }

        // ── 4. Rank the eligible, choose the top `target`. ───────────────────────────────────────
        $eligible = array_values(array_filter($rows, fn ($x) => $x['elig']['eligible']));
        usort($eligible, fn ($a, $b) => [$b['score'], $b['sig']['reorder_priority']] <=> [$a['score'], $a['sig']['reorder_priority']]);
        $chosenDigits = [];
        foreach (array_slice($eligible, 0, $target) as $x) {
            $chosenDigits[$x['digits']] = true;
        }

        // ── 5. Persist the snapshot (rebuild the day). Recommended product only for the chosen few. ─
        DB::transaction(function () use ($tenant, $planDate, $rows, $chosenDigits) {
            EngagementCandidate::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('plan_date', $planDate)->delete();

            foreach ($rows as $x) {
                $chosen  = isset($chosenDigits[$x['digits']]);
                $product = $chosen ? $this->recommendedProduct($tenant, $x['digits']) : ['id' => null, 'name' => null];

                EngagementCandidate::create([
                    'tenant_id'           => $tenant->id,
                    'plan_date'           => $planDate,
                    'phone'               => $x['digits'],
                    'customer_profile_id' => $x['profile']?->id,
                    'name'                => mb_substr($x['name'], 0, 191),
                    'score'               => $x['score'],
                    'campaign_type'       => $x['elig']['eligible'] ? $x['type'] : null,
                    'reason'              => $x['reasons'] ? mb_substr(implode(' · ', $x['reasons']), 0, 255) : null,
                    'product_id'          => $product['id'],
                    'product_name'        => $product['name'] ? mb_substr((string) $product['name'], 0, 191) : null,
                    'eligible'            => $x['elig']['eligible'],
                    'blocked_reason'      => $x['elig']['blocked'],
                    'chosen'              => $chosen,
                    'next_eligible_at'    => $x['next'],
                    'signals'             => $x['sig'],
                ]);
            }
        });

        // ── 6. Summary for the command / dashboard. ──────────────────────────────────────────────
        $blocked = [];
        foreach ($rows as $x) {
            if ($x['elig']['blocked']) {
                $blocked[$x['elig']['blocked']] = ($blocked[$x['elig']['blocked']] ?? 0) + 1;
            }
        }

        return [
            'plan_date'     => $planDate,
            'target'        => $target,
            'cooldown_days' => $cooldown,
            'counts'        => [
                'past_buyers' => count($rows),
                'opted_in'    => count(array_filter($rows, fn ($x) => $x['sig']['opted_in'])),
                'eligible'    => count($eligible),
                'chosen'      => count($chosenDigits),
            ],
            'blocked'       => $blocked,
        ];
    }

    /** digits => CustomerProfile, one query, first profile wins per number. */
    private function profileMap(Tenant $tenant): array
    {
        $out = [];
        CustomerProfile::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get(['id', 'name', 'phone', 'stage', 'health', 'health_flags', 'last_contacted_at', 'last_whatsapp_at', 'marketing_opted_in_at', 'marketing_opted_out_at'])
            ->each(function ($p) use (&$out) {
                $d = preg_replace('/[^0-9]/', '', (string) $p->phone);
                if ($d !== '' && ! isset($out[$d])) {
                    $out[$d] = $p;
                }
            });

        return $out;
    }

    /** Set of suppressed (do-not-contact) digits for whatsapp. */
    private function suppressedSet(Tenant $tenant): array
    {
        $out = [];
        try {
            CommSuppression::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->where('channel', 'whatsapp')
                ->pluck('address')
                ->each(function ($a) use (&$out) {
                    $d = preg_replace('/[^0-9]/', '', (string) $a);
                    if ($d !== '') { $out[$d] = true; }
                });
        } catch (\Throwable $e) { /* suppression list unavailable → nobody force-passed; gate is opt-in anyway */ }

        return $out;
    }

    /** digits => [count30 => int, replied => bool] from the marketing send ledger. Best-effort. */
    private function marketingHistory(Tenant $tenant): array
    {
        $out = [];
        try {
            DB::table('comm_messages')
                ->where('tenant_id', $tenant->id)->where('channel', 'whatsapp')
                ->where('created_at', '>=', now()->subDays(30))
                ->get(['to_email', 'replied_at'])
                ->each(function ($m) use (&$out) {
                    $d = preg_replace('/[^0-9]/', '', (string) $m->to_email);
                    if ($d === '') { return; }
                    $out[$d] ??= ['count30' => 0, 'replied' => false];
                    $out[$d]['count30']++;
                    if ($m->replied_at !== null) { $out[$d]['replied'] = true; }
                });
        } catch (\Throwable $e) { /* no ledger yet → no fatigue/reply signal, safe default */ }

        return $out;
    }

    /** Set of digits with a live conversation (recent inbound / handover). Best-effort, Phase-2 hardens. */
    private function activeConversationSet(Tenant $tenant): array
    {
        $out = [];
        try {
            $q = DB::table('conversations')->where('tenant_id', $tenant->id);
            $q->where(function ($w) {
                $w->where('last_inbound_at', '>=', now()->subDay());
                if (\Illuminate\Support\Facades\Schema::hasColumn('conversations', 'agent_active')) {
                    $w->orWhere('agent_active', true);
                }
                if (\Illuminate\Support\Facades\Schema::hasColumn('conversations', 'human_until')) {
                    $w->orWhere('human_until', '>=', now());
                }
            });
            $col = \Illuminate\Support\Facades\Schema::hasColumn('conversations', 'phone') ? 'phone'
                : (\Illuminate\Support\Facades\Schema::hasColumn('conversations', 'wa_id') ? 'wa_id' : null);
            if ($col === null) { return $out; }
            $q->pluck($col)->each(function ($v) use (&$out) {
                $d = preg_replace('/[^0-9]/', '', (string) $v);
                if ($d !== '') { $out[$d] = true; }
            });
        } catch (\Throwable $e) { /* conversations shape differs → skip the gate (Phase 1 sends nothing) */ }

        return $out;
    }

    /** Unix ts of the customer's last outbound touch (max of CRM contact + whatsapp), or null. */
    private function lastTouch(?CustomerProfile $p): ?int
    {
        if (! $p) {
            return null;
        }
        $ts = [];
        if ($p->last_contacted_at) { $ts[] = $p->last_contacted_at->getTimestamp(); }
        if ($p->last_whatsapp_at)  { $ts[] = $p->last_whatsapp_at->getTimestamp(); }

        return $ts ? max($ts) : null;
    }

    /** Top cross-sell suggestion for a chosen customer, or empty. */
    private function recommendedProduct(Tenant $tenant, string $digits): array
    {
        try {
            $recs = $this->cross->forCustomer($tenant, $digits);
            $top  = $recs[0] ?? null;
            if ($top) {
                return ['id' => $top['product_id'] ?? null, 'name' => $top['product'] ?? null];
            }
        } catch (\Throwable $e) { /* recommendations optional */ }

        return ['id' => null, 'name' => null];
    }
}
