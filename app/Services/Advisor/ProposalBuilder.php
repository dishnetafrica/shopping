<?php declare(strict_types=1);

namespace App\Services\Advisor;

/**
 * Project Advisor — deterministic proposal assembly.
 *
 * Turns a captured project (discovery answers + engine sizing), a resolved equipment list, and a segment
 * template into a complete, structured proposal with the eleven standard sections. Pure: no DB, no I/O —
 * given the same inputs it always produces the same proposal. The AI may later rewrite the prose of the
 * executive summary, but every number here comes from the sizing engine, never from the AI.
 *
 * $equipment is a list of RESOLVED line items (the service resolves product ids → these):
 *   ['name'=>'…','sku'=>'…','qty'=>2,'unit_price'=>450000,'warranty'=>'…','specs'=>[['label','value','unit'],…]]
 */
class ProposalBuilder
{
    /**
     * @param array $project   ['name','profile','answers'=>[...], 'sizing'=>['result'=>[...],'assumptions'=>[...]]]
     * @param array $equipment resolved line items
     * @param array $template  ProposalTemplate::for($segment)
     * @param array $opts       ['currency'=>'UGX','install_fee'=>0,'vat_pct'=>0]
     */
    public static function build(array $project, array $equipment = [], array $template = [], array $opts = []): array
    {
        $answers = (array) ($project['answers'] ?? []);
        $sizing  = (array) ($project['sizing'] ?? []);
        $res     = (array) ($sizing['result'] ?? []);
        $assume  = (array) ($sizing['assumptions'] ?? []);
        $tpl     = $template ?: ProposalTemplate::for((string) ($answers['customer_type'] ?? ''));
        $currency = (string) ($opts['currency'] ?? 'UGX');

        $segment = (string) ($answers['customer_type'] ?? 'Customer');
        $pvKw    = round(((float) ($res['pv_array_w'] ?? 0)) / 1000, 2);
        $dailyKwh = (float) ($res['daily_energy_kwh'] ?? 0);
        $backup  = (float) ($answers['backup_hours'] ?? ($sizing['inputs']['backup_hours'] ?? 0));

        // ── Commercial maths (from the equipment list) ────────────────────────────────────────────
        $items = [];
        $subtotal = 0.0;
        foreach ($equipment as $e) {
            $qty   = max(0.0, (float) ($e['qty'] ?? 1));
            $price = max(0.0, (float) ($e['unit_price'] ?? 0));
            $line  = round($qty * $price, 2);
            $subtotal += $line;
            $items[] = [
                'name'       => (string) ($e['name'] ?? 'Item'),
                'sku'        => (string) ($e['sku'] ?? ''),
                'qty'        => $qty,
                'unit_price' => $price,
                'line_total' => $line,
            ];
        }
        $install = max(0.0, (float) ($opts['install_fee'] ?? 0));
        $vatPct  = max(0.0, (float) ($opts['vat_pct'] ?? 0));
        $taxable = $subtotal + $install;
        $vat     = round($taxable * $vatPct / 100, 2);
        $total   = round($taxable + $vat, 2);

        // ── Sections ──────────────────────────────────────────────────────────────────────────────
        return [
            'profile'  => (string) ($project['profile'] ?? 'solar'),
            'segment'  => $segment,
            'title'    => trim(($project['name'] ? $project['name'] . ' — ' : '') . 'Solar Proposal'),
            'currency' => $currency,
            'sections' => [
                [
                    'key' => 'executive_summary', 'title' => 'Executive Summary',
                    'body' => self::execSummary($tpl, $segment, $answers, $res, $pvKw, $dailyKwh, $backup),
                ],
                [
                    'key' => 'customer_requirements', 'title' => 'Customer Requirements',
                    'rows' => self::requirements($answers, $res),
                ],
                [
                    'key' => 'recommended_solution', 'title' => 'Recommended Solution',
                    'rows' => self::solution($res),
                ],
                [
                    'key' => 'equipment_list', 'title' => 'Equipment List',
                    'items' => $items, 'subtotal' => $subtotal, 'currency' => $currency,
                    'note' => $items ? null : 'Equipment to be finalised after site survey.',
                ],
                [
                    'key' => 'technical_specifications', 'title' => 'Technical Specifications',
                    'groups' => self::techSpecs($equipment),
                ],
                [
                    'key' => 'estimated_performance', 'title' => 'Estimated System Performance',
                    'rows' => self::performance($res, $backup, $dailyKwh),
                ],
                [
                    'key' => 'commercial_offer', 'title' => 'Commercial Offer',
                    'currency' => $currency,
                    'rows' => array_values(array_filter([
                        ['label' => 'Equipment subtotal', 'value' => $subtotal],
                        $install > 0 ? ['label' => 'Installation', 'value' => $install] : null,
                        $vat > 0 ? ['label' => 'VAT (' . rtrim(rtrim((string) $vatPct, '0'), '.') . '%)', 'value' => $vat] : null,
                    ])),
                    'total' => $total,
                ],
                [
                    'key' => 'warranty', 'title' => 'Warranty',
                    'body' => (string) ($tpl['warranty_note'] ?? ''),
                    'items' => self::warranties($equipment),
                ],
                [
                    'key' => 'assumptions', 'title' => 'Assumptions',
                    'items' => array_merge(self::sizingAssumptions($assume), (array) ($tpl['assumptions'] ?? [])),
                ],
                [
                    'key' => 'exclusions', 'title' => 'Exclusions',
                    'items' => (array) ($tpl['exclusions'] ?? []),
                ],
                [
                    'key' => 'terms', 'title' => 'Terms and Conditions',
                    'items' => (array) ($tpl['terms'] ?? []),
                ],
            ],
            'totals' => ['equipment_subtotal' => $subtotal, 'installation' => $install, 'vat' => $vat, 'total' => $total, 'currency' => $currency],
        ];
    }

