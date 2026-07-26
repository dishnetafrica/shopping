<?php declare(strict_types=1);

namespace App\Services\Advisor;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * Project Advisor — branded PDF export of a proposal.
 *
 * Renders the stored, sectioned proposal into a professional A4 PDF using the app's existing dompdf
 * stack (same engine as invoices/quotations — no new dependency). html() is pure and unit-testable;
 * pdf() wraps dompdf. The document is tenant-branded (name, logo, contact) and lays out all eleven
 * sections. All figures come straight from the stored proposal; nothing is recomputed here.
 */
class ProposalPdfRenderer
{
    public function available(): bool
    {
        return class_exists(\Dompdf\Dompdf::class);
    }

    /** Raw PDF bytes for a proposal. */
    public function pdf(Tenant $t, array $proposal): string
    {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($this->html($t, $proposal));
        $dompdf->setPaper('A4');
        $dompdf->render();
        return (string) $dompdf->output();
    }

    /** The full branded HTML document (pure — no dompdf, no I/O beyond reading the logo). */
    public function html(Tenant $t, array $proposal): string
    {
        $cur   = (string) ($proposal['currency'] ?? 'UGX');
        $money = fn ($v) => e($cur . ' ' . number_format((float) $v, 0));
        $name  = e((string) $t->name);
        $city  = e((string) ($t->setting('city', '') ?? ''));
        $wa    = e((string) ($t->whatsapp_number ?? ''));
        $logo  = $this->logo($t);
        $title = e((string) ($proposal['title'] ?? 'Solar Proposal'));

        $head = '<div class="hd">'
            . ($logo ? '<img class="logo" src="' . $logo . '">' : '')
            . '<div class="co"><div class="con">' . $name . '</div>'
            . '<div class="cod">' . trim($city . ($city && $wa ? ' · ' : '') . ($wa ? ('WhatsApp ' . $wa) : '')) . '</div></div>'
            . '</div>'
            . '<h1>' . $title . '</h1>';

        $body = '';
        foreach ((array) ($proposal['sections'] ?? []) as $s) {
            $body .= $this->section($s, $money, $cur);
        }

        $foot = '<div class="ft">' . $name . ($wa ? ' · WhatsApp ' . $wa : '') . ' — thank you for the opportunity.</div>';

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans, sans-serif;color:#1a1a1a;font-size:12px;line-height:1.5}'
            . '.hd{border-bottom:2px solid #d97706;padding-bottom:10px;margin-bottom:8px}'
            . '.logo{max-height:54px;max-width:180px;vertical-align:middle;margin-right:12px}'
            . '.co{display:inline-block;vertical-align:middle}.con{font-size:18px;font-weight:bold}.cod{color:#666;font-size:11px}'
            . 'h1{font-size:20px;margin:6px 0 14px}'
            . 'h2{font-size:13px;color:#d97706;border-bottom:1px solid #eee;padding-bottom:3px;margin:16px 0 6px}'
            . 'table{width:100%;border-collapse:collapse}'
            . 'td,th{padding:4px 2px;text-align:left;vertical-align:top}'
            . '.kv td:last-child{text-align:right;font-weight:bold}'
            . '.eq th{border-bottom:1px solid #ddd;color:#666;font-weight:normal}'
            . '.eq td.n,.eq th.n{text-align:right}.eq td.c,.eq th.c{text-align:center}'
            . '.tot{border-top:1px solid #ddd;font-weight:bold}'
            . 'ul{margin:4px 0;padding-left:18px}li{margin:2px 0}'
            . 'p{margin:2px 0 8px}.mut{color:#666}'
            . '.ft{margin-top:20px;border-top:1px solid #eee;padding-top:8px;color:#666;font-size:10px;text-align:center}'
            . '</style></head><body>' . $head . $body . $foot . '</body></html>';
    }

