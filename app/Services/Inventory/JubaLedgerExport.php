<?php declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * PDF and CSV renderers for the Juba Stock product-transaction drill-down.
 *
 * Reads exactly what JubaLedger::forProduct() returns — the same figures the on-screen page shows —
 * so a downloaded report reconciles to the screen. csv() needs no PDF engine; pdf() is Dompdf (all
 * tables, which Dompdf lays out reliably) and returns null when the engine isn't installed.
 */
final class JubaLedgerExport
{
    /** One flat, Excel-friendly workbook-as-CSV: a summary block, then Purchases, Sales and the Ledger. */
    public function csv(array $L): string
    {
        $p = $L['product']; $s = $L['summary']; $u = $p['unit'];
        $out = "\xEF\xBB\xBF";   // BOM so Excel reads UTF-8

        $out .= $this->row(['Product transaction report']);
        $out .= $this->row(['Product', $p['name']]);
        $out .= $this->row(['SKU / ID', ($p['sku'] !== '' ? $p['sku'] . ' · ' : '') . '#' . $p['id']]);
        $out .= $this->row(['Category', $p['category']]);
        $out .= $this->row(['Generated', now()->format('d M Y H:i')]);
        $out .= "\n";
        $openNote = $L['opening']['new'] ? 'new line' : ('counted ' . $this->d($L['opening']['at']) . ($L['opening']['by'] !== '' ? ' by ' . $L['opening']['by'] : ''));
        $out .= $this->row(['Counted (opening)', $this->n($s['opening']), $u, $openNote]);
        $out .= $this->row(['Bought since', $this->n($s['bought']), $u]);
        $out .= $this->row(['Sold since', $this->n($s['sold']), $u]);
        $out .= $this->row(['Free to sell', $this->n($s['current']), $u]);
        if ((float) $s['reserved'] > 0) {
            $out .= $this->row(['Reserved', $this->n($s['reserved']), $u]);
            $out .= $this->row(['On shelf', $this->n($s['onShelf']), $u]);
        }
        $out .= "\n";

        $out .= $this->row(['PURCHASES']);
        $out .= $this->row(['Date', 'Supplier', 'PO / ref', 'Qty (' . $u . ')', 'Unit cost', 'Total', 'Currency', 'Recorded by', 'Basis']);
        foreach ($L['purchases'] as $r) {
            $out .= $this->row([
                $this->d($r['at']), $r['supplier'] ?? '', $r['ref'] ?? '', $this->n($r['qty']),
                $r['unit'] !== null ? $this->n($r['unit']) : '', $this->n($r['total'] ?? 0), $r['currency'] ?? '',
                $r['user'] ?? '', ($r['since'] ?? false) ? 'since count' : 'in count',
            ]);
        }
        $out .= "\n";

        $out .= $this->row(['SALES']);
        $out .= $this->row(['Date', 'Customer', 'Invoice / order', 'Qty (' . $u . ')', 'Price', 'Total', 'Currency', 'Source', 'Basis']);
        foreach ($L['sales'] as $r) {
            $out .= $this->row([
                $this->d($r['at']), $r['customer'] ?? '', $r['doc'] ?? '', $this->n($r['qty']),
                (float) ($r['unit'] ?? 0) > 0 ? $this->n($r['unit']) : '', (float) ($r['total'] ?? 0) > 0 ? $this->n($r['total']) : '',
                $r['currency'] ?? '', ($r['reserved'] ?? false) ? 'reserved' : (($r['src'] ?? '') === 'invoice' ? 'invoice' : 'order'),
                ($r['since'] ?? false) ? 'since count' : 'in count',
            ]);
        }
        $out .= "\n";

        $out .= $this->row(['STOCK MOVEMENT LEDGER']);
        $out .= $this->row(['Date', 'Type', 'Document', 'Party', 'In', 'Out', 'Balance (' . $u . ')']);
        foreach ($L['ledger'] as $e) {
            $out .= $this->row([
                $this->d($e['at']), $this->typeLabel((string) $e['type']), $e['doc'] ?? '', $e['party'] ?? '',
                (float) $e['in'] > 0 ? $this->n($e['in']) : '', (float) $e['out'] > 0 ? $this->n($e['out']) : '',
                $this->n($e['balance']),
            ]);
        }

        return $out;
    }

