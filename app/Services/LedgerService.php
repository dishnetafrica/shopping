<?php declare(strict_types=1);

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerJournal;
use App\Models\LedgerLine;
use App\Models\OrderCost;
use Illuminate\Support\Facades\DB;

/**
 * Phase 1 double-entry engine.
 *
 * It does NOT change how the cashbook writes today. It DERIVES a proper general ledger
 * from the existing order_costs rows so that a Trial Balance / Balance Sheet / P&L can be
 * produced and PROVEN to balance (Debit = Credit). Any event that has no clean second side
 * today (owner capital with no equity, FX with no gain/loss) is given its correct counter-
 * account here — that is the whole point: the gaps become visible and balanced instead of
 * hidden.
 *
 * GL is kept in the functional currency (USD). Foreign-currency cash accounts also store the
 * native figure on each line for later reconciliation/revaluation (Phase 3).
 */
class LedgerService
{
    /** Standard chart of accounts. code => [name, type, normal, currency?] */
    public function chart(): array
    {
        return [
            // Assets
            '1010' => ['Cash on Hand — USD', 'asset', 'debit', 'USD'],
            '1011' => ['Cash on Hand — UGX', 'asset', 'debit', 'UGX'],
            '1012' => ['Cash on Hand — SSP', 'asset', 'debit', 'SSP'],
            '1100' => ['Bank', 'asset', 'debit', null],
            '1200' => ['Accounts Receivable', 'asset', 'debit', null],
            '1300' => ['Inventory', 'asset', 'debit', null],
            '1400' => ['Supplier Advances', 'asset', 'debit', null],
            '1900' => ['FX Clearing (unreconciled conversions)', 'asset', 'debit', null],
            // Liabilities
            '2100' => ['Accounts Payable', 'liability', 'credit', null],
            '2200' => ['Customer Advances', 'liability', 'credit', null],
            '2300' => ['Tax Payable (VAT/GST)', 'liability', 'credit', null],
            // Equity
            '3100' => ["Owner's Capital", 'equity', 'credit', null],
            '3200' => ["Owner's Drawings", 'equity', 'debit', null],
            '3900' => ['Retained Earnings', 'equity', 'credit', null],
            // Income
            '4100' => ['Sales Revenue', 'income', 'credit', null],
            '4900' => ['FX Gain', 'income', 'credit', null],
            // Expenses / COGS
            '5100' => ['Cost of Goods Sold / Purchases', 'expense', 'debit', null],
            '5200' => ['Operating Expenses', 'expense', 'debit', null],
            '5300' => ['Bank Charges', 'expense', 'debit', null],
            '5400' => ['Cash Short/Over', 'expense', 'debit', null],
            '5900' => ['FX Loss', 'expense', 'debit', null],
        ];
    }

    /** Ensure the chart exists for a tenant; returns code=>account_id map. */
    public function ensureChart(int $tenantId): array
    {
        $map = [];
        foreach ($this->chart() as $code => [$name, $type, $normal, $ccy]) {
            $a = LedgerAccount::firstOrCreate(
                ['tenant_id' => $tenantId, 'code' => $code],
                ['name' => $name, 'type' => $type, 'normal' => $normal, 'currency' => $ccy, 'is_active' => true]
            );
            $map[$code] = $a->id;
        }
        return $map;
    }

    private function cashCode(string $currency): string
    {
        $currency = strtoupper($currency);
        return $currency === 'UGX' ? '1011' : ($currency === 'SSP' ? '1012' : '1010');
    }

