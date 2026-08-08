<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Services\Growth\GrowthSignalsService;
use App\Services\Growth\ReorderSignals;

/**
 * v4.55 — Growth: who has gone quiet, and who is about to.
 *
 * Read-only, and deliberately small. It answers one question — which customers should somebody
 * ring today — and answers it out of the order book, with no new data and nothing to configure.
 *
 * Every row carries its own reasoning: how often that customer normally buys, how far past it they
 * are, and how much to trust the answer. A row that says "43 days late, usually every 2 weeks" can
 * be argued with; a row that says "at risk" cannot, and gets ignored by the second week.
 *
 * v4.61 — the value column and the "at risk" total are money COLLECTED, not money ordered. A
 * customer who took USD 7,836 of stock and paid USD 3,000 is worth USD 3,000 to this shop; the rest
 * is a receivable, and there is a screen for those. Ranking a call list by what was ordered puts the
 * customer who does not pay at the top of it.
 */
final class GrowthCapability implements ProcessCapability
{
    /** Below this, the cadence is a hint rather than a finding, and the screen says so. */
    private const SHAKY = 0.4;

    public function key(): string { return 'growth'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $data   = app(GrowthSignalsService::class)->forTenant($ctx->tenant);
        $counts = $data['counts'];
        $cur    = strtoupper((string) ($ctx->tenant->setting('currency', 'USD') ?: 'USD'));
        $money  = fn ($v) => $cur . ' ' . number_format((float) $v, $cur === 'USD' ? 2 : 0);

        // Only the three states worth acting on. A healthy customer needs no row, and a customer
        // with two orders needs a rep, not a report.
        $act = array_values(array_filter(
            $data['rows'],
            fn ($r) => in_array($r['state'], ['due', 'overdue', 'lapsed'], true)
        ));

        $atRisk = 0.0;
        foreach ($act as $r) {
            if ($r['state'] !== 'due') {
                $atRisk += (float) $r['value'];
            }
        }

        $badge = [
            'due'     => ['Due now', 'warn'],
            'overdue' => ['Late', 'danger'],
            'lapsed'  => ['Gone quiet', 'danger'],
        ];

        $rows = [];
        foreach ($act as $r) {
            [$label, $tone] = $badge[$r['state']];
            $shaky = (float) $r['confidence'] < self::SHAKY;

            $rows[] = [
                'customer' => ['value' => (string) $r['name'], 'sub' => (string) $r['phone'], 'avatar' => true],
                'state'    => ['value' => $label, 'badge' => $tone],
                'reason'   => (string) $r['reason'],
                'last'     => (int) $r['daysSince'],
                'value'    => ['value' => $money($r['value']), 'align' => 'right'],
                // Shown, not hidden. A rep deciding whether to drive across Juba deserves to know
                // the difference between a customer who buys like clockwork and one who does not.
                'trust'    => ['value' => $shaky ? 'Low' : (((float) $r['confidence'] >= 0.7) ? 'High' : 'Fair'),
                               'badge' => $shaky ? 'default' : (((float) $r['confidence'] >= 0.7) ? 'ok' : 'warn')],
                'link'     => $r['id'] ? '/api/v1/workspace/customer/' . (int) $r['id'] : null,
            ];
        }

        $columns = [
            ['key' => 'customer', 'label' => 'Customer', 'type' => 'avatar', 'sortable' => true],
            ['key' => 'state',    'label' => 'Status',   'type' => 'badge',  'sortable' => true],
            ['key' => 'reason',   'label' => 'Why',      'sortable' => false],
            ['key' => 'last',     'label' => 'Days since order', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'value',    'label' => 'Collected 12m', 'align' => 'right', 'sortable' => true],
            ['key' => 'trust',    'label' => 'Confidence', 'type' => 'badge', 'sortable' => true],
        ];

        // A number nobody can check is a number nobody should trust, so the tile says which of the
        // two ways it could be understated actually applies.
        //
        // `unpaid` is the one worth reading. It counts customers who ordered inside the window with
        // no receipt recorded at all — normally credit, and occasionally a shop banking its cash
        // somewhere this system cannot see, in which case every figure in the column is low.
        if ($data['unpriced'] > 0) {
            $foot = number_format($data['unpriced']) . ' receipts not counted (no base-currency value)';
        } elseif (($data['unpaid'] ?? 0) > 0) {
            $foot = 'collected · ' . number_format($data['unpaid']) . ' ordered but never paid';
        } else {
            $foot = 'collected, trailing 12 months';
        }

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:growth', 'title' => 'Growth',
                'subtitle' => count($act) . ' customers worth a call · ranked by money collected, not money ordered',
            ])
            ->headline($money($atRisk), 'at risk — late or gone quiet', $atRisk > 0 ? 'warn' : 'ok')
            ->financials([
                ['key' => 'due',     'label' => 'Due now',      'value' => (int) ($counts['due'] ?? 0),     'foot' => 'about to be late'],
                ['key' => 'overdue', 'label' => 'Late',         'value' => (int) ($counts['overdue'] ?? 0), 'tone' => 'warn',   'foot' => 'past their usual gap'],
                ['key' => 'lapsed',  'label' => 'Gone quiet',   'value' => (int) ($counts['lapsed'] ?? 0),  'tone' => 'danger', 'foot' => 'well past — may be lost'],
                ['key' => 'risk',    'label' => 'Value at risk', 'value' => $money($atRisk), 'tone' => 'warn', 'foot' => $foot],
                ['key' => 'new',     'label' => 'Too new to judge', 'value' => (int) ($counts['too_new'] ?? 0),
                 'foot' => 'fewer than ' . ReorderSignals::MIN_ORDERS . ' orders'],
            ])
            ->table($columns, $rows)
            ->meta(['capability' => 'growth', 'ttl' => 300, 'permissions' => ['menu:customers']]);
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        $c = app(GrowthSignalsService::class)->forTenant($ctx->tenant)['counts'];

        return ['at_risk' => (int) (($c['overdue'] ?? 0) + ($c['lapsed'] ?? 0))];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if ($action === 'refresh') {
            GrowthSignalsService::forget((int) $ctx->tenantId());

            return ['ok' => true, 'message' => 'Recalculated from the latest orders.'];
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
