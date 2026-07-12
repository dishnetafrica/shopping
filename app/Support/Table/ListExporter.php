<?php declare(strict_types=1);

namespace App\Support\Table;

/**
 * Exports a list/report's FULL (filtered) set to CSV or PDF (Roadmap E8.2). Rows are associative
 * arrays keyed by column key; columns are ['key','label','type']. The same helper serves every
 * register and every report, so exports look and behave identically everywhere. PDF uses dompdf (the
 * library the app already renders invoices with).
 */
class ListExporter
{
    /** Build a CSV string: a label header row, then one row per record. */
    public static function csv(array $columns, array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, array_map(fn ($c) => (string) ($c['label'] ?? $c['key'] ?? ''), $columns));
        foreach ($rows as $r) {
            $line = [];
            foreach ($columns as $c) {
                $line[] = self::scalar($r[$c['key']] ?? '');
            }
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /** Build a printable HTML table (pure — testable without dompdf). */
    public static function htmlTable(string $title, array $columns, array $rows): string
    {
        $esc = fn ($v) => htmlspecialchars(self::scalar($v), ENT_QUOTES, 'UTF-8');
        $head = '';
        foreach ($columns as $c) $head .= '<th>' . $esc($c['label'] ?? $c['key'] ?? '') . '</th>';
        $body = '';
        foreach ($rows as $r) {
            $body .= '<tr>';
            foreach ($columns as $c) {
                $numeric = in_array(($c['type'] ?? 'text'), ['number', 'money'], true);
                $body .= '<td class="' . ($numeric ? 'num' : '') . '">' . $esc($r[$c['key']] ?? '') . '</td>';
            }
            $body .= '</tr>';
        }
        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:11px;color:#111}'
            . 'h1{font-size:16px;margin:0 0 8px}table{width:100%;border-collapse:collapse}'
            . 'th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}th{background:#f2f2f2}'
            . 'td.num{text-align:right}</style></head><body>'
            . '<h1>' . $esc($title) . '</h1>'
            . '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>'
            . '</body></html>';
    }

    /** Render the table to a PDF (binary) via dompdf. */
    public static function pdf(string $title, array $columns, array $rows): string
    {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml(self::htmlTable($title, $columns, $rows));
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        return (string) $dompdf->output();
    }

    /** A download response in the requested format (csv default). */
    public static function response(string $format, string $title, array $columns, array $rows)
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($title));
        $slug = trim((string) $slug, '-') ?: 'export';

        if (strtolower($format) === 'pdf') {
            return response(self::pdf($title, $columns, $rows), 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $slug . '.pdf"',
            ]);
        }
        return response(self::csv($columns, $rows), 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $slug . '.csv"',
        ]);
    }

    private static function scalar($v): string
    {
        if ($v === null) return '';
        if (is_bool($v)) return $v ? '1' : '0';
        if (is_array($v)) return json_encode($v);
        return (string) $v;
    }
}
