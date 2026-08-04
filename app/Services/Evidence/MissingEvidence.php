<?php

namespace App\Services\Evidence;

use App\Models\Attachment;
use App\Models\CustomerPayment;
use App\Models\OrderCost;
use Illuminate\Support\Facades\Schema;

/**
 * Which transactions have no document behind them.
 *
 * This is the report that makes the feature worth having. Attaching receipts is only useful
 * if you can see, at a glance, the ones you have not attached — otherwise the gaps are
 * invisible until an auditor finds them.
 *
 * Money OUT is what an auditor asks about, so outflows are listed first and by default:
 * cash out, purchases, expenses and supplier payments. Customer receipts are included too
 * because a payment with no proof is the other half of the same question.
 *
 * THE NUMBERS ARE WHOLE-SET, THE LIST IS A PAGE. The counts and the "value with no
 * evidence" total are computed with aggregate queries over every matching transaction, not
 * over the rows shown. A summary that silently described only the first 300 rows while
 * calling itself the total would be worse than no summary at all — you would think you were
 * nearly done when you were not. `summary.capped` says whether the list below is a subset.
 *
 * Voided entries are excluded throughout — that is the house convention for every finance
 * reader in this codebase (PayablesCalculator, CashbookExport, SupplierStatement), and a
 * voided entry needs no evidence because it did not happen. A voided customer payment is
 * hard-deleted by PaymentController::destroy, so it disappears from this report on its own.
 */
final class MissingEvidence
{
    /** Stages that move money OUT of the business. */
    public const OUTFLOW = [
        'cash_out', 'purchase', 'expense', 'supplier_payment',
        'freight_bus', 'delivery_boda', 'transport', 'order_cost', 'drawing',
    ];

    /** The three subject slugs that all live in order_costs. */
    public const COST_SLUGS = ['cashbook', 'purchase', 'expense'];

    /**
     * @param  array{only?:string, min?:float, from?:string, to?:string, limit?:int}  $opt
     *         only: missing (default) | all
     * @return array{rows:array, summary:array}
     */
    public static function report(int $tenantId, array $opt = []): array
    {
        $only  = ($opt['only'] ?? 'missing') === 'all' ? 'all' : 'missing';
        $min   = max(0.0, (float) ($opt['min'] ?? 0));
        $limit = max(1, min(1000, (int) ($opt['limit'] ?? 300)));

        $summary = [
            'checked' => 0, 'missing' => 0, 'missing_usd' => 0.0,
            'with_evidence' => 0, 'documents' => 0, 'capped' => false, 'shown' => 0,
        ];

        if (! Schema::hasTable('order_costs')) {
            return ['rows' => [], 'summary' => $summary];
        }
        $migrated = EvidenceService::available();
        $rows = [];
        $listed = 0;

        /* ---------------- money out ---------------- */
        $costs = self::costQuery($tenantId, $opt, $min);
        $costsMissing = $migrated
            ? (clone $costs)->whereNotIn('id', self::documented($tenantId, self::COST_SLUGS))
            : (clone $costs);

        $summary['checked'] += (clone $costs)->count();
        $summary['missing'] += (clone $costsMissing)->count();
        $summary['missing_usd'] += (float) (clone $costsMissing)->sum('usd_amount');

        $page = ($only === 'missing' ? (clone $costsMissing) : (clone $costs))
            ->orderByDesc('usd_amount')->orderByDesc('id')->limit($limit)->get();
        $listed += ($only === 'missing' ? (int) $summary['missing'] : (int) $summary['checked']);

        $counts = self::costCounts($tenantId, $page, $migrated);
        foreach ($page as $c) {
            $docs = (int) ($counts[(int) $c->id] ?? 0);
            $summary['documents'] += $docs;
            $rows[] = [
                'type'     => self::slugFor((string) $c->stage),
                'id'       => (int) $c->id,
                'date'     => optional($c->created_at)->toDateString(),
                'stage'    => (string) $c->stage,
                'what'     => self::describe((string) $c->stage),
                'party'    => (string) ($c->supplier_name ?: $c->holder ?: ''),
                'currency' => (string) $c->currency,
                'native'   => (float) $c->native_amount,
                'usd'      => (float) $c->usd_amount,
                'note'     => mb_substr((string) ($c->note ?? ''), 0, 120),
                'docs'     => $docs,
                'legacy_receipt' => trim((string) ($c->receipt_path ?? '')) !== '',
            ];
        }

        /* ---------------- money in: customer receipts ---------------- */
        if (Schema::hasTable('customer_payments')) {
            $pays = self::paymentQuery($tenantId, $opt, $min);
            $paysMissing = $migrated
                ? (clone $pays)->whereNotIn('id', self::documented($tenantId, ['payment']))
                : (clone $pays);

            $summary['checked'] += (clone $pays)->count();
            $summary['missing'] += (clone $paysMissing)->count();
            $summary['missing_usd'] += (float) (clone $paysMissing)->sum('usd_amount');

            $ppage = ($only === 'missing' ? (clone $paysMissing) : (clone $pays))
                ->orderByDesc('usd_amount')->orderByDesc('id')->limit($limit)->get();
            $listed += ($only === 'missing'
                ? (int) (clone $paysMissing)->count()
                : (int) (clone $pays)->count());

            $pc = $migrated && count($ppage)
                ? EvidenceService::countsFor($tenantId, 'payment', self::ids($ppage))
                : [];

            foreach ($ppage as $p) {
                $docs = (int) ($pc[(int) $p->id] ?? 0);
                $summary['documents'] += $docs;
                $rows[] = [
                    'type'     => 'payment',
                    'id'       => (int) $p->id,
                    'date'     => optional($p->created_at)->toDateString(),
                    'stage'    => 'customer_payment',
                    'what'     => 'Customer receipt ' . (string) ($p->receipt_no ?? ''),
                    'party'    => (string) ($p->customer_name ?: $p->customer_phone ?: ''),
                    'currency' => (string) ($p->currency ?? ''),
                    'native'   => (float) ($p->amount ?? 0),
                    'usd'      => (float) ($p->usd_amount ?? 0),
                    'note'     => trim(((string) ($p->method ?? '')) . ' ' . ((string) ($p->reference ?? ''))),
                    'docs'     => $docs,
                    'legacy_receipt' => trim((string) ($p->receipt_path ?? '')) !== '',
                ];
            }
        }

        // Biggest gaps first — that is the order an auditor works in, and the order in which
        // a missing document actually matters.
        usort($rows, function ($a, $b) {
            return ($b['usd'] <=> $a['usd']) ?: ($b['id'] <=> $a['id']);
        });
        $rows = array_slice($rows, 0, $limit);

        $summary['with_evidence'] = max(0, $summary['checked'] - $summary['missing']);
        $summary['missing_usd']   = round($summary['missing_usd'], 2);
        $summary['documents']     = $migrated ? EvidenceService::countLive($tenantId) : 0;
        $summary['shown']         = count($rows);
        $summary['capped']        = $listed > count($rows);

        return ['rows' => $rows, 'summary' => $summary];
    }