    private function section(array $s, callable $money, string $cur): string
    {
        $key = (string) ($s['key'] ?? '');
        $h = '<h2>' . e((string) ($s['title'] ?? '')) . '</h2>';
        $inner = '';

        if (! empty($s['body'])) {
            $inner .= '<p>' . nl2br(e((string) $s['body'])) . '</p>';
        }

        if ($key === 'equipment_list') {
            $items = (array) ($s['items'] ?? []);
            if ($items) {
                $rows = '<table class="eq"><tr><th>Item</th><th class="c">Qty</th><th class="n">Unit</th><th class="n">Total</th></tr>';
                foreach ($items as $it) {
                    $rows .= '<tr><td>' . e((string) ($it['name'] ?? '')) . ($it['sku'] ?? '' ? ' <span class="mut">' . e((string) $it['sku']) . '</span>' : '') . '</td>'
                        . '<td class="c">' . e((string) ($it['qty'] ?? '')) . '</td>'
                        . '<td class="n">' . $money($it['unit_price'] ?? 0) . '</td>'
                        . '<td class="n">' . $money($it['line_total'] ?? 0) . '</td></tr>';
                }
                if (isset($s['subtotal'])) {
                    $rows .= '<tr class="tot"><td colspan="3">Subtotal</td><td class="n">' . $money($s['subtotal']) . '</td></tr>';
                }
                $inner .= $rows . '</table>';
            } elseif (! empty($s['note'])) {
                $inner .= '<p class="mut">' . e((string) $s['note']) . '</p>';
            }
        } elseif (! empty($s['groups'])) {
            foreach ((array) $s['groups'] as $g) {
                $inner .= '<p><b>' . e((string) ($g['product'] ?? '')) . '</b></p><table class="kv">';
                foreach ((array) ($g['specs'] ?? []) as $sp) {
                    $inner .= '<tr><td>' . e((string) ($sp['label'] ?? '')) . '</td><td>' . e((string) ($sp['value'] ?? '')) . '</td></tr>';
                }
                $inner .= '</table>';
            }
        } elseif ($key === 'commercial_offer') {
            $inner .= '<table class="kv">';
            foreach ((array) ($s['rows'] ?? []) as $r) {
                $inner .= '<tr><td>' . e((string) ($r['label'] ?? '')) . '</td><td>' . $money($r['value'] ?? 0) . '</td></tr>';
            }
            if (isset($s['total'])) {
                $inner .= '<tr class="tot"><td>Total</td><td>' . $money($s['total']) . '</td></tr>';
            }
            $inner .= '</table>';
        } elseif (! empty($s['rows'])) {
            $inner .= '<table class="kv">';
            foreach ((array) $s['rows'] as $r) {
                $v = $r['value'] ?? '';
                $inner .= '<tr><td>' . e((string) ($r['label'] ?? '')) . '</td><td>' . e(is_numeric($v) ? (string) $v : (string) $v) . '</td></tr>';
            }
            $inner .= '</table>';
        }

        // Warranty per-component list.
        if ($key === 'warranty' && ! empty($s['items'])) {
            $inner .= '<table class="kv">';
            foreach ((array) $s['items'] as $w) {
                $inner .= '<tr><td>' . e((string) ($w['product'] ?? '')) . '</td><td>' . e((string) ($w['warranty'] ?? '')) . '</td></tr>';
            }
            $inner .= '</table>';
        } elseif (in_array($key, ['assumptions', 'exclusions', 'terms'], true) && ! empty($s['items'])) {
            $inner .= '<ul>';
            foreach ((array) $s['items'] as $x) {
                $inner .= '<li>' . e(is_string($x) ? $x : (string) ($x['label'] ?? '')) . '</li>';
            }
            $inner .= '</ul>';
        }

        return $h . ($inner !== '' ? $inner : '<p class="mut">—</p>');
    }

    /** The tenant logo as a data URI (dompdf embeds it), or '' if none. Mirrors the invoice renderer. */
    private function logo(Tenant $t): string
    {
        $raw = (string) ($t->setting('logo', '') ?? '');
        if ($raw === '') {
            return '';
        }
        try {
            if (str_starts_with($raw, 'data:')) {
                return $raw;
            }
            $rel = ltrim(preg_replace('#^https?://[^/]+/storage/#', '', $raw), '/');
            $rel = preg_replace('#^storage/#', '', $rel);
            if (Storage::disk('public')->exists($rel)) {
                $mime = str_ends_with(strtolower($rel), '.png') ? 'image/png' : (str_ends_with(strtolower($rel), '.webp') ? 'image/webp' : 'image/jpeg');
                return 'data:' . $mime . ';base64,' . base64_encode(Storage::disk('public')->get($rel));
            }
        } catch (\Throwable $e) {
        }
        return '';
    }
}