    /**
     * Map one order_costs row to a set of balanced lines: [ [code, debit, credit], ... ].
     * Returns null when the row has no company-level GL effect (pure internal cash custody,
     * e.g. giving float to staff or moving cash between people in the same currency).
     */
    public function linesForOrderCost(object $c): ?array
    {
        $usd = round((float) $c->usd_amount, 2);
        if ($usd <= 0) return null;
        $cash = $this->cashCode((string) $c->currency);
        $stage = (string) $c->stage;
        $category = strtolower((string) ($c->category ?? ''));
        $note = strtolower((string) ($c->note ?? ''));
        $pay = (string) ($c->pay_status ?? 'cash');

        // rejected rows never post
        if ((string) ($c->status ?? '') === 'rejected') return null;

        switch ($stage) {
            case 'funding':
            case 'cash_in':
                // FX conversion IN leg -> clears against FX Clearing (pairs with the OUT leg)
                if (str_contains($category, 'conversion') || str_contains($note, 'convert')) {
                    return [[$cash, $usd, 0], ['1900', 0, $usd]];       // Dr Cash / Cr FX Clearing
                }
                // owner capital / opening balance -> equity; a plain float to a person is custody-only
                if (str_contains($category, 'working capital') || str_contains($note, 'opening') || str_contains($category, 'capital')) {
                    return [[$cash, $usd, 0], ['3100', 0, $usd]];        // Dr Cash / Cr Owner's Capital
                }
                if (str_contains($category, 'overage')) {
                    return [[$cash, $usd, 0], ['5400', 0, $usd]];        // Dr Cash / Cr Cash Short-Over (gain)
                }
                if (str_contains($category, 'float') || str_contains($category, 'transfer')) {
                    return null;                                        // internal custody move — no GL effect at company level
                }
                // generic cash-in with no clear source: treat as capital injection (safest, keeps it balanced & visible)
                return [[$cash, $usd, 0], ['3100', 0, $usd]];

            case 'customer_payment':
                // money in from a customer -> settles receivable (Phase 1 treats all as AR settlement)
                return [[$cash, $usd, 0], ['1200', 0, $usd]];           // Dr Cash / Cr Accounts Receivable

            case 'purchase':
                // goods bought against an order
                if ($pay === 'credit') {
                    return [['5100', $usd, 0], ['2100', 0, $usd]];      // Dr COGS/Purchases / Cr Accounts Payable
                }
                return [['5100', $usd, 0], [$cash, 0, $usd]];           // Dr COGS/Purchases / Cr Cash

            case 'expense':
                return [['5200', $usd, 0], [$cash, 0, $usd]];           // Dr Operating Expenses / Cr Cash

            case 'supplier_payment':
                return [['2100', $usd, 0], [$cash, 0, $usd]];           // Dr Accounts Payable / Cr Cash

            case 'drawing':
                return [['3200', $usd, 0], [$cash, 0, $usd]];           // Dr Owner's Drawings / Cr Cash

            case 'cash_out':
                if (str_contains($category, 'conversion') || str_contains($note, 'convert')) {
                    // FX conversion leg (out): Cr Cash / Dr FX Clearing (the pair nets in 1900;
                    // any residual in 1900 is unrecognised FX gain/loss — surfaced, not hidden)
                    return [['1900', $usd, 0], [$cash, 0, $usd]];
                }
                if (str_contains($category, 'transfer')) return null;   // internal custody move
                if (str_contains($category, 'shortage')) {
                    return [['5400', $usd, 0], [$cash, 0, $usd]];       // Dr Cash Short-Over / Cr Cash
                }
                return [['5200', $usd, 0], [$cash, 0, $usd]];           // unclassified out -> expense (conservative)

            default:
                // unknown stage -> park against clearing so the TB still balances and it is visible
                return [['1900', $usd, 0], [$cash, 0, $usd]];
        }
    }

    /**
     * Post a balanced journal. Throws if debits != credits (the core invariant).
     * $lines = [ [accountId, debit, credit], ... ]
     */
    public function post(int $tenantId, array $lines, string $source, ?int $sourceId, string $memo, ?string $createdBy = null, $postedAt = null): LedgerJournal
    {
        $dr = 0.0; $cr = 0.0;
        foreach ($lines as $l) { $dr += (float) $l['debit']; $cr += (float) $l['credit']; }
        $dr = round($dr, 2); $cr = round($cr, 2);
        if (abs($dr - $cr) > 0.005) {
            throw new \RuntimeException("Unbalanced journal: debits {$dr} != credits {$cr} ({$source}#{$sourceId})");
        }
        return DB::transaction(function () use ($tenantId, $lines, $source, $sourceId, $memo, $createdBy, $postedAt, $dr) {
            $j = LedgerJournal::create([
                'tenant_id' => $tenantId, 'source' => $source, 'source_id' => $sourceId,
                'memo' => mb_substr($memo, 0, 500), 'amount_usd' => $dr, 'status' => 'posted',
                'created_by' => $createdBy, 'posted_at' => $postedAt ?: now(),
            ]);
            $j->ref = 'JV-' . str_pad((string) $j->id, 6, '0', STR_PAD_LEFT);
            $j->save();
            foreach ($lines as $l) {
                LedgerLine::create([
                    'tenant_id' => $tenantId, 'journal_id' => $j->id, 'account_id' => $l['account_id'],
                    'debit_usd' => round((float) $l['debit'], 2), 'credit_usd' => round((float) $l['credit'], 2),
                    'currency' => $l['currency'] ?? null, 'native_amount' => $l['native_amount'] ?? null,
                    'memo' => isset($l['memo']) ? mb_substr((string) $l['memo'], 0, 500) : null,
                ]);
            }
            return $j;
        });
    }

    /** Is live write-time posting enabled for this tenant? (dormant by default) */
    public function livePosting(int $tenantId): bool
    {
        try { return (bool) DB::table('ledger_config')->where('tenant_id', $tenantId)->value('live_posting'); }
        catch (\Throwable $e) { return false; }
    }

