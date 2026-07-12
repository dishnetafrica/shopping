<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Dashboard\DashboardRegistry;
use App\Dashboard\DashboardWidget;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * The role dashboard endpoint (Roadmap E10.1). Returns the widgets for the signed-in user's role,
 * each with its value computed from SSOT services/projections. One endpoint; the home screen renders
 * it behind dash_v2 (E10.2). A widget that errors is skipped rather than breaking the whole board.
 */
class DashboardController extends Controller
{
    /** GET /papi/dashboard */
    public function index(Request $r)
    {
        $u = $r->user();
        if (! $u || ! $u->tenant_id) return response()->json(['ok' => false], 401);
        $t = $u->tenant;

        $registry = DashboardRegistry::default();
        $widgets = $registry->forRole((string) ($u->role ?? ''));

        $out = [];
        foreach ($widgets as $w) {
            /** @var DashboardWidget $w */
            try {
                $out[] = [
                    'key'   => $w->key(),
                    'title' => $w->title(),
                    'type'  => $w->type(),
                    'value' => $w->value($t, $u),
                ];
            } catch (\Throwable $e) {
                // skip a failing widget; never break the whole dashboard
            }
        }

        return response()->json([
            'ok'      => true,
            'role'    => (string) ($u->role ?? ''),
            'widgets' => $out,
        ]);
    }
}
