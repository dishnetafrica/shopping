<?php declare(strict_types=1);

namespace App\Capability\Object;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ObjectCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Services\Inventory\JubaLedger;
use Illuminate\Support\Carbon;

/**
 * Juba Stock drill-down — one product's transaction history, opened from a Juba Stock row.
 *
 * Every figure here is the SAME derivation as the Juba Stock list, resolved for one product by
 * App\Services\Inventory\JubaLedger and kept at line detail: the physical count is the opening,
 * purchases (order_costs) and sales (invoices, then uninvoiced orders) move it, and
 *
 *     Opening  +  Bought since  −  Sold since  =  Free to sell        On shelf = Free to sell + Reserved
 *
 * so the numbers on this page reconcile to the row that was clicked, and every increase and decrease
 * names the document it came from. Read-only. The breadcrumb (meta.listKey) walks back to Juba Stock.
 */
final class JubaProductCapability implements ObjectCapability
{
    public function key(): string { return 'juba.product'; }

    public function permission(): string { return 'menu:products'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        // Opened only by id from a Juba Stock row; the bare capability has no list of its own.
        return (new WorkspacePayload)->object(['type' => 'product'])
            ->meta(['capability' => 'juba.product', 'listKey' => 'juba.stock']);
    }

    public function forId(CapabilityContext $ctx, string $id): WorkspacePayload
    {
        $L = JubaLedger::forProduct($ctx->tenantId(), (int) $id);
        $p = $L['product'];
        $s = $L['summary'];
        $unit = $p['unit'];

        $current = (float) $s['current'];
        $onShelf = (float) $s['onShelf'];
        $status  = $onShelf > 0 ? 'in' : ($onShelf < 0 ? 'out' : 'low');

        // ── Summary tiles: Opening → +Bought → −Sold → Free to sell (+ Reserved, On shelf) ────────
        $fins = [
            ['key' => 'opening', 'label' => 'Counted (opening)', 'value' => $this->num($s['opening']) . ' ' . $unit,
             'tone' => 'default', 'foot' => $L['opening']['new'] ? 'new line — nothing before the count' :
                 ('counted ' . $this->dt($L['opening']['at']) . ($L['opening']['by'] !== '' ? ' by ' . $L['opening']['by'] : ''))],
            ['key' => 'bought', 'label' => 'Bought since', 'value' => '+' . $this->num($s['bought']) . ' ' . $unit,
             'tone' => $s['bought'] > 0 ? 'ok' : 'default', 'foot' => 'purchases after the count'],
            ['key' => 'sold', 'label' => 'Sold since', 'value' => '−' . $this->num($s['sold']) . ' ' . $unit,
             'tone' => $s['sold'] > 0 ? 'warn' : 'default', 'foot' => 'billed + ordered since the count'],
            ['key' => 'current', 'label' => 'Free to sell', 'value' => $this->num($current) . ' ' . $unit,
             'tone' => $current > 0 ? 'ok' : ($current < 0 ? 'danger' : 'default'), 'foot' => 'opening + bought − sold'],
        ];
        if ((float) $s['reserved'] > 0) {
            $fins[] = ['key' => 'reserved', 'label' => 'Reserved', 'value' => $this->num($s['reserved']) . ' ' . $unit,
                'tone' => 'warn', 'foot' => 'sold on an order still on the shelf'];
            $fins[] = ['key' => 'onshelf', 'label' => 'On shelf', 'value' => $this->num($onShelf) . ' ' . $unit,
                'tone' => 'ok', 'foot' => 'free to sell + reserved'];
        }

        // ── Purchase history ─────────────────────────────────────────────────────────────────────
        $buyRows = [];
        foreach ($L['purchases'] as $r) {
            $cur = (string) ($r['currency'] ?? '');
            $buyRows[] = ['cells' => [
                $this->dt($r['at']),
                (string) ($r['supplier'] ?? '') ?: '—',
                (string) ($r['ref'] ?? '') ?: '—',
                ['value' => $this->num($r['qty']) . ' ' . $unit, 'align' => 'right'],
                ['value' => $r['unit'] !== null ? $this->money((float) $r['unit'], $cur) : '—', 'align' => 'right'],
                ['value' => $this->money((float) ($r['total'] ?? 0), $cur), 'align' => 'right'],
                (string) ($r['user'] ?? '') ?: '—',
                ['value' => ($r['since'] ?? false) ? 'since count' : 'in count', 'align' => 'right',
                 'tone' => ($r['since'] ?? false) ? 'ok' : 'muted'],
            ], 'ts' => $this->ts($r['at'])];
        }
        $buyCard = ['kind' => 'table', 'title' => 'Purchase history', 'searchable' => true, 'dateFilter' => true,
            'sub' => $this->num($s['bought']) . ' ' . $unit . ' bought since the count'
                . ' · ' . count($buyRows) . ' line' . (count($buyRows) === 1 ? '' : 's') . ' shown',
            'columns' => [['label' => 'Date'], ['label' => 'Supplier'], ['label' => 'PO / ref'],
                ['label' => 'Qty', 'align' => 'right'], ['label' => 'Unit cost', 'align' => 'right'],
                ['label' => 'Total', 'align' => 'right'], ['label' => 'Recorded by'], ['label' => 'Basis', 'align' => 'right']],
            'rows' => $buyRows,
            'empty' => 'No purchases recorded against this product. Use Buy stock on a supplier to link one.',
            'pageSize' => 10];

        // ── Sales history ────────────────────────────────────────────────────────────────────────
        $saleRows = [];
        foreach ($L['sales'] as $r) {
            $cur = (string) ($r['currency'] ?? '');
            $who = (string) ($r['source'] ?? '');
            $saleRows[] = ['cells' => [
                $this->dt($r['at']),
                (string) ($r['customer'] ?? '') ?: '—',
                (string) ($r['doc'] ?? '') ?: '—',
                ['value' => $this->num($r['qty']) . ' ' . $unit, 'align' => 'right'],
                ['value' => (float) ($r['unit'] ?? 0) > 0 ? $this->money((float) $r['unit'], $cur) : '—', 'align' => 'right'],
                ['value' => (float) ($r['total'] ?? 0) > 0 ? $this->money((float) $r['total'], $cur) : '—', 'align' => 'right'],
                ['value' => ($r['reserved'] ?? false) ? 'reserved' : (($r['src'] ?? '') === 'invoice' ? ('invoice' . ($who ? ' · ' . $who : '')) : 'order'),
                 'align' => 'right', 'tone' => ($r['reserved'] ?? false) ? 'warn' : 'muted'],
                ['value' => ($r['since'] ?? false) ? 'since count' : 'in count', 'align' => 'right',
                 'tone' => ($r['since'] ?? false) ? 'ok' : 'muted'],
            ], 'ts' => $this->ts($r['at'])];
        }
        $saleCard = ['kind' => 'table', 'title' => 'Sales history', 'searchable' => true, 'dateFilter' => true,
            'sub' => $this->num($s['sold']) . ' ' . $unit . ' sold since the count'
                . ' · ' . count($saleRows) . ' line' . (count($saleRows) === 1 ? '' : 's') . ' shown',
            'columns' => [['label' => 'Date'], ['label' => 'Customer'], ['label' => 'Invoice / order'],
                ['label' => 'Qty', 'align' => 'right'], ['label' => 'Price', 'align' => 'right'],
                ['label' => 'Total', 'align' => 'right'], ['label' => 'Source', 'align' => 'right'], ['label' => 'Basis', 'align' => 'right']],
            'rows' => $saleRows,
            'empty' => 'No sales matched to this product since the count.',
            'pageSize' => 10];

        // ── Chronological stock movement ledger (running balance ends at Free to sell) ───────────
        $ledRows = [];
        $typeLabel = ['count' => 'Stock count', 'purchase' => 'Purchase in', 'sale' => 'Sale out', 'reserved' => 'Reserved (sold, on shelf)'];
        foreach ($L['ledger'] as $e) {
            $in  = (float) $e['in'];  $out = (float) $e['out'];
            $ledRows[] = ['cells' => [
                $this->dt($e['at']),
                ['value' => $typeLabel[$e['type']] ?? ucfirst((string) $e['type']),
                 'tone' => $e['type'] === 'purchase' ? 'ok' : ($e['type'] === 'sale' ? 'warn' : ($e['type'] === 'reserved' ? 'muted' : 'default'))],
                (string) ($e['doc'] ?? '') ?: '—',
                (string) ($e['party'] ?? '') ?: '—',
                ['value' => $in > 0 ? '+' . $this->num($in) : '', 'align' => 'right', 'tone' => 'ok'],
                ['value' => $out > 0 ? '−' . $this->num($out) : '', 'align' => 'right', 'tone' => 'warn'],
                ['value' => $this->num($e['balance']) . ' ' . $unit, 'align' => 'right'],
            ], 'ts' => $this->ts($e['at'])];
        }
        $ledCard = ['kind' => 'table', 'title' => 'Stock movement ledger', 'searchable' => true, 'dateFilter' => true,
            'sub' => 'From the count forward — every movement, oldest first. Closing balance = free to sell ('
                . $this->num($current) . ' ' . $unit . ').',
            'columns' => [['label' => 'Date'], ['label' => 'Type'], ['label' => 'Document'], ['label' => 'Party'],
                ['label' => 'In', 'align' => 'right'], ['label' => 'Out', 'align' => 'right'], ['label' => 'Balance', 'align' => 'right']],
            'rows' => $ledRows,
            'empty' => 'Nothing has moved since the count.',
            'pageSize' => 20];

        // ── Product info + audit answers ─────────────────────────────────────────────────────────
        $answers = [
            ['q' => 'SKU / product ID', 'value' => ($p['sku'] !== '' ? $p['sku'] . ' · ' : '') . '#' . $p['id'], 'sub' => 'Product'],
            ['q' => 'Category', 'value' => $p['category'] ?: '—', 'sub' => 'Product'],
            ['q' => 'Reorder point', 'value' => $p['reorder'] !== null ? ($this->num((float) $p['reorder']) . ' ' . $unit) : 'not set', 'sub' => 'Product'],
            ['q' => 'Last counted', 'value' => $L['opening']['new'] ? 'never (new line)' : ($this->dt($L['opening']['at']) . ($L['opening']['by'] !== '' ? ' · ' . $L['opening']['by'] : '')), 'sub' => 'Count'],
        ];
        foreach ($L['opening']['history'] as $h) {
            $answers[] = ['q' => 'Earlier count (superseded)', 'value' => $this->num($h['qty']) . ' ' . $unit . ' · ' . $this->dt($h['at']) . ($h['by'] !== '' ? ' · ' . $h['by'] : ''), 'sub' => 'Count history'];
        }

        // Re-count from here (reuses the existing product record_count command — one count writer).
        $recordCount = ['key' => 'record_count', 'label' => 'Record count', 'method' => 'POST',
            'endpoint' => '/api/v1/workspace/product/' . $p['id'] . '/record_count', 'actor' => 'human', 'tone' => 'primary',
            'schema' => ['counted' => 'number', 'location' => 'combo:Kampala|Juba', 'note' => 'text'],
            'labels' => ['counted' => 'Counted quantity — what is physically on the shelf right now',
                'location' => 'Where the stock is', 'note' => 'Note (optional)'],
            'prefill' => ['counted' => '', 'location' => 'Juba', 'note' => '']];
        $openProduct = ['key' => 'open_product', 'label' => 'Open full product', 'method' => 'GET',
            'endpoint' => '/api/v1/workspace/product/' . $p['id'], 'actor' => 'human', 'tone' => 'default'];
        $backToList = ['key' => 'back', 'label' => '← Juba Stock', 'method' => 'GET',
            'endpoint' => '/api/v1/workspace/juba.stock', 'actor' => 'human', 'tone' => 'default'];

        return (new WorkspacePayload)
            ->object([
                'type' => 'product', 'id' => (string) $p['id'], 'ref' => (string) $p['sku'], 'title' => (string) $p['name'],
                'subtitle' => trim(($p['sku'] !== '' ? $p['sku'] . ' · ' : '') . $p['category']), 'status' => $status,
            ])
            ->headline($this->num($onShelf) . ' ' . $unit, 'on shelf', $status === 'out' ? 'danger' : ($status === 'low' ? 'warn' : 'ok'))
            ->financials($fins)
            // A single tab routes the shell to the full record view (renderRecord → tabContent),
            // which is the ONLY path that renders overviewCards; without it the payload falls to the
            // inline object renderer that shows timeline/related only ("No linked records").
            ->tabs([['key' => 'overview', 'label' => 'Transactions']])
            ->overviewCards([$buyCard, $saleCard, $ledCard])
            ->answers($answers)
            ->actions([$recordCount, $openProduct, $backToList])
            ->meta(['capability' => 'juba.product', 'listKey' => 'juba.stock', 'ttl' => 30, 'permissions' => ['menu:products']]);
    }

