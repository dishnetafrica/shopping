<?php declare(strict_types=1);

namespace Tests\Unit\Vision;

use App\Services\Vision\Reconciliation\ReconciliationEngine as RE;
use PHPUnit\Framework\TestCase;

/**
 * The Phase-0 single-dispatch-door acceptance tests, as a CI gate. Pure logic — no framework, no DB,
 * no clock (time is passed in). Mirrors tests/Vision/reconciliation_harness.php.
 */
final class ReconciliationEngineTest extends TestCase
{
    private const T = 1_000_000;

    private array $cfg = [
        'controlled_zones'  => ['D->EXIT', 'X'],
        'zone_location_map' => ['D->EXIT' => 'EXIT'],
        'authorized'        => ['X' => [17]],
    ];

    private function carton(string $dir, string $zone, int $ts, array $o = []): array
    {
        return array_replace([
            'kind' => 'carton.move', 'uid' => 'u'.$ts, 'zone_uid' => $zone, 'direction' => $dir,
            'object' => ['class' => 'carton', 'qty' => 1, 'qty_conf' => 0.95],
            'assoc' => ['track_id' => 17, 'candidates' => [17], 'assoc_conf' => 0.9],
            'confidence' => 0.9, 'occlusion' => false, 'ts' => $ts,
        ], $o);
    }

    public function test_legitimate_dispatch_matches(): void
    {
        $d = RE::reconcile($this->carton('out', 'D->EXIT', self::T),
            [['type' => 'dispatch', 'location' => 'EXIT', 'direction' => 'out', 'qty' => 1, 'ts' => self::T - 10]],
            [], $this->cfg, self::T);
        $this->assertSame(RE::MATCH, $d['result']);
        $this->assertFalse($d['review_required']);
    }

    public function test_delayed_paperwork_pending_then_match(): void
    {
        $pending = RE::reconcile($this->carton('out', 'D->EXIT', self::T), [], [], $this->cfg, self::T);
        $this->assertSame(RE::PENDING, $pending['result']);

        $resolved = RE::reconcile($this->carton('out', 'D->EXIT', self::T),
            [['type' => 'dispatch', 'location' => 'EXIT', 'direction' => 'out', 'qty' => 1, 'ts' => self::T + 120]],
            [], $this->cfg, self::T + 120);
        $this->assertSame(RE::MATCH, $resolved['result']);
    }

    public function test_missing_transaction_is_unreconciled_red(): void
    {
        $d = RE::reconcile($this->carton('out', 'D->EXIT', self::T), [], [], $this->cfg, self::T + 901);
        $this->assertSame(RE::UNRECONCILED, $d['result']);
        $this->assertSame(RE::RED, $d['severity']);
        $this->assertTrue($d['review_required']);
    }

    public function test_returned_carton_nets_to_zero(): void
    {
        $out = $this->carton('out', 'D->EXIT', self::T);
        $in  = $this->carton('in', 'D->EXIT', self::T + 30);
        $d = RE::reconcile($in, [], [$out], $this->cfg, self::T + 30);
        $this->assertSame(RE::NET_ZERO, $d['result']);
        $this->assertFalse($d['review_required']);
    }

    public function test_camera_failure_is_coverage_gap_not_theft(): void
    {
        $d = RE::reconcile(['kind' => 'camera.offline', 'ts' => self::T], [], [], $this->cfg, self::T);
        $this->assertSame(RE::COVERAGE_GAP, $d['result']);
        $this->assertFalse($d['review_required']);
    }

    public function test_two_workers_movement_real_attribution_undetermined(): void
    {
        $d = RE::reconcile(
            $this->carton('out', 'D->EXIT', self::T, ['assoc' => ['track_id' => 17, 'candidates' => [17, 22], 'assoc_conf' => 0.3]]),
            [], [], $this->cfg, self::T + 901);
        $this->assertSame(RE::UNRECONCILED, $d['result']);
        $this->assertSame('undetermined', $d['attribution']);
    }

