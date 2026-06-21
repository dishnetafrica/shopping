<?php

namespace App\Services\Bot\Validation;

/**
 * Field Validation — dashboard renderer. Pure: turns FieldValidationProgram::dashboard() data into
 * a standalone HTML page (Validation Results table + success criteria + verdict). No framework deps.
 */
class FieldDashboardHtml
{
    public static function render(array $dashboard): string
    {
        $rows    = $dashboard['rows'] ?? [];
        $summary = $dashboard['summary'] ?? [];
        $verdict = $dashboard['verdict'] ?? [];

        $tbody = '';
        foreach ($rows as $r) {
            $acc  = $r['owner_approved_accuracy'] ?: $r['actual_accuracy'];
            $time = $r['time_to_go_live_min'] === null ? '—' : ($r['time_to_go_live_min'] . ' min');
            $tbody .= '<tr>'
                . '<td>' . self::esc($r['business_name'] ?: '—') . '</td>'
                . '<td>' . self::esc(ucfirst($r['business_type'])) . '</td>'
                . '<td>' . self::badge($r['status']) . '</td>'
                . '<td class="num">' . (int) $r['readiness_score'] . '%</td>'
                . '<td class="num">' . self::accCell($acc) . '</td>'
                . '<td class="num">' . $time . '</td>'
                . '<td class="num">' . (int) $r['owner_corrections_pct'] . '% (' . (int) $r['owner_edits_required'] . ')</td>'
                . '</tr>';
        }
        if (! $tbody) $tbody = '<tr><td colspan="7" class="empty">No businesses enrolled yet.</td></tr>';

        $crit = $summary['criteria'] ?? [];
        $cards = '';
        $cards .= self::card('Avg Accuracy', ($summary['avg_accuracy'] ?? 0) . '%', '≥ 80%', $crit['accuracy']['pass'] ?? false);
        $cards .= self::card('Avg Time to Go-Live', ($summary['avg_time_to_go_live'] ?? 0) . ' min', '≤ 30 min', $crit['time']['pass'] ?? false);
        $cards .= self::card('Avg Owner Corrections', ($summary['avg_corrections'] ?? 0) . '%', '≤ 20%', $crit['corrections']['pass'] ?? false);

        $can = (bool) ($verdict['can_go_operational'] ?? false);
        $verdictClass = $can ? 'ok' : 'bad';
        $verdictText = self::esc($verdict['statement'] ?? '');
        $n = (int) ($summary['businesses'] ?? 0);

        return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Field Validation Results</title>
<style>
:root{--bg:#0e1116;--card:#171b22;--line:#262c36;--ink:#e6e9ef;--mut:#8b94a3;--ok:#2fbf71;--bad:#e0533d;--accent:#5b8def}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;padding:28px}
h1{font-size:22px;margin:0 0 4px}.sub{color:var(--mut);margin:0 0 22px}
.cards{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:22px}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px 18px;min-width:180px;flex:1}
.card .lbl{color:var(--mut);font-size:13px}.card .val{font-size:26px;font-weight:700;margin:4px 0}
.card .tgt{font-size:12px;color:var(--mut)}.pill{float:right;font-size:12px;font-weight:600;padding:2px 9px;border-radius:999px}
.pill.ok{background:rgba(47,191,113,.15);color:var(--ok)}.pill.bad{background:rgba(224,83,61,.15);color:var(--bad)}
table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden}
th,td{padding:11px 13px;text-align:left;border-bottom:1px solid var(--line)}th{color:var(--mut);font-size:12px;text-transform:uppercase;letter-spacing:.04em}
td.num{text-align:right;font-variant-numeric:tabular-nums}tr:last-child td{border-bottom:0}
.empty{color:var(--mut);text-align:center}
.badge{font-size:12px;padding:2px 8px;border-radius:6px;background:#222936;color:var(--mut)}
.badge.live{background:rgba(47,191,113,.15);color:var(--ok)}.badge.scanned{background:rgba(91,141,239,.15);color:var(--accent)}
.acc-hi{color:var(--ok);font-weight:600}.acc-lo{color:var(--bad);font-weight:600}
.verdict{margin-top:22px;padding:16px 18px;border-radius:12px;border:1px solid var(--line)}
.verdict.ok{background:rgba(47,191,113,.1);border-color:rgba(47,191,113,.4)}
.verdict.bad{background:rgba(224,83,61,.1);border-color:rgba(224,83,61,.4)}
.verdict .q{color:var(--mut);font-size:13px;margin-bottom:4px}
</style></head><body>
<h1>Field Validation Results</h1>
<p class="sub">{$n} businesses · target: ≥80% accuracy · ≤30 min · ≤20% corrections</p>
<div class="cards">{$cards}</div>
<table><thead><tr>
<th>Business</th><th>Type</th><th>Status</th><th>Readiness</th><th>Accuracy</th><th>Time to Go-Live</th><th>Owner Corrections</th>
</tr></thead><tbody>{$tbody}</tbody></table>
<div class="verdict {$verdictClass}">
<div class="q">Can a completely new business become operational after importing WhatsApp history?</div>
<div>{$verdictText}</div>
</div>
</body></html>
HTML;
    }

    private static function card(string $label, string $value, string $target, bool $pass): string
    {
        $pill = $pass ? '<span class="pill ok">PASS</span>' : '<span class="pill bad">FAIL</span>';
        $l = self::esc($label); $v = self::esc($value); $t = self::esc($target);
        return "<div class=\"card\">{$pill}<div class=\"lbl\">{$l}</div><div class=\"val\">{$v}</div><div class=\"tgt\">target {$t}</div></div>";
    }

    private static function badge(string $status): string
    {
        $cls = in_array($status, ['live', 'scanned'], true) ? $status : '';
        return '<span class="badge ' . $cls . '">' . self::esc($status) . '</span>';
    }

    private static function accCell(int $acc): string
    {
        if ($acc <= 0) return '—';
        $cls = $acc >= 80 ? 'acc-hi' : 'acc-lo';
        return '<span class="' . $cls . '">' . $acc . '%</span>';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
