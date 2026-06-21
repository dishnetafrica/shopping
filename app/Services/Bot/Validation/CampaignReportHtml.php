<?php

namespace App\Services\Bot\Validation;

/**
 * Platform Validation Campaign — monthly report renderer. Pure: turns CampaignMetrics::monthlyReport
 * data into a standalone HTML page (leaderboard, category accuracy, the five answers, verdict).
 */
class CampaignReportHtml
{
    public static function render(array $report): string
    {
        $period = self::esc((string) ($report['period'] ?? ''));
        $n      = (int) ($report['businesses'] ?? 0);
        $q      = $report['questions'] ?? [];
        $cat    = $report['category_avg'] ?? [];
        $verdict = $report['verdict'] ?? [];

        // leaderboard
        $lb = '';
        $rank = 1;
        foreach ($report['leaderboard'] ?? [] as $b) {
            $lb .= '<tr>'
                . '<td class="rank">#' . $rank++ . '</td>'
                . '<td>' . self::esc(ucfirst($b['business_type'])) . '</td>'
                . '<td class="num">' . (int) $b['businesses'] . '</td>'
                . '<td class="num">' . self::accCell((int) $b['avg_accuracy']) . '</td>'
                . '<td class="num">' . (int) $b['avg_corrections'] . '%</td>'
                . '<td class="num">' . (int) $b['avg_time'] . ' min</td>'
                . '<td class="num">' . (int) $b['avg_readiness'] . '%</td>'
                . '<td class="num"><b>' . (int) $b['ease_score'] . '</b></td>'
                . '</tr>';
        }
        if (! $lb) $lb = '<tr><td colspan="8" class="empty">No reviewed businesses in this period.</td></tr>';

        // category accuracy bars
        $catBars = '';
        $labels = ['products_accuracy' => 'Products', 'faq_accuracy' => 'FAQs', 'delivery_accuracy' => 'Delivery', 'offer_accuracy' => 'Offers', 'language_accuracy' => 'Language'];
        foreach ($labels as $k => $label) {
            $v = (int) ($cat[$k] ?? 0);
            $catBars .= '<div class="bar"><span class="bl">' . self::esc($label) . '</span>'
                . '<span class="bt"><span class="bf" style="width:' . $v . '%"></span></span>'
                . '<span class="bv">' . $v . '%</span></div>';
        }

        // questions
        $pred = $q['best_predictor'] ?? ['feature' => '—', 'correlation' => 0];
        $answers = [
            ['1. Which type onboards easiest?', self::esc(ucfirst((string) ($q['easiest_type'] ?? '—'))) . ' (ease ' . (int) ($q['easiest_score'] ?? 0) . ')'],
            ['2. Which needs most corrections?', self::esc(ucfirst((string) ($q['most_corrections_type'] ?? '—'))) . ' (' . (int) ($q['most_corrections_pct'] ?? 0) . '%)'],
            ['3. How many messages are needed?', '~' . (int) ($q['messages_needed'] ?? 0) . ' messages'],
            ['4. Average readiness score?', (int) ($q['avg_readiness'] ?? 0) . '%'],
            ['5. What predicts success?', self::esc(self::pretty((string) $pred['feature'])) . ' (r = ' . $pred['correlation'] . ')'],
        ];
        $qHtml = '';
        foreach ($answers as $a) $qHtml .= '<div class="qa"><div class="qq">' . $a[0] . '</div><div class="qaa">' . $a[1] . '</div></div>';

        $can = (bool) ($verdict['can_go_operational'] ?? false);
        $vClass = $can ? 'ok' : 'bad';
        $vText = self::esc((string) ($verdict['statement'] ?? ''));

        return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monthly Validation Report — {$period}</title>
<style>
:root{--bg:#0e1116;--card:#171b22;--line:#262c36;--ink:#e6e9ef;--mut:#8b94a3;--ok:#2fbf71;--bad:#e0533d;--accent:#5b8def}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;padding:28px;max-width:1000px}
h1{font-size:23px;margin:0 0 2px}h2{font-size:15px;color:var(--mut);text-transform:uppercase;letter-spacing:.05em;margin:26px 0 10px}
.sub{color:var(--mut);margin:0 0 8px}
table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden}
th,td{padding:10px 13px;text-align:left;border-bottom:1px solid var(--line)}th{color:var(--mut);font-size:12px;text-transform:uppercase;letter-spacing:.04em}
td.num{text-align:right;font-variant-numeric:tabular-nums}td.rank{color:var(--mut)}tr:last-child td{border-bottom:0}
.empty{color:var(--mut);text-align:center}.acc-hi{color:var(--ok);font-weight:600}.acc-lo{color:var(--bad);font-weight:600}
.bar{display:flex;align-items:center;gap:10px;margin:7px 0}.bl{width:90px;color:var(--mut);font-size:13px}
.bt{flex:1;height:10px;background:#222936;border-radius:6px;overflow:hidden}.bf{display:block;height:100%;background:var(--accent)}
.bv{width:44px;text-align:right;font-variant-numeric:tabular-nums}
.qa{display:flex;justify-content:space-between;gap:14px;padding:9px 0;border-bottom:1px solid var(--line)}
.qq{color:var(--mut)}.qaa{font-weight:600;text-align:right}
.verdict{margin-top:24px;padding:16px 18px;border-radius:12px;border:1px solid var(--line)}
.verdict.ok{background:rgba(47,191,113,.1);border-color:rgba(47,191,113,.4)}
.verdict.bad{background:rgba(224,83,61,.1);border-color:rgba(224,83,61,.4)}
.verdict .q{color:var(--mut);font-size:13px;margin-bottom:4px}
</style></head><body>
<h1>Monthly Validation Report</h1>
<p class="sub">{$period} · {$n} reviewed businesses · target 70–90% readiness in 15–30 min</p>

<h2>Business Type Leaderboard</h2>
<table><thead><tr>
<th>Rank</th><th>Type</th><th>Shops</th><th>Accuracy</th><th>Corrections</th><th>Time</th><th>Readiness</th><th>Ease</th>
</tr></thead><tbody>{$lb}</tbody></table>

<h2>Category Accuracy</h2>
{$catBars}

<h2>Campaign Questions</h2>
{$qHtml}

<div class="verdict {$vClass}">
<div class="q">Can businesses reach 70–90% automation readiness within 15–30 minutes of onboarding?</div>
<div>{$vText}</div>
</div>
</body></html>
HTML;
    }

    private static function pretty(string $feature): string
    {
        return [
            'messages_scanned'     => 'message volume',
            'products_found'       => 'products found',
            'faq_found'            => 'FAQs found',
            'delivery_rules_found' => 'delivery rules found',
        ][$feature] ?? $feature;
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