    public function setLivePosting(int $tenantId, bool $on): void
    {
        DB::table('ledger_config')->updateOrInsert(
            ['tenant_id' => $tenantId],
            ['live_posting' => $on, 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /** Post the journal for a single order_costs row (used by the live observer). Best-effort. */
    public function postForOrderCost(object $c): ?LedgerJournal
    {
        $spec = $this->linesForOrderCost($c);
        if ($spec === null) return null;
        $codeToId = $this->ensureChart((int) $c->tenant_id);
        $lines = [];
        foreach ($spec as [$code, $debit, $credit]) {
            if (! isset($codeToId[$code])) return null;
            $lines[] = ['account_id' => $codeToId[$code], 'debit' => $debit, 'credit' => $credit,
                'currency' => (string) $c->currency, 'native_amount' => (float) $c->native_amount];
        }
        return $this->post(
            (int) $c->tenant_id, $lines, 'order_cost', (int) $c->id,
            trim(((string) $c->category ? $c->category . ' — ' : '') . (string) $c->note) ?: (string) $c->stage,
            (string) ($c->created_by ?? ''), $c->created_at
        );
    }

    /**
     * Reverse the ACTIVE journals for a source row (append-only, immutable GL).
     * Posts a mirror-opposite "reversal" journal for each active journal and marks the
     * original as reversed. Nothing is ever deleted from the ledger.
     */
    public function reverseActiveFor(int $tenantId, string $source, int $sourceId): int
    {
        $active = LedgerJournal::where('tenant_id', $tenantId)->where('source', $source)
            ->where('source_id', $sourceId)->where('status', 'posted')->whereNull('reversed_by')->get();
        $n = 0;
        foreach ($active as $j) {
            $lines = LedgerLine::where('journal_id', $j->id)->get();
            $rev = LedgerJournal::create([
                'tenant_id' => $tenantId, 'source' => 'reversal', 'source_id' => $sourceId,
                'memo' => 'Reversal of ' . $j->ref, 'amount_usd' => $j->amount_usd, 'status' => 'posted',
                'posted_at' => now(),
            ]);
            $rev->ref = 'JV-' . str_pad((string) $rev->id, 6, '0', STR_PAD_LEFT);
            $rev->save();
            foreach ($lines as $l) {
                LedgerLine::create([
                    'tenant_id' => $tenantId, 'journal_id' => $rev->id, 'account_id' => $l->account_id,
                    'debit_usd' => $l->credit_usd, 'credit_usd' => $l->debit_usd,   // swapped
                    'currency' => $l->currency, 'native_amount' => $l->native_amount !== null ? -1 * (float) $l->native_amount : null,
                    'memo' => 'reversal',
                ]);
            }
            $j->reversed_by = $rev->id; $j->save();
            $n++;
        }
        return $n;
    }

    /**
     * Rebuild the ledger for a tenant from order_costs. Idempotent: it clears previously
     * derived journals (source=order_cost) and regenerates them, so it is safe to re-run.
     * Returns a summary.
     */
    public function backfillOrderCosts(int $tenantId): array
    {
        $codeToId = $this->ensureChart($tenantId);

        // clear previously derived journals (only the derived ones)
        $old = LedgerJournal::where('tenant_id', $tenantId)->where('source', 'order_cost')->pluck('id');
        if ($old->count()) {
            LedgerLine::whereIn('journal_id', $old)->delete();
            LedgerJournal::whereIn('id', $old)->delete();
        }

        $rows = OrderCost::where('tenant_id', $tenantId)->orderBy('id')->get();
        $posted = 0; $skipped = 0; $errors = [];
        foreach ($rows as $c) {
            $spec = $this->linesForOrderCost($c);
            if ($spec === null) { $skipped++; continue; }
            $lines = [];
            foreach ($spec as [$code, $debit, $credit]) {
                if (! isset($codeToId[$code])) { $errors[] = "missing account {$code}"; continue 2; }
                $lines[] = [
                    'account_id' => $codeToId[$code], 'debit' => $debit, 'credit' => $credit,
                    'currency' => (string) $c->currency, 'native_amount' => (float) $c->native_amount,
                ];
            }
            try {
                $this->post(
                    $tenantId, $lines, 'order_cost', (int) $c->id,
                    trim(((string) $c->category ? $c->category . ' — ' : '') . (string) $c->note) ?: (string) $c->stage,
                    (string) ($c->created_by ?? ''), $c->created_at
                );
                $posted++;
            } catch (\Throwable $e) {
                $errors[] = 'oc#' . $c->id . ': ' . $e->getMessage();
            }
        }
        return ['posted' => $posted, 'skipped' => $skipped, 'errors' => $errors, 'rows' => $rows->count()];
    }
}