    /** Read-only drill-down: no commands of its own. Record-count delegates to the product capability. */
    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        return ['ok' => false, 'error' => 'unknown_action'];
    }

    private function num($v): string
    {
        $f = (float) $v;

        return rtrim(rtrim(number_format($f, 3, '.', ','), '0'), '.') ?: '0';
    }

    private function money(float $v, string $cur): string
    {
        $cur = strtoupper(trim($cur));
        $zero = in_array($cur, ['UGX', 'KES', 'TZS', 'RWF', 'BIF', 'SSP', 'JPY', 'XAF', 'XOF'], true);
        $n = number_format($v, $zero ? 0 : 2, '.', ',');

        return $cur === 'USD' ? '$' . $n : ($n . ($cur !== '' ? ' ' . $cur : ''));
    }

    private function dt(?string $at): string
    {
        $at = trim((string) $at);
        if ($at === '') { return '—'; }
        try { return Carbon::parse($at)->format('d M Y'); }
        catch (\Throwable $e) { return substr($at, 0, 10); }
    }

    /** Unix seconds for the card table's date-range filter; 0 when the date can't be parsed. */
    private function ts(?string $at): int
    {
        $at = trim((string) $at);
        if ($at === '') { return 0; }
        try { return Carbon::parse($at)->getTimestamp(); }
        catch (\Throwable $e) { return 0; }
    }
}