    public function pdf(Tenant $t, array $L): ?string
    {
        if (! class_exists(\Dompdf\Dompdf::class)) {
            return null;
        }
        try {
            $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false, 'isHtml5ParserEnabled' => true]);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->loadHtml($this->html($t, $L));
            $dompdf->render();

            return $dompdf->output();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function html(Tenant $t, array $L): string
    {
        $p = $L['product']; $s = $L['summary']; $u = $this->e($p['unit']);
        $org = $this->e((string) ($t->name ?? 'CloudBSS'));

        $flow = 'Counted ' . $this->n($s['opening']) . ' &nbsp;+&nbsp; Bought ' . $this->n($s['bought'])
              . ' &nbsp;&minus;&nbsp; Sold ' . $this->n($s['sold']) . ' &nbsp;=&nbsp; <b>Free to sell ' . $this->n($s['current']) . '</b> ' . $u;
        if ((float) $s['reserved'] > 0) {
            $flow .= ' &nbsp;(Reserved ' . $this->n($s['reserved']) . ', On shelf ' . $this->n($s['onShelf']) . ')';
        }

        $css = 'body{font-family:DejaVu Sans,Arial,sans-serif;color:#111;font-size:11px}'
             . 'h1{font-size:16px;margin:0 0 2px}h2{font-size:12px;margin:16px 0 4px;color:#0d9488}'
             . '.sub{color:#666;font-size:10px;margin:0 0 2px}'
             . '.flow{background:#f0fdf9;border:1px solid #cceee7;padding:7px 9px;border-radius:5px;margin:8px 0}'
             . 'table{width:100%;border-collapse:collapse;margin-top:3px}'
             . 'th{background:#f4f5f7;text-align:left;padding:4px 6px;border-bottom:1px solid #ddd;font-size:9.5px;text-transform:uppercase;color:#555}'
             . 'td{padding:4px 6px;border-bottom:1px solid #eee;font-size:10px}.r{text-align:right}.muted{color:#888}'
             . '.empty{color:#888;padding:6px}';

        $h = '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>';
        $h .= '<div class="sub">' . $org . ' · Juba Stock</div>';
        $h .= '<h1>' . $this->e($p['name']) . '</h1>';
        $h .= '<div class="sub">' . ($p['sku'] !== '' ? $this->e($p['sku']) . ' · ' : '') . $this->e($p['category'])
            . ' · generated ' . now()->format('d M Y H:i') . '</div>';
        $h .= '<div class="flow">' . $flow . '</div>';

        // Purchases
        $h .= '<h2>Purchases — ' . $this->n($s['bought']) . ' ' . $u . ' since the count</h2><table>'
            . '<tr><th>Date</th><th>Supplier</th><th>PO / ref</th><th class="r">Qty</th><th class="r">Unit cost</th><th class="r">Total</th><th>By</th><th class="r">Basis</th></tr>';
        if ($L['purchases']) {
            foreach ($L['purchases'] as $r) {
                $cur = (string) ($r['currency'] ?? '');
                $h .= '<tr><td>' . $this->d($r['at']) . '</td><td>' . $this->e((string) ($r['supplier'] ?? '')) . '</td>'
                    . '<td>' . $this->e((string) ($r['ref'] ?? '')) . '</td><td class="r">' . $this->n($r['qty']) . '</td>'
                    . '<td class="r">' . ($r['unit'] !== null ? $this->money((float) $r['unit'], $cur) : '—') . '</td>'
                    . '<td class="r">' . $this->money((float) ($r['total'] ?? 0), $cur) . '</td>'
                    . '<td>' . $this->e((string) ($r['user'] ?? '')) . '</td>'
                    . '<td class="r muted">' . (($r['since'] ?? false) ? 'since' : 'in count') . '</td></tr>';
            }
        } else {
            $h .= '<tr><td colspan="8" class="empty">No purchases recorded.</td></tr>';
        }
        $h .= '</table>';

        // Sales
        $h .= '<h2>Sales — ' . $this->n($s['sold']) . ' ' . $u . ' since the count</h2><table>'
            . '<tr><th>Date</th><th>Customer</th><th>Invoice / order</th><th class="r">Qty</th><th class="r">Price</th><th class="r">Total</th><th>Source</th><th class="r">Basis</th></tr>';
        if ($L['sales']) {
            foreach ($L['sales'] as $r) {
                $cur = (string) ($r['currency'] ?? '');
                $src = ($r['reserved'] ?? false) ? 'reserved' : (($r['src'] ?? '') === 'invoice' ? 'invoice' : 'order');
                $h .= '<tr><td>' . $this->d($r['at']) . '</td><td>' . $this->e((string) ($r['customer'] ?? '')) . '</td>'
                    . '<td>' . $this->e((string) ($r['doc'] ?? '')) . '</td><td class="r">' . $this->n($r['qty']) . '</td>'
                    . '<td class="r">' . ((float) ($r['unit'] ?? 0) > 0 ? $this->money((float) $r['unit'], $cur) : '—') . '</td>'
                    . '<td class="r">' . ((float) ($r['total'] ?? 0) > 0 ? $this->money((float) $r['total'], $cur) : '—') . '</td>'
                    . '<td>' . $this->e($src) . '</td>'
                    . '<td class="r muted">' . (($r['since'] ?? false) ? 'since' : 'in count') . '</td></tr>';
            }
        } else {
            $h .= '<tr><td colspan="8" class="empty">No sales matched to this product since the count.</td></tr>';
        }
        $h .= '</table>';

        // Ledger
        $h .= '<h2>Stock movement ledger</h2><table>'
            . '<tr><th>Date</th><th>Type</th><th>Document</th><th>Party</th><th class="r">In</th><th class="r">Out</th><th class="r">Balance</th></tr>';
        if ($L['ledger']) {
            foreach ($L['ledger'] as $e) {
                $h .= '<tr><td>' . $this->d($e['at']) . '</td><td>' . $this->e($this->typeLabel((string) $e['type'])) . '</td>'
                    . '<td>' . $this->e((string) ($e['doc'] ?? '')) . '</td><td>' . $this->e((string) ($e['party'] ?? '')) . '</td>'
                    . '<td class="r">' . ((float) $e['in'] > 0 ? '+' . $this->n($e['in']) : '') . '</td>'
                    . '<td class="r">' . ((float) $e['out'] > 0 ? '−' . $this->n($e['out']) : '') . '</td>'
                    . '<td class="r">' . $this->n($e['balance']) . ' ' . $u . '</td></tr>';
            }
        } else {
            $h .= '<tr><td colspan="7" class="empty">Nothing has moved since the count.</td></tr>';
        }
        $h .= '</table>';

        $h .= '</body></html>';

        return $h;
    }

    private function typeLabel(string $t): string
    {
        return ['count' => 'Stock count', 'purchase' => 'Purchase in', 'sale' => 'Sale out',
            'reserved' => 'Reserved (sold, on shelf)'][$t] ?? ucfirst($t);
    }

    private function n($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.') ?: '0';
    }

    private function money(float $v, string $cur): string
    {
        $cur = strtoupper(trim($cur));
        $zero = in_array($cur, ['UGX', 'KES', 'TZS', 'RWF', 'BIF', 'SSP', 'JPY', 'XAF', 'XOF'], true);
        $n = number_format($v, $zero ? 0 : 2, '.', ',');

        return $cur === 'USD' ? '$' . $n : ($n . ($cur !== '' ? ' ' . $cur : ''));
    }

    private function d(?string $at): string
    {
        $at = trim((string) $at);
        if ($at === '') { return ''; }
        try { return Carbon::parse($at)->format('d M Y'); }
        catch (\Throwable $e) { return substr($at, 0, 10); }
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /** One CSV row: quote every field, escape embedded quotes. */
    private function row(array $cells): string
    {
        return implode(',', array_map(fn ($c) => '"' . str_replace('"', '""', (string) $c) . '"', $cells)) . "\n";
    }
}
