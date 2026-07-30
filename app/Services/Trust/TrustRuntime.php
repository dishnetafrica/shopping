<?php declare(strict_types=1);

namespace App\Services\Trust;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Inventory Trust — runtime layer (Pilot Plan Phase B0 silent scoring → B1 queue).
 *
 * Wraps the pure TrustScorer with the DB context it refuses to know about: actor stats, tenant
 * median value, pattern queries, attached evidence, and persistence. Mirrors the backtest's causal
 * rules exactly, applied forward: a new negative ADJUST registers a variance and taxes the actors
 * who touched that SKU+location in the prior 14 days — affecting only future scores.
 *
 * Record confidence (Spec §8): C decays nightly with the value of unverified movements since the
 * last count and resets on a blind count. The cycle-count pick list is (1−C) × on-hand value —
 * the engine schedules its own ground truth.
 */
final class TrustRuntime
{
    public const CONF_MU = 0.02;          // decay rate per (movement value / median)
    public const CONF_STEP_FLOOR = 0.90;  // max decay per step — a fast mover comes due weekly, not daily
    public const CONF_RESET = 0.98;       // confidence after a clean blind count
    public const VARIANCE_WINDOW_DAYS = 14;

    private ?float $median = null;

    public function __construct(private Tenant $tenant) {}

    public static function forTenant(Tenant $t): self
    {
        return new self($t);
    }

    // ---------- context ----------

    /** Median movement value over the last 90 days (cached per instance). */
    public function medianValue(): float
    {
        if ($this->median !== null) {
            return $this->median;
        }
        $unit = $this->unitValues();
        $vals = StockMovement::where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', now()->subDays(90))
            ->get(['product_id', 'qty'])
            ->map(fn ($m) => abs((float) $m->qty) * ($unit[$m->product_id] ?? 0.0))
            ->filter(fn ($v) => $v > 0)->sort()->values();
        return $this->median = $vals->isEmpty()
            ? 1.0
            : (float) $vals[(int) floor($vals->count() / 2)];
    }

    /** @return array<int,float> product_id => unit value (cost preferred, price fallback) */
    public function unitValues(): array
    {
        static $cache = [];
        return $cache[$this->tenant->id] ??= Product::where('tenant_id', $this->tenant->id)
            ->get(['id', 'cost_price', 'price'])
            ->mapWithKeys(fn ($p) => [$p->id => (float) ($p->cost_price ?? $p->price ?? 0)])->all();
    }

    public function valueOf(StockMovement $m): float
    {
        return abs((float) $m->qty) * ($this->unitValues()[$m->product_id] ?? 0.0);
    }

    // ---------- scoring ----------

    /** Score one movement (idempotent) and persist. Returns the TrustScorer result array. */
    public function scoreMovement(StockMovement $m): array
    {
        $type = strtoupper((string) $m->type);
        $value = $this->valueOf($m);
        $actor = (string) ($m->created_by ?: '');

        // patterns (past-only queries)
        $patterns = [];
        $hour = (int) $m->created_at->format('G');
        if ($hour < 6 || $hour >= 22) $patterns[] = 'off_hours';
        if ($type === 'ADJUST' && (float) $m->qty < 0) $patterns[] = 'negative_adjust';
        $q = abs((float) $m->qty);
        if (in_array($type, ['OUT', 'ADJUST'], true) && $q >= 20 && fmod($q, 10.0) === 0.0) {
            $patterns[] = 'round_qty';
        }
        if ($actor !== '' && in_array($type, ['OUT', 'ADJUST'], true)) {
            $burst = StockMovement::where('tenant_id', $this->tenant->id)
                ->where('created_by', $actor)->where('type', $m->type)
                ->where(fn ($w) => $m->sku ? $w->where('sku', $m->sku) : $w->where('product_id', $m->product_id))
                ->where('created_at', '>=', $m->created_at->copy()->subDays(7))
                ->where('id', '<', $m->id)->count();
            if ($burst >= 3) $patterns[] = 'burst';
        }

        // evidence: ledger fields + any attached trust_evidence_items rows
        $evidence = [
            'ref_linked' => ($m->ref_type && $m->ref_id) ? 'present_pass' : 'expected_missing',
            'actor_known' => $actor !== '' ? 'present_pass' : 'expected_missing',
            'note_present' => trim((string) $m->note) !== '' ? 'present_pass' : 'expected_missing',
        ];
        $attached = DB::table('trust_evidence_items')
            ->where('tenant_id', $this->tenant->id)
            ->where('stock_movement_id', $m->id)
            ->pluck('status', 'signal_code');
        foreach ($attached as $code => $status) {
            $evidence[$code] = $status;
        }

        $stat = $actor !== '' ? $this->actorStat($actor) : null;
        $r = TrustScorer::score($type, $value, $this->medianValue(), $stat, $patterns, $evidence);

        DB::table('trust_scores')->updateOrInsert(
            ['stock_movement_id' => $m->id],
            ['tenant_id' => $this->tenant->id, 'score_version' => SignalCatalog::VERSION,
             'logit_base' => $r['logit_base'], 'logit_prior' => $r['logit_prior'],
             'logit_evidence' => $r['logit_evidence'], 'trust' => $r['trust'],
             'trust_required' => $r['trust_required'], 'value_at_risk' => $value,
             'expected_loss' => $r['expected_loss'], 'decision' => $r['decision'],
             'reason_codes' => json_encode($r['reason_codes']),
             'created_at' => now(), 'updated_at' => now()]);

        $this->registerOutcomeSideEffects($m, $value);
        return $r + ['value' => $value];
    }

