<?php

namespace App\Services\Tender;

use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\Tenant;
use App\Support\BotTrace;

/**
 * v4.22 TenderBot — the procurement worklist behind the "Items to price" board.
 *
 * What a back-office buyer actually does with a tender: pull every line out of the BOQ, find out what
 * each one costs, and turn that into a bid price. This service owns all three steps —
 *
 *   sync()        report.products_required  → tender_items rows (idempotent: re-analysing replaces
 *                                             only the lines nobody has priced yet)
 *   priceMemory() what we paid for the same item last time, and who supplied it
 *   setPrice()    capture a supplier cost → compute the bid price from the tenant's margin
 *   totals()      cost / bid / margin totals for a tender, and what is still unpriced
 *
 * Pricing rule (deliberately simple and inspectable): bid_unit = unit_cost × (1 + margin%).
 * The margin is the tenant's `tender_margin_pct` unless a line overrides it. Nothing here touches
 * PriceResolver — that owns SELLING catalogue stock to dealers; a tender bid is a different animal
 * (bought-to-order, per-line margin, no stock or floor involved).
 */
final class TenderItems
{
    /** Fallback margin when the tenant has not set one. */
    public const DEFAULT_MARGIN = 20.0;

    /** A tender with more lines than this is almost always a mis-parse; keep the board usable. */
    private const MAX_LINES = 200;

    public const STATUSES = ['to_price', 'quoted', 'priced', 'skip'];

    /**
     * Create/refresh the item rows for a tender from its analysis report.
     *
     * Idempotent by design: lines that already carry a captured price (or were skipped) are LEFT
     * ALONE, so re-analysing a tender never destroys the buyer's work. Everything else is rebuilt.
     *
     * @return int number of rows now on the board for this tender
     */
    public function sync(Tenant $tenant, Tender $tender): int
    {
        try {
            $rep = is_array($tender->report) ? $tender->report : [];
            $rows = $rep['products_required'] ?? [];
            if (! is_array($rows)) {
                return 0;
            }

            $keep = TenderItem::where('tenant_id', $tenant->id)->where('tender_id', $tender->id)
                ->whereIn('status', ['priced', 'skip'])->get();
            $keptKeys = [];
            foreach ($keep as $k) {
                $keptKeys[$this->key((string) $k->item)] = true;
            }

            TenderItem::where('tenant_id', $tenant->id)->where('tender_id', $tender->id)
                ->whereNotIn('status', ['priced', 'skip'])->delete();

            $n = 0;
            $line = 0;
            foreach ($rows as $r) {
                if ($n >= self::MAX_LINES) {
                    break;
                }
                // Tolerate a bare string ("40 bags of cement") as well as the structured shape.
                $r = is_array($r) ? $r : ['item' => (string) $r];
                $item = trim((string) ($r['item'] ?? $r['description'] ?? $r['product'] ?? ''));
                if ($item === '') {
                    continue;
                }
                $line++;
                if (isset($keptKeys[$this->key($item)])) {
                    continue;   // already priced/skipped — don't duplicate it
                }
                $qty = trim((string) ($r['qty'] ?? $r['quantity'] ?? ''));

                TenderItem::create([
                    'tenant_id' => $tenant->id,
                    'tender_id' => $tender->id,
                    'line' => $line,
                    'lot' => mb_substr(trim((string) ($r['lot'] ?? '')), 0, 60) ?: null,
                    'item' => mb_substr($item, 0, 300),
                    'qty' => mb_substr($qty, 0, 60) ?: null,
                    'qty_num' => $this->qtyNum($qty),
                    'unit' => mb_substr($this->unit($qty, (string) ($r['unit'] ?? '')), 0, 40) ?: null,
                    'spec' => trim((string) ($r['spec'] ?? $r['specification'] ?? '')) ?: null,
                    'brand' => mb_substr(trim((string) ($r['brand'] ?? '')), 0, 120) ?: null,
                    'alt_brand' => mb_substr(trim((string) ($r['alt_brand'] ?? '')), 0, 120) ?: null,
                    'needs_quote' => ($r['needs_quote'] ?? true) !== false,
                    'status' => 'to_price',
                ]);
                $n++;
            }

            $total = TenderItem::where('tenant_id', $tenant->id)->where('tender_id', $tender->id)->count();
            if ($n > 0) {
                BotTrace::log($tenant->id, 'tender_items', null, 'sync',
                    'tender ' . $tender->id . ' · ' . $n . ' new line(s), ' . $total . ' total', null);
            }

            return $total;
        } catch (\Throwable $e) {
            \Log::warning('TenderItems sync failed: ' . $e->getMessage());

            return 0;
        }
    }