    /** Map the equipment list to quotation line items — the reuse hook for the quotation + PDF engine. */
    public static function toQuotationLines(array $equipment): array
    {
        $lines = [];
        foreach ($equipment as $e) {
            $lines[] = [
                'name'       => (string) ($e['name'] ?? 'Item'),
                'sku'        => (string) ($e['sku'] ?? ''),
                'qty'        => max(0.0, (float) ($e['qty'] ?? 1)),
                'unit_price' => max(0.0, (float) ($e['unit_price'] ?? 0)),
            ];
        }
        return $lines;
    }

    // ── Section builders ────────────────────────────────────────────────────────────────────────

    private static function execSummary(array $tpl, string $segment, array $answers, array $res, float $pvKw, float $dailyKwh, float $backup): string
    {
        $objective = strtolower(trim((string) ($answers['project_objective'] ?? '')));
        $location  = trim((string) ($answers['location'] ?? ''));
        $hasBattery = (float) ($res['battery_bank_wh'] ?? 0) > 0;
        $system = $objective === 'full off-grid' ? 'off-grid solar system'
            : ($hasBattery ? 'solar system with battery backup' : 'solar system');

        $lead = (string) ($tpl['exec_lead'] ?? ProposalTemplate::BASE['exec_lead']);
        $parts = [$lead];
        $spec = 'We recommend a ' . ($pvKw > 0 ? $pvKw . ' kW ' : '') . $system
            . ($segment ? ' for ' . strtolower($segment) . ' use' : '')
            . ($location ? ' in ' . $location : '') . '.';
        $parts[] = $spec;
        if ($dailyKwh > 0) {
            $perf = 'It is sized to deliver approximately ' . self::n($dailyKwh) . ' kWh per day';
            if ($backup > 0) {
                $perf .= ' and to keep critical loads running for about ' . self::n($backup) . ' hours during an outage';
            }
            $parts[] = $perf . '.';
        }
        return implode(' ', $parts);
    }

    private static function requirements(array $a, array $res): array
    {
        $rows = [];
        $add = function (string $label, $value) use (&$rows) {
            $value = is_string($value) ? trim($value) : $value;
            if ($value !== '' && $value !== null) {
                $rows[] = ['label' => $label, 'value' => $value];
            }
        };
        $add('Customer type', (string) ($a['customer_type'] ?? ''));
        $add('Objective', (string) ($a['project_objective'] ?? ''));
        $add('Location', (string) ($a['location'] ?? ''));
        $add('Existing power source', (string) ($a['existing_power_source'] ?? ''));
        if (($a['monthly_kwh'] ?? '') !== '') $add('Monthly consumption', self::n((float) $a['monthly_kwh']) . ' kWh');
        if (($a['monthly_bill'] ?? '') !== '') $add('Monthly electricity bill', self::n((float) $a['monthly_bill']));
        $add('Required backup', ($a['backup_hours'] ?? '') !== '' ? self::n((float) $a['backup_hours']) . ' hours' : '');
        $add('Critical connected load', ($res['critical_load_w'] ?? 0) > 0 ? self::n((float) $res['critical_load_w']) . ' W' : '');
        if (($a['future_expansion'] ?? '') !== '') $add('Future expansion', (string) $a['future_expansion']);
        if (($a['budget'] ?? '') !== '') $add('Budget', self::n((float) $a['budget']));
        return $rows;
    }