    /** Which attachment slug a cashbook stage belongs to. */
    public static function slugFor(string $stage): string
    {
        if ($stage === 'purchase') {
            return 'purchase';
        }
        if ($stage === 'expense') {
            return 'expense';
        }

        return 'cashbook';
    }

    /** Money-out entries, void excluded, filtered by the caller's range. */
    private static function costQuery(int $tenantId, array $opt, float $min)
    {
        $q = OrderCost::where('tenant_id', $tenantId)
            ->whereIn('stage', self::OUTFLOW)
            ->where(function ($w) {
                $w->whereNull('status')->orWhere('status', '!=', 'void');
            });
        self::dateRange($q, $opt);
        if ($min > 0) {
            $q->where('usd_amount', '>=', $min);
        }

        return $q;
    }

    private static function paymentQuery(int $tenantId, array $opt, float $min)
    {
        $q = CustomerPayment::where('tenant_id', $tenantId);
        self::dateRange($q, $opt);
        if ($min > 0) {
            $q->where('usd_amount', '>=', $min);
        }

        return $q;
    }

    /**
     * A subquery of the ids that already carry live evidence, so "missing" can be counted in
     * the database instead of by pulling every id into PHP.
     */
    private static function documented(int $tenantId, array $slugs)
    {
        return Attachment::where('tenant_id', $tenantId)
            ->whereNull('detached_at')
            ->whereIn('subject_type', $slugs)
            ->select('subject_id');
    }

    /** Per-row document counts for the page being shown, across all three cost slugs. */
    private static function costCounts(int $tenantId, $page, bool $migrated): array
    {
        if (! $migrated || count($page) === 0) {
            return [];
        }
        $ids = self::ids($page);
        $counts = [];
        foreach (self::COST_SLUGS as $slug) {
            foreach (EvidenceService::countsFor($tenantId, $slug, $ids) as $id => $c) {
                $counts[(int) $id] = ($counts[(int) $id] ?? 0) + (int) $c;
            }
        }

        return $counts;
    }

    private static function ids($rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = (int) $r->id;
        }

        return $out;
    }

    private static function describe(string $stage): string
    {
        $map = [
            'cash_out'         => 'Cash out',
            'purchase'         => 'Purchase',
            'expense'          => 'Expense',
            'supplier_payment' => 'Supplier payment',
            'drawing'          => 'Owner drawing',
            'freight_bus'      => 'Freight',
            'delivery_boda'    => 'Delivery',
            'transport'        => 'Transport',
            'order_cost'       => 'Order cost',
        ];

        return $map[$stage] ?? ucfirst(str_replace('_', ' ', $stage));
    }

    private static function dateRange($q, array $opt): void
    {
        $from = trim((string) ($opt['from'] ?? ''));
        $to   = trim((string) ($opt['to'] ?? ''));
        if ($from !== '') {
            $q->whereDate('created_at', '>=', $from);
        }
        if ($to !== '') {
            $q->whereDate('created_at', '<=', $to);
        }
    }
}
