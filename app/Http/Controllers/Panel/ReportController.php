<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Reports\ReportDefinition;
use App\Reports\ReportRegistry;
use Illuminate\Http\Request;

/**
 * The ONE generic report endpoint (Roadmap E7.2, Constitution R4). Resolves a report definition from
 * the registry, validates incoming filters against what the definition declares, and returns its
 * rows — which the definition produces by reading ONLY SSOT services/projections. One endpoint serves
 * every report; adding a report is a register() call in ReportRegistry, not a new route.
 */
class ReportController extends Controller
{
    /** Financial reports are owner/manager/accountant only. */
    private function allowed(Request $r): bool
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) return false;
        return $u->isOwnerLike() || in_array((string) $u->role, ['manager', 'accountant'], true);
    }

    /** GET /papi/reports — list the available reports. */
    public function index(Request $r)
    {
        if (! $this->allowed($r)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $reports = array_map(
            fn (ReportDefinition $d) => ['key' => $d->key(), 'title' => $d->title()],
            ReportRegistry::default()->all()
        );
        return response()->json(['ok' => true, 'reports' => $reports]);
    }

    /** GET /papi/reports/{key} — the report's shape + rows for the given (validated) filters. */
    public function show(Request $r, string $key)
    {
        if (! $this->allowed($r)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $def = ReportRegistry::default()->get($key);
        if (! $def) return response()->json(['ok' => false, 'error' => 'unknown_report'], 404);

        $t = $r->user()->tenant;
        $filters = $this->validatedFilters($def, $r);

        try {
            $rows = $def->rows($t, $filters);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'report_failed', 'message' => $e->getMessage()], 500);
        }

        // E8.2 — export the full (filtered) report set as CSV or PDF.
        $format = strtolower((string) $r->query('format', ''));
        if (in_array($format, ['csv', 'pdf'], true)) {
            $columns = array_map(fn ($c) => $c->toArray(), $def->columns());
            return \App\Support\Table\ListExporter::response($format, $def->title(), $columns, $rows);
        }

        return response()->json([
            'ok'      => true,
            'key'     => $def->key(),
            'title'   => $def->title(),
            'columns' => array_map(fn ($c) => $c->toArray(), $def->columns()),
            'filters' => array_map(fn ($f) => $f->toArray(), $def->filters()),
            'applied' => $filters,
            'rows'    => $rows,
        ]);
    }

    /**
     * Only pass through filters the definition declares, and for a select, only a value from its
     * options. This keeps arbitrary request input out of the SSOT services the definition reads.
     *
     * @return array<string,string>
     */
    private function validatedFilters(ReportDefinition $def, Request $r): array
    {
        $out = [];
        foreach ($def->filters() as $f) {
            if (! $r->has($f->key)) continue;
            $val = trim((string) $r->query($f->key, ''));
            if ($val === '') continue;
            if ($f->type === 'select' && ! empty($f->options) && ! in_array($val, $f->options, true)) continue;
            $out[$f->key] = $val;
        }
        return $out;
    }
}