    private static function solution(array $r): array
    {
        return [
            ['label' => 'Solar array', 'value' => self::n((float) ($r['pv_array_w'] ?? 0)) . ' W'
                . (($r['panel_count'] ?? null) ? ' (' . (int) $r['panel_count'] . ' panels)' : '')],
            ['label' => 'Battery bank', 'value' => self::n((float) ($r['battery_bank_wh'] ?? 0)) . ' Wh · '
                . self::n((float) ($r['battery_bank_ah'] ?? 0)) . ' Ah'],
            ['label' => 'Inverter', 'value' => self::n((float) ($r['inverter_va'] ?? 0)) . ' VA ('
                . self::n((float) ($r['inverter_w'] ?? 0)) . ' W)'],
            ['label' => 'Peak demand covered', 'value' => self::n((float) ($r['peak_demand_w'] ?? 0)) . ' W'],
        ];
    }

    private static function techSpecs(array $equipment): array
    {
        $groups = [];
        foreach ($equipment as $e) {
            $specs = (array) ($e['specs'] ?? []);
            if (! $specs) {
                continue;
            }
            $rows = [];
            foreach ($specs as $s) {
                $val = trim((string) ($s['value'] ?? ''));
                if ($val === '') {
                    continue;
                }
                $rows[] = ['label' => (string) ($s['label'] ?? ''), 'value' => $val . (($s['unit'] ?? '') ? ' ' . $s['unit'] : '')];
            }
            if ($rows) {
                $groups[] = ['product' => (string) ($e['name'] ?? 'Item'), 'specs' => $rows];
            }
        }
        return $groups;
    }

    private static function performance(array $r, float $backup, float $dailyKwh): array
    {
        return [
            ['label' => 'Estimated daily energy', 'value' => self::n($dailyKwh) . ' kWh/day'],
            ['label' => 'Backup autonomy (critical loads)', 'value' => self::n($backup) . ' hours'],
            ['label' => 'PV array', 'value' => self::n((float) ($r['pv_array_w'] ?? 0)) . ' W'],
            ['label' => 'Usable battery energy', 'value' => self::n((float) ($r['battery_bank_wh'] ?? 0)) . ' Wh'],
            ['label' => 'Continuous inverter output', 'value' => self::n((float) ($r['inverter_w'] ?? 0)) . ' W'],
        ];
    }

    private static function warranties(array $equipment): array
    {
        $out = [];
        foreach ($equipment as $e) {
            $w = trim((string) ($e['warranty'] ?? ''));
            if ($w !== '') {
                $out[] = ['product' => (string) ($e['name'] ?? 'Item'), 'warranty' => $w];
            }
        }
        return $out;
    }

    private static function sizingAssumptions(array $a): array
    {
        if (! $a) {
            return [];
        }
        $lines = [];
        if (isset($a['system_efficiency']))  $lines[] = 'System efficiency assumed at ' . self::pct($a['system_efficiency']) . '.';
        if (isset($a['depth_of_discharge'])) $lines[] = 'Battery depth-of-discharge assumed at ' . self::pct($a['depth_of_discharge']) . '.';
        if (isset($a['system_voltage']))     $lines[] = 'Battery bank nominal voltage of ' . self::n((float) $a['system_voltage']) . ' V.';
        if (isset($a['safety_margin']))      $lines[] = 'Inverter sized with a ' . self::pct(((float) $a['safety_margin']) - 1) . ' safety margin.';
        return $lines;
    }

    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ','), '0'), '.');
    }

    private static function pct(float $frac): string
    {
        return self::n($frac * 100) . '%';
    }
}
