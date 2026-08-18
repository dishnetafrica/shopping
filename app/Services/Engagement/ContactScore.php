<?php declare(strict_types=1);

namespace App\Services\Engagement;

/**
 * Phase 1 — the unified "should we contact this customer today, and how badly" score.
 *
 * PURE. No models, no database, no clock. It takes the signals the planner has already gathered from
 * the systems that own them — the nightly RFM `health` (relationship:stages), the ReorderSignals
 * cadence/priority (GrowthSignalsService), the lifecycle flags, and the engagement history — and folds
 * them into one 0–100 ranking number plus the human reasons behind it.
 *
 * It deliberately does NOT re-derive R/F/M or cadence: those already exist and are trusted. This class
 * only *combines* them, so there is exactly one place the weighting lives and one place to test it.
 * Eligibility (opt-in, opt-out, suppression, cooldown, active conversation) is a SEPARATE hard gate —
 * see EngagementEligibility. A high score never overrides a consent rule; the planner scores only the
 * customers eligibility has already cleared.
 *
 * Companion tests: tests/Unit/Engagement/ContactScoreTest.php + tests/Engagement/engagement_harness.php.
 */
final class ContactScore
{
    /**
     * @param  array<string,mixed>  $s  the gathered signals (all optional, safely defaulted):
     *   health(0-100), flags(string[] of VIP/AT_RISK/WINBACK), reorder_state, reorder_confidence(0-1),
     *   days_since_order(?int), orders(int), value(float, collected), days_since_contacted(?int),
     *   replied_before(bool), marketed_30d(int)
     * @return array{score:int, reasons:array<int,string>}
     */
    public static function score(array $s): array
    {
        $health   = self::clamp((float) ($s['health'] ?? 0), 0, 100);
        $flags    = array_map('strtoupper', (array) ($s['flags'] ?? []));
        $state    = (string) ($s['reorder_state'] ?? 'unknown');
        $conf     = self::clamp((float) ($s['reorder_confidence'] ?? 0), 0, 1);
        $orders   = max(0, (int) ($s['orders'] ?? 0));
        $value    = max(0.0, (float) ($s['value'] ?? 0));
        $sinceC   = $s['days_since_contacted'] ?? null;           // null = never contacted
        $replied  = (bool) ($s['replied_before'] ?? false);
        $marketed = max(0, (int) ($s['marketed_30d'] ?? 0));

        $reasons = [];
        $score   = 0.0;

        // ── RFM core: the nightly health score is the backbone (0–40). ───────────────────────────
        $score += 0.40 * $health;
        if ($health >= 70) {
            $reasons[] = 'Strong RFM health (' . (int) $health . ')';
        } elseif ($health > 0) {
            $reasons[] = 'RFM health ' . (int) $health;
        }

        // ── Reorder urgency: the sweet spot is OVERDUE (slipping now), not long-lapsed (likely gone).
        //    Scaled by cadence confidence so an erratic buyer's "overdue" counts for less. ──────────
        $urgency = match ($state) {
            'overdue' => 16.0,
            'lapsed'  => 10.0,
            'due'     => 8.0,
            default   => 0.0,
        };
        if ($urgency > 0) {
            $add = $urgency * (0.5 + 0.5 * $conf);
            $score += $add;
            $reasons[] = ucfirst($state) . ' to reorder' . ($conf < 0.4 ? ' (timing varies)' : '');
        }

        // ── Opportunity flags. VIP is the biggest single lift; win-back/at-risk are the point of
        //    re-engagement. Take the strongest, don't stack them. ──────────────────────────────────
        $opp = 0.0;
        $oppReason = '';
        if (in_array('VIP', $flags, true))      { $opp = max($opp, 10.0); $oppReason = 'VIP customer'; }
        if (in_array('WINBACK', $flags, true))  { if (8.0 >= $opp) { $opp = 8.0; $oppReason = 'Win-back (dormant past buyer)'; } }
        if (in_array('AT_RISK', $flags, true))  { if (8.0 >= $opp) { $opp = 8.0; $oppReason = 'At risk of lapsing'; } }
        if ($opp > 0) { $score += $opp; $reasons[] = $oppReason; }

        // ── Engagement history. A past reply is the strongest signal a message will land; repeated
        //    marketing with no reply is fatigue and should cool them down. ─────────────────────────
        if ($replied) {
            $score += 8.0;
            $reasons[] = 'Has replied to us before';
        } elseif ($marketed >= 2) {
            $score -= 10.0;
            $reasons[] = 'Messaged ' . $marketed . '× recently with no reply';
        }

        // ── Freshness nudge: never-contacted or long-quiet gets a small lift (fair rotation). ──────
        if ($sinceC === null) {
            $score += 4.0;
        } elseif ((int) $sinceC >= 30) {
            $score += 3.0;
        }

        // ── Value nudge: collected lifetime value, log-scaled so a whale doesn't swamp the ranking.
        if ($value > 0) {
            $vAdd = min(8.0, log10(1 + $value) * 3.0);
            $score += $vAdd;
            if ($vAdd >= 4) {
                $reasons[] = 'High lifetime value';
            }
        }

        // A customer with no orders at all has no re-engagement basis; keep them far down the list.
        if ($orders === 0) {
            $score -= 15.0;
        }

        $final = (int) round(self::clamp($score, 0, 100));

        return ['score' => $final, 'reasons' => $reasons];
    }

    private static function clamp(float $v, float $lo, float $hi): float
    {
        return max($lo, min($hi, $v));
    }
}