    /** Score every movement in the window that has no score yet. Returns count scored. */
    public function scoreUnscored(int $sinceHours = 48): int
    {
        $rows = StockMovement::where('stock_movements.tenant_id', $this->tenant->id)
            ->where('stock_movements.created_at', '>=', now()->subHours($sinceHours))
            ->leftJoin('trust_scores', 'trust_scores.stock_movement_id', '=', 'stock_movements.id')
            ->whereNull('trust_scores.id')
            ->orderBy('stock_movements.created_at')
            ->select('stock_movements.*')->get();
        foreach ($rows as $m) {
            $this->scoreMovement($m);
        }
        return $rows->count();
    }

    // ---------- reputation + variances ----------

    /** @return array{alpha:float,beta:float} */
    private function actorStat(string $actor): array
    {
        $row = DB::table('trust_entity_stats')->where([
            'tenant_id' => $this->tenant->id, 'entity_type' => 'actor',
            'entity_key' => $actor, 'txn_type' => '*'])->first();
        return $row
            ? ['alpha' => (float) $row->alpha, 'beta' => (float) $row->beta]
            : EntityTrust::coldStart();
    }

    private function saveActorStat(string $actor, array $s): void
    {
        DB::table('trust_entity_stats')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'entity_type' => 'actor',
             'entity_key' => $actor, 'txn_type' => '*'],
            ['alpha' => $s['alpha'], 'beta' => $s['beta'],
             'created_at' => now(), 'updated_at' => now()]);
    }

    private function registerOutcomeSideEffects(StockMovement $m, float $value): void
    {
        $type = strtoupper((string) $m->type);
        $actor = (string) ($m->created_by ?: '');

        if ($type === 'ADJUST' && (float) $m->qty < 0) {
            $already = DB::table('trust_variance_events')
                ->where('adjust_movement_id', $m->id)->exists();
            if ($already) {
                return;
            }
            $windowStart = $m->created_at->copy()->subDays(self::VARIANCE_WINDOW_DAYS);
            $touchers = StockMovement::where('tenant_id', $this->tenant->id)
                ->where(fn ($w) => $m->sku ? $w->where('sku', $m->sku) : $w->where('product_id', $m->product_id))
                ->where('location', $m->location)
                ->whereBetween('created_at', [$windowStart, $m->created_at])
                ->where('id', '<>', $m->id)
                ->whereNotNull('created_by')->distinct()->pluck('created_by');
            $sev = $value > 0 ? min(1.0, $value / max($this->medianValue() * 4, 0.01)) : 0.25;
            foreach ($touchers as $a) {
                $s = $this->actorStat((string) $a);
                $this->saveActorStat((string) $a, EntityTrust::update($s['alpha'], $s['beta'], false, $sev));
            }
            DB::table('trust_variance_events')->insert([
                'tenant_id' => $this->tenant->id, 'product_id' => $m->product_id, 'sku' => $m->sku,
                'location' => $m->location, 'variance_qty' => (float) $m->qty,
                'variance_value' => $value, 'adjust_movement_id' => $m->id,
                'window_start' => $windowStart, 'window_end' => $m->created_at,
                'created_at' => now(), 'updated_at' => now()]);
        } elseif ($actor !== '') {
            $s = $this->actorStat($actor);
            $this->saveActorStat($actor, EntityTrust::update($s['alpha'], $s['beta'], true));
        }
    }

    // ---------- record confidence (Spec §8) ----------

    /** Pure decay step — separated for testability. */
    public static function decayConfidence(float $c, float $unverifiedValue, float $median): float
    {
        $ratio = $median > 0 ? $unverifiedValue / $median : 0.0;
        $step = max(exp(-self::CONF_MU * $ratio), self::CONF_STEP_FLOOR);
        return round(max(0.0, $c * $step), 3);
    }

    /**
     * Nightly: decay confidence per (product, location) by the value moved since the last run,
     * then return the cycle-count pick list — top N by (1−C) × on-hand value.
     *
     * @return array<int, array{product_id:int, location:string, confidence:float, priority:float, name:string}>
     */
    public function decayAndPick(int $pick = 5): array
    {
        $median = $this->medianValue();
        $unit = $this->unitValues();

        $moved = StockMovement::where('tenant_id', $this->tenant->id)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('product_id')
            ->select('product_id', 'location', DB::raw('SUM(ABS(qty)) as q'))
            ->groupBy('product_id', 'location')->get();
        foreach ($moved as $r) {
            $val = (float) $r->q * ($unit[$r->product_id] ?? 0.0);
            $row = DB::table('trust_record_confidence')->where([
                'tenant_id' => $this->tenant->id, 'product_id' => $r->product_id,
                'location' => $r->location])->first();
            $c = $row ? (float) $row->confidence : 0.5;   // never-counted starts uncertain
            DB::table('trust_record_confidence')->updateOrInsert(
                ['tenant_id' => $this->tenant->id, 'product_id' => $r->product_id, 'location' => $r->location],
                ['confidence' => self::decayConfidence($c, $val, $median),
                 'unverified_value' => DB::raw('unverified_value + ' . round($val, 2)),
                 'created_at' => now(), 'updated_at' => now()]);
        }

        $onHand = [];
        foreach (StockMovement::where('tenant_id', $this->tenant->id)
            ->whereNotNull('product_id')
            ->select('product_id', 'location', DB::raw('SUM(qty) as q'))
            ->groupBy('product_id', 'location')->get() as $r) {
            $onHand[$r->product_id . '|' . $r->location] = max(0.0, (float) $r->q);
        }

        $names = Product::where('tenant_id', $this->tenant->id)->pluck('name', 'id');
        $list = [];
        foreach (DB::table('trust_record_confidence')
            ->where('tenant_id', $this->tenant->id)->get() as $rc) {
            $oh = $onHand[$rc->product_id . '|' . $rc->location] ?? 0.0;
            if ($oh <= 0) {
                continue;
            }
            $value = $oh * ($unit[$rc->product_id] ?? 0.0);
            $list[] = [
                'product_id' => (int) $rc->product_id, 'location' => (string) $rc->location,
                'confidence' => (float) $rc->confidence,
                'priority' => round((1.0 - (float) $rc->confidence) * $value, 2),
                'name' => (string) ($names[$rc->product_id] ?? ('#' . $rc->product_id)),
            ];
        }
        usort($list, fn ($a, $b) => $b['priority'] <=> $a['priority']);
        return array_slice($list, 0, $pick);
    }

    /** Record a blind count result for a product+location (called by the count flow, Phase B1). */
    public function registerCount(int $productId, string $location, float $matchQuality = 1.0): void
    {
        DB::table('trust_record_confidence')->updateOrInsert(
            ['tenant_id' => $this->tenant->id, 'product_id' => $productId, 'location' => $location],
            ['confidence' => round(self::CONF_RESET * max(0.1, min(1.0, $matchQuality)), 3),
             'unverified_value' => 0, 'last_counted_at' => now(),
             'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Resolve an investigation with a human outcome — the label factory (Spec §7.2 / §10).
     * Same semantics as `trust:resolve`, exposed for the panel (T6): clean ↑ actor reputation,
     * confirmed_loss ↓ scaled by value severity, `error` is process-not-person → no reputation
     * change. Unlike the artisan path this refuses to overwrite an already-resolved item
     * (double-tap protection in a browser).
     */
    public function resolveInvestigation(int $invId, string $outcome, ?float $value, string $notes, string $by): array
    {
        if (! in_array($outcome, ['clean', 'error', 'confirmed_loss', 'unresolved'], true)) {
            return ['ok' => false, 'error' => 'bad_outcome'];
        }
        $tid = (int) $this->tenant->id;
        $inv = DB::table('trust_investigations')->where('tenant_id', $tid)->where('id', $invId)->first();
        if (! $inv) {
            return ['ok' => false, 'error' => 'not_found'];
        }
        if ($inv->outcome !== null) {
            return ['ok' => false, 'error' => 'already_resolved'];
        }

        DB::table('trust_investigations')->where('id', $inv->id)->update([
            'outcome' => $outcome,
            'outcome_value' => $value,
            'resolved_by' => $by,
            'resolved_at' => now(),
            'notes' => $notes,
            'updated_at' => now(),
        ]);

        $m = DB::table('stock_movements')->where('id', $inv->stock_movement_id)->first();
        if ($m && $m->created_by && in_array($outcome, ['clean', 'confirmed_loss'], true)) {
            $stat = DB::table('trust_entity_stats')->where([
                'tenant_id' => $tid, 'entity_type' => 'actor',
                'entity_key' => $m->created_by, 'txn_type' => '*'])->first();
            $s = $stat ? ['alpha' => (float) $stat->alpha, 'beta' => (float) $stat->beta]
                       : EntityTrust::coldStart();
            if ($outcome === 'clean') {
                $s = EntityTrust::update($s['alpha'], $s['beta'], true);
            } else {
                $sev = ($value ?? 0) > 0
                    ? min(1.0, (float) $value / max($this->medianValue() * 4, 0.01))
                    : 0.6;
                $s = EntityTrust::update($s['alpha'], $s['beta'], false, $sev);
            }
            DB::table('trust_entity_stats')->updateOrInsert(
                ['tenant_id' => $tid, 'entity_type' => 'actor',
                 'entity_key' => $m->created_by, 'txn_type' => '*'],
                ['alpha' => $s['alpha'], 'beta' => $s['beta'],
                 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['ok' => true];
    }
}