    public function test_occlusion_flags_reviewer_but_stays_red(): void
    {
        $d = RE::reconcile($this->carton('out', 'D->EXIT', self::T, ['occlusion' => true]), [], [], $this->cfg, self::T + 901);
        $this->assertSame(RE::RED, $d['severity']);
        $this->assertContains('act_not_directly_observed', $d['notes']);
    }

    public function test_unauthorised_restricted_entry_is_violation(): void
    {
        $auth = RE::reconcile(['kind' => 'zone.entry', 'zone_uid' => 'X', 'assoc' => ['track_id' => 17], 'confidence' => 0.9, 'ts' => self::T], [], [], $this->cfg, self::T);
        $this->assertSame(RE::ACCESS, $auth['result']);

        $bad = RE::reconcile(['kind' => 'zone.entry', 'zone_uid' => 'X', 'assoc' => ['track_id' => 99], 'confidence' => 0.9, 'ts' => self::T], [], [], $this->cfg, self::T);
        $this->assertSame(RE::ACCESS_VIOLATION, $bad['result']);
        $this->assertTrue($bad['review_required']);
    }

    public function test_quantity_discrepancy_is_red(): void
    {
        $d = RE::reconcile(
            $this->carton('out', 'D->EXIT', self::T, ['kind' => 'pallet.move', 'object' => ['class' => 'pallet', 'qty' => 6, 'qty_conf' => 0.8]]),
            [['type' => 'dispatch', 'location' => 'EXIT', 'direction' => 'out', 'qty' => 4, 'ts' => self::T]],
            [], $this->cfg, self::T);
        $this->assertSame(RE::QTY_DISCREPANCY, $d['result']);
        $this->assertTrue($d['review_required']);
    }

    /** Audit fix A: a late-but-in-grace transaction (beyond the old ±match_window) must still MATCH. */
    public function test_late_transaction_within_grace_matches(): void
    {
        $d = RE::reconcile($this->carton('out', 'D->EXIT', self::T),
            [['type' => 'dispatch', 'location' => 'EXIT', 'direction' => 'out', 'qty' => 1, 'ts' => self::T + 600]],
            [], $this->cfg, self::T + 600);
        $this->assertSame(RE::MATCH, $d['result']);
    }

    /** Audit fix B: an unmapped zone is a misconfiguration, never a red. */
    public function test_unmapped_zone_is_never_a_red(): void
    {
        $d = RE::reconcile($this->carton('out', 'P->D', self::T), [], [], $this->cfg, self::T + 901);
        $this->assertSame(RE::UNMAPPED_ZONE, $d['result']);
        $this->assertFalse($d['review_required']);
    }

    /** Audit fix D: a shelf event can never be processed as a carton movement. */
    public function test_shelf_event_is_ignored(): void
    {
        $d = RE::reconcile(['kind' => 'shelf.out_of_stock', 'zone_uid' => 'D->EXIT', 'ts' => self::T], [], [], $this->cfg, self::T);
        $this->assertSame(RE::IGNORED, $d['result']);
        $this->assertFalse($d['review_required']);
    }

    /** Audit point 12: every red carries self-contained evidence for review. */
    public function test_red_carries_evidence(): void
    {
        $d = RE::reconcile($this->carton('out', 'D->EXIT', self::T), [], [], $this->cfg, self::T + 901);
        foreach (['zone_uid', 'direction', 'qty', 'track_id', 'confidence', 'ts'] as $k) {
            $this->assertArrayHasKey($k, $d['evidence']);
        }
    }

    /** Audit point 4/5: an inbound-signed transaction must not satisfy an outbound crossing. */
    public function test_inbound_txn_does_not_match_outbound_crossing(): void
    {
        $d = RE::reconcile($this->carton('out', 'D->EXIT', self::T),
            [['type' => 'IN', 'location' => 'EXIT', 'direction' => 'in', 'qty' => 1, 'ts' => self::T]],
            [], $this->cfg, self::T + 901);
        $this->assertSame(RE::UNRECONCILED, $d['result']);
    }
}
