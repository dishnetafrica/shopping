<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\VisionBox;
use App\Models\VisionEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Phase-0 Stock & Loss — STAGING end-to-end verification of the real Laravel reconciliation chain.
 *
 *   php artisan vision:verify-reconcile --tenant=99 [--url=https://staging.host] [--other-tenant=100] [--keep]
 *
 * Proves the production path — real HTTP POST to /api/vision/events, real X-Vision-Token auth, real
 * VisionReconciler, real stock_movements lookup, real persistence — not the pure engine (already 17/17).
 *
 * PRODUCTION-SAFE and repeatable:
 *   • all ledger fixtures use a SYNTHETIC location + sku (product_id NULL), so NO real product on-hand
 *     is ever touched; rows are tagged note='vision-verify' and cleaned up.
 *   • all vision events use 'verify-*' uids and are cleaned up.
 *   • a disposable claimed VisionBox is minted for auth and revoked at the end.
 * Point it at a disposable STAGING tenant. It refuses to run unless reconciliation is enabled.
 */
class VisionVerifyReconcileCommand extends Command
{
    protected $signature = 'vision:verify-reconcile {--tenant= : disposable staging tenant id} {--url= : base URL (default app.url)} {--other-tenant= : a second tenant id, for the isolation case} {--keep : leave fixtures in place}';

    protected $description = 'Staging end-to-end verification of the vision reconciliation chain';

    private const LOC     = 'VERIFY-DOCK';
    private const SKU     = 'VERIFY-SKU';
    private const MARK    = 'vision-verify';
    private const Z_EXIT  = 'VERIFY->EXIT';   // controlled boundary
    private const Z_UNMAP = 'VERIFY->UNMAPPED';
    private const Z_REST  = 'X-VERIFY';        // restricted access zone

    /** @var array<int,array<string,mixed>> */
    private array $results = [];

    public function handle(): int
    {
        if (! (bool) config('vision.reconcile.enabled', false)) {
            $this->error('Reconciliation is OFF. Set VISION_RECONCILE=true (and `php artisan config:clear`) on staging, then re-run.');

            return self::FAILURE;
        }
        $tenantId = (int) $this->option('tenant');
        $tenant   = $tenantId ? Tenant::find($tenantId) : null;
        if (! $tenant) {
            $this->error('Pass --tenant=<id> of a disposable STAGING tenant.');

            return self::FAILURE;
        }
        $base = rtrim((string) ($this->option('url') ?: config('app.url', '')), '/');
        if ($base === '') {
            $this->error('No base URL. Pass --url=https://staging.host (or set APP_URL).');

            return self::FAILURE;
        }

        $this->info("Verifying reconciliation chain against {$base}/api/vision/events (tenant {$tenant->id})");

        // ---- configure the test tenant (test-only zone names; harmless to anything real) ----
        $this->setSetting($tenant, 'vision_zone_location_map', [self::Z_EXIT => self::LOC]);
        $this->setSetting($tenant, 'vision_controlled_zones', [self::Z_EXIT, self::Z_REST]);
        $this->setSetting($tenant, 'vision_authorized_tracks', [self::Z_REST => [17]]);
        $this->setSetting($tenant, 'vision_qty_tolerance', 0);   // exact-qty for the discrepancy case

        // ---- mint a disposable claimed box for the real auth path ----
        $token = 'verify-' . Str::random(48);
        $box = VisionBox::updateOrCreate(
            ['box_uid' => 'verify-box-' . $tenant->id],
            ['secret_sha256' => str_repeat('0', 64), 'status' => 'claimed', 'tenant_id' => $tenant->id,
             'store' => 'VERIFY', 'webhook_token' => $token, 'claimed_at' => now()],
        );

        $this->cleanup($tenant->id);   // start clean (repeatable)

        try {
            $this->caseLegitimate($tenant, $base, $token);
            $this->caseLatePaperwork($tenant, $base, $token);
            $this->caseMissing($tenant, $base, $token);
            $this->caseCameraOffline($tenant, $base, $token);
            $this->caseDuplicate($tenant, $base, $token);
            $this->caseUnmapped($tenant, $base, $token);
            $this->caseInboundVsOutbound($tenant, $base, $token);
            $this->caseTwoWorkers($tenant, $base, $token);
            $this->caseQtyDiscrepancy($tenant, $base, $token);
            $this->caseShelfUntouched($tenant, $base, $token);
            $this->caseTenantIsolation($tenant, $base, $token);
        } finally {
            if (! $this->option('keep')) {
                $this->cleanup($tenant->id);
                $other = (int) $this->option('other-tenant');
                if ($other) { $this->clearTxns($other); }
                $box->delete();
                foreach (['vision_zone_location_map', 'vision_controlled_zones', 'vision_authorized_tracks', 'vision_qty_tolerance'] as $k) {
                    $this->unsetSetting($tenant, $k);
                }
            }
        }

        return $this->report();
    }

    /* ------------------------------------------------------------------ cases */

    private function caseLegitimate(Tenant $t, string $base, string $token): void
    {
        $this->clearTxns($t->id);
        $now = time();
        $this->seedTxn($t->id, 'out', 1, $now);
        $this->post($base, $token, $this->carton('verify-A', 'out', self::Z_EXIT, $now));
        $this->assertRow($t->id, 'verify-A', 'A · legitimate dispatch', 'MATCH', false, true);
    }

    private function caseLatePaperwork(Tenant $t, string $base, string $token): void
    {
        $this->clearTxns($t->id);
        $now = time();
        $this->post($base, $token, $this->carton('verify-B', 'out', self::Z_EXIT, $now));   // no txn yet
        $this->assertRow($t->id, 'verify-B', 'B1 · late paperwork — at crossing', 'PENDING', false, false);
        // paperwork lands +10 min — inside grace but well beyond the old ±5 min window (the fixed bug)
        $this->seedTxn($t->id, 'out', 1, $now + 600);
        Artisan::call('vision:reconcile-sweep', ['--tenant' => $t->id]);
        $this->assertRow($t->id, 'verify-B', 'B2 · late paperwork +10min → MATCH (6–14min bug fixed)', 'MATCH', false, true);
    }

    private function caseMissing(Tenant $t, string $base, string $token): void
    {
        $this->clearTxns($t->id);
        $now = time();
        $this->post($base, $token, $this->carton('verify-C', 'out', self::Z_EXIT, $now));
        $this->assertRow($t->id, 'verify-C', 'C1 · missing txn — at crossing', 'PENDING', false, false);
        // simulate the grace window elapsing, then sweep
        $this->backdate($t->id, 'verify-C', $now - 901);
        Artisan::call('vision:reconcile-sweep', ['--tenant' => $t->id]);
        $this->assertRow($t->id, 'verify-C', 'C2 · grace expired → UNRECONCILED', 'UNRECONCILED', true, false);
    }

    private function caseCameraOffline(Tenant $t, string $base, string $token): void
    {
        $now = time();
        $this->post($base, $token, ['event' => 'camera.offline', 'uid' => 'verify-D', 'zone_uid' => self::Z_EXIT,
            'timestamp' => Carbon::createFromTimestamp($now)->toIso8601String()]);
        $this->assertRow($t->id, 'verify-D', 'D · camera offline → COVERAGE_GAP (never red)', 'COVERAGE_GAP', false, false);
    }

    private function caseDuplicate(Tenant $t, string $base, string $token): void
    {
        $this->clearTxns($t->id);
        $now = time();
        $ev  = $this->carton('verify-E', 'out', self::Z_EXIT, $now);
        $this->post($base, $token, $ev);
        $second = $this->post($base, $token, $ev);   // exact re-delivery
        $count  = VisionEvent::withoutGlobalScopes()->where('tenant_id', $t->id)->where('event_uid', 'verify-E')->count();
        $dupFlag = (bool) ($second['duplicate'] ?? false);
        $ok = $count === 1 && $dupFlag;
        $this->record('E · duplicate delivery → one row, one decision', 'carton.move ×2', $count === 1 ? 'yes(1)' : "no({$count})",
            '—', $dupFlag ? 'duplicate=true' : 'duplicate=false', '—', $ok);
    }

    private function caseUnmapped(Tenant $t, string $base, string $token): void
    {
        $now = time();
        $this->post($base, $token, $this->carton('verify-UM', 'out', self::Z_UNMAP, $now - 901));
        $this->assertRow($t->id, 'verify-UM', 'X1 · unmapped zone → UNMAPPED_ZONE (never red)', 'UNMAPPED_ZONE', false, false);
    }

    private function caseInboundVsOutbound(Tenant $t, string $base, string $token): void
    {
        $this->clearTxns($t->id);
        $now = time();
        $this->seedTxn($t->id, 'in', 1, $now - 901);   // only an INBOUND txn exists
        $this->post($base, $token, $this->carton('verify-INV', 'out', self::Z_EXIT, $now - 901));
        $this->assertRow($t->id, 'verify-INV', 'X2 · inbound txn cannot satisfy outbound', 'UNRECONCILED', true, false);
    }

    private function caseTwoWorkers(Tenant $t, string $base, string $token): void
    {
        $this->clearTxns($t->id);
        $now = time();
        $this->post($base, $token, $this->carton('verify-2W', 'out', self::Z_EXIT, $now - 901,
            ['assoc' => ['track_id' => 17, 'candidates' => [17, 22], 'assoc_conf' => 0.3]]));
        $row = $this->reload($t->id, 'verify-2W');
        $attr = $row ? (string) data_get($row->payload, 'reconciliation.attribution') : '';
        $ok = $row && $row->recon_result === 'UNRECONCILED' && $attr === 'undetermined';
        $this->record('X3 · two workers → movement real, worker undetermined', 'carton.move out',
            $row ? 'yes' : 'no', 'none', $row->recon_result ?? '—', 'attribution=' . ($attr ?: '—'), (bool) $ok);
    }

    private function caseQtyDiscrepancy(Tenant $t, string $base, string $token): void
    {
        $this->clearTxns($t->id);
        $now = time();
        $this->seedTxn($t->id, 'out', 4, $now);   // booked 4
        $this->post($base, $token, $this->carton('verify-QTY', 'out', self::Z_EXIT, $now,
            ['event' => 'pallet.move', 'object' => ['class' => 'pallet', 'qty' => 6, 'qty_conf' => 0.8]]));   // moved 6
        $this->assertRow($t->id, 'verify-QTY', 'X4 · quantity discrepancy (moved 6, booked 4)', 'QTY_DISCREPANCY', true, false);
    }

    private function caseShelfUntouched(Tenant $t, string $base, string $token): void
    {
        $now = time();
        $this->post($base, $token, ['event' => 'shelf.out_of_stock', 'uid' => 'verify-SHELF', 'zone' => 'aisle-1',
            'from_state' => 'stocked', 'to_state' => 'empty', 'fill' => 0.1,
            'timestamp' => Carbon::createFromTimestamp($now)->toIso8601String()]);
        $row = $this->reload($t->id, 'verify-SHELF');
        $ok  = $row && $row->recon_result === null && $row->review_required == false;
        $this->record('X5 · shelf event untouched (no reconciliation)', 'shelf.out_of_stock',
            $row ? 'yes' : 'no', 'n/a', $row && $row->recon_result === null ? 'null (correct)' : ($row->recon_result ?? '—'), '—', (bool) $ok);
    }

    private function caseTenantIsolation(Tenant $t, string $base, string $token): void
    {
        $other = (int) $this->option('other-tenant');
        if (! $other || ! Tenant::find($other)) {
            $this->record('X6 · tenant isolation', 'carton.move out', '—', '—', 'SKIPPED', 'pass --other-tenant', true, true);

            return;
        }
        $this->clearTxns($t->id);
        $this->clearTxns($other);
        $now = time();
        $this->seedTxn($other, 'out', 1, $now);   // matching txn belongs to ANOTHER tenant
        $this->post($base, $token, $this->carton('verify-ISO', 'out', self::Z_EXIT, $now));
        // The other tenant's txn must NOT be seen → no match → PENDING (fresh), not MATCH.
        $this->assertRow($t->id, 'verify-ISO', 'X6 · tenant isolation (other tenant txn not matched)', 'PENDING', false, false);
    }

    /* ------------------------------------------------------------------ helpers */

    private function carton(string $uid, string $dir, string $zone, int $ts, array $extra = []): array
    {
        return array_replace([
            'event' => 'carton.move', 'uid' => $uid, 'zone_uid' => $zone, 'direction' => $dir,
            'object' => ['class' => 'carton', 'qty' => 1, 'qty_conf' => 0.95],
            'assoc' => ['track_id' => 17, 'candidates' => [17], 'assoc_conf' => 0.9],
            'confidence' => 0.9, 'occlusion' => false,
            'timestamp' => Carbon::createFromTimestamp($ts)->toIso8601String(),
        ], $extra);
    }

    private function post(string $base, string $token, array $payload): array
    {
        try {
            $r = Http::withHeaders(['X-Vision-Token' => $token, 'Accept' => 'application/json'])
                ->timeout(15)->post($base . '/api/vision/events', $payload);

            return is_array($r->json()) ? $r->json() : [];
        } catch (\Throwable $e) {
            $this->warn('POST failed: ' . $e->getMessage());

            return [];
        }
    }

    private function seedTxn(int $tenantId, string $direction, int $qty, int $ts): void
    {
        $signed = $direction === 'out' ? -abs($qty) : abs($qty);
        DB::table('stock_movements')->insert([
            'tenant_id' => $tenantId, 'product_id' => null, 'sku' => self::SKU, 'location' => self::LOC,
            'type' => $direction === 'out' ? 'OUT' : 'IN', 'qty' => $signed, 'ref_type' => 'dispatch',
            'note' => self::MARK, 'created_by' => self::MARK,
            'created_at' => Carbon::createFromTimestamp($ts), 'updated_at' => Carbon::createFromTimestamp($ts),
        ]);
    }

    private function reload(int $tenantId, string $uid): ?VisionEvent
    {
        return VisionEvent::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('event_uid', $uid)->first();
    }

    /** Backdate a stored event so the grace clock is treated as elapsed. */
    private function backdate(int $tenantId, string $uid, int $ts): void
    {
        $row = $this->reload($tenantId, $uid);
        if (! $row) { return; }
        $pl = is_array($row->payload) ? $row->payload : [];
        $pl['timestamp'] = Carbon::createFromTimestamp($ts)->toIso8601String();
        $row->forceFill(['payload' => $pl, 'occurred_at' => Carbon::createFromTimestamp($ts)])->save();
    }

    private function assertRow(int $tenantId, string $uid, string $name, string $wantResult, bool $wantReview, bool $wantMatch): void
    {
        $row = $this->reload($tenantId, $uid);
        $got = $row->recon_result ?? '—';
        $rev = $row ? (bool) $row->review_required : false;
        $matched = $row ? (data_get($row->payload, 'reconciliation.matched') !== null) : false;
        $ok = $row && $got === $wantResult && $rev === $wantReview && ($wantMatch ? $matched : true);
        $this->record($name, 'carton.move', $row ? 'yes(#' . $row->id . ')' : 'no',
            $matched ? 'yes' : 'no', $got, $rev ? 'true' : 'false', (bool) $ok);
    }

    private function record(string $name, string $event, string $persisted, string $ledger, string $result, string $review, bool $ok, bool $skipped = false): void
    {
        $this->results[] = compact('name', 'event', 'persisted', 'ledger', 'result', 'review', 'ok', 'skipped');
        $tag = $skipped ? '<comment>SKIP</comment>' : ($ok ? '<info>PASS</info>' : '<error>FAIL</error>');
        $this->line(sprintf('  %s  %-52s → %s', $tag, $name, $result));
    }

    private function report(): int
    {
        $this->newLine();
        $rows = array_map(fn ($r) => [
            $r['skipped'] ? 'SKIP' : ($r['ok'] ? 'PASS' : 'FAIL'),
            $r['name'], $r['persisted'], $r['ledger'], $r['result'], $r['review'],
        ], $this->results);
        $this->table(['', 'Case', 'Persisted', 'Ledger', 'Result', 'Review'], $rows);

        $fail = count(array_filter($this->results, fn ($r) => ! $r['ok'] && ! $r['skipped']));
        $pass = count(array_filter($this->results, fn ($r) => $r['ok'] && ! $r['skipped']));
        $skip = count(array_filter($this->results, fn ($r) => $r['skipped']));
        $this->line("{$pass} passed, {$fail} failed, {$skip} skipped");

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function cleanup(int $tenantId): void
    {
        $this->clearTxns($tenantId);
        VisionEvent::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('event_uid', 'like', 'verify-%')->delete();
    }

    private function clearTxns(int $tenantId): void
    {
        DB::table('stock_movements')->where('tenant_id', $tenantId)->where('note', self::MARK)->delete();
    }

    private function setSetting(Tenant $t, string $key, $val): void
    {
        $s = $t->settings ?? [];
        $s[$key] = $val;
        $t->settings = $s;
        $t->save();
    }

    private function unsetSetting(Tenant $t, string $key): void
    {
        $s = $t->settings ?? [];
        unset($s[$key]);
        $t->settings = $s;
        $t->save();
    }
}