    /**
     * What we last paid for this item (any tender, this tenant).
     *
     * @return array{unit_cost:float, currency:string, supplier:string, when:string}|null
     */
    public function priceMemory(Tenant $tenant, string $item, ?int $excludeId = null): ?array
    {
        try {
            $q = TenderItem::where('tenant_id', $tenant->id)
                ->whereNotNull('unit_cost')
                ->orderByDesc('priced_at')->orderByDesc('id')->limit(40);
            if ($excludeId) {
                $q->where('id', '!=', $excludeId);
            }
            $key = $this->key($item);
            foreach ($q->get() as $row) {
                if ($this->key((string) $row->item) === $key) {
                    return [
                        'unit_cost' => (float) $row->unit_cost,
                        'currency' => (string) ($row->currency ?: ''),
                        'supplier' => (string) ($row->supplier ?: ''),
                        'when' => optional($row->priced_at)->format('d M Y') ?: '',
                    ];
                }
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Capture a supplier cost on one line and compute its bid price.
     *
     * @return array{ok:bool, message:string, item?:TenderItem}
     */
    public function setPrice(Tenant $tenant, TenderItem $row, array $in, string $by = ''): array
    {
        $cost = $in['unit_cost'] ?? null;
        if ($cost !== null && trim((string) $cost) !== '') {
            $cost = (float) preg_replace('/[^0-9.]/', '', (string) $cost);
            if ($cost <= 0) {
                return ['ok' => false, 'message' => 'Unit cost must be a number greater than zero.'];
            }
            $row->unit_cost = $cost;
            $row->priced_at = now();
            $row->priced_by = mb_substr($by, 0, 120) ?: null;
        }
        foreach (['supplier' => 160, 'currency' => 8, 'note' => 2000] as $k => $len) {
            if (array_key_exists($k, $in)) {
                $row->{$k} = mb_substr(trim((string) $in[$k]), 0, $len) ?: null;
            }
        }
        if (array_key_exists('qty_num', $in) && trim((string) $in['qty_num']) !== '') {
            $row->qty_num = (float) preg_replace('/[^0-9.]/', '', (string) $in['qty_num']);
        }
        if (array_key_exists('margin_pct', $in) && trim((string) $in['margin_pct']) !== '') {
            $row->margin_pct = max(0, min(500, (float) preg_replace('/[^0-9.]/', '', (string) $in['margin_pct'])));
        }
        if (array_key_exists('status', $in) && in_array((string) $in['status'], self::STATUSES, true)) {
            $row->status = (string) $in['status'];
        }

        // bid price follows the cost unless somebody typed one in explicitly
        if (array_key_exists('bid_unit', $in) && trim((string) $in['bid_unit']) !== '') {
            $row->bid_unit = (float) preg_replace('/[^0-9.]/', '', (string) $in['bid_unit']);
        } elseif ($row->unit_cost !== null) {
            $row->bid_unit = round((float) $row->unit_cost * (1 + $this->margin($tenant, $row) / 100), 4);
        }

        // capturing a cost moves the line along on its own — one less thing to remember
        if ($row->unit_cost !== null && in_array($row->status, ['to_price', 'quoted'], true)
            && ! array_key_exists('status', $in)) {
            $row->status = 'priced';
        }

        $row->save();

        return ['ok' => true, 'message' => 'Saved.', 'item' => $row];
    }

    /** The margin that applies to a line: its own override, else the tenant default. */
    public function margin(Tenant $tenant, ?TenderItem $row = null): float
    {
        if ($row && $row->margin_pct !== null) {
            return (float) $row->margin_pct;
        }
        $m = $tenant->setting('tender_margin_pct', null);

        return is_numeric($m) ? (float) $m : self::DEFAULT_MARGIN;
    }

    /**
     * Bid-sheet totals for one tender.
     *
     * @return array{lines:int, priced:int, unpriced:int, cost:float, bid:float, profit:float, margin:float, currency:string}
     */
    public function totals(Tenant $tenant, Tender $tender): array
    {
        $rows = TenderItem::where('tenant_id', $tenant->id)->where('tender_id', $tender->id)
            ->where('status', '!=', 'skip')->get();
        $cost = 0.0;
        $bid = 0.0;
        $priced = 0;
        $cur = '';
        foreach ($rows as $r) {
            $lc = $r->lineCost();
            $lb = $r->lineBid();
            if ($lc !== null) {
                $cost += $lc;
                $priced++;
                $cur = $cur ?: (string) ($r->currency ?: '');
            }
            if ($lb !== null) {
                $bid += $lb;
            }
        }
        $cost = round($cost, 2);
        $bid = round($bid, 2);

        return [
            'lines' => $rows->count(),
            'priced' => $priced,
            'unpriced' => max(0, $rows->count() - $priced),
            'cost' => $cost,
            'bid' => $bid,
            'profit' => round($bid - $cost, 2),
            'margin' => $bid > 0 ? round(($bid - $cost) / $bid * 100, 1) : 0.0,
            'currency' => $cur,
        ];
    }

    /** "40 MT" → 40.0 · "8,000" → 8000.0 · "" → null. First number wins. */
    private function qtyNum(string $qty): ?float
    {
        if (! preg_match('/\d[\d,. ]*/', $qty, $m)) {
            return null;
        }
        $raw = str_replace([',', ' '], '', $m[0]);
        // a trailing dot ("40.") or thousands-dot ("8.000 pcs") would otherwise skew the number
        $raw = rtrim($raw, '.');
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        return round((float) $raw, 3);
    }

    /** Unit written alongside the quantity ("40 MT" → MT), unless the report already gave one. */
    private function unit(string $qty, string $given): string
    {
        $given = trim($given);
        if ($given !== '') {
            return $given;
        }
        if (preg_match('/\d[\d,. ]*\s*([A-Za-z%\/]{1,12})/', $qty, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /** Loose match key for price memory: case/space/punctuation-insensitive. */
    private function key(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', (string) $s));
    }
}
