<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Catalog\KitService;
use Illuminate\Http\Request;

/**
 * Kit / Bundle API (Asset Lifecycle · S4a). Reads open to any tenant user; importing (which writes
 * kit definitions) requires owner/manager. Kits are built by CSV import — no engine, pricing or stock
 * is touched. Tenant scoping is automatic.
 */
class KitController extends Controller
{
    private function tenantUser(Request $request)
    {
        $u = $request->user();
        return ($u && $u->tenant_id) ? $u : null;
    }

    private function isManager($u): bool
    {
        return $u->isOwnerLike() || in_array((string) $u->role, ['manager'], true);
    }

    /** GET /papi/kits/template — a starter CSV to fill in and import. */
    public function template(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);

        $csv = KitService::forTenant($u->tenant)->templateCsv();
        return response($csv, 200)
            ->header('Content-Type', 'text/csv; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="kit-components-template.csv"');
    }

    /** GET /papi/kits — list bundles with a component count + parts value. */
    public function index(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);

        return response()->json(['ok' => true, 'kits' => KitService::forTenant($u->tenant)->listKits()]);
    }

    /** GET /papi/products/{product}/kit?qty=1 — a kit's exploded parts (read-only preview). */
    public function show(Request $request, int $product)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);

        $qty = (float) $request->query('qty', 1);
        return response()->json(['ok' => true, 'parts' => KitService::forTenant($u->tenant)->explode($product, $qty)]);
    }

    /**
     * POST /papi/kits/import — import a kit-components CSV. Send the CSV text as `csv`, OR a base64
     * upload as `file`. dry-run unless apply=1. Owner/manager only.
     */
    public function import(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $csv = (string) $request->input('csv', '');
        if (trim($csv) === '') {
            $b64 = (string) $request->input('file', '');
            if (str_contains($b64, ',')) {
                $b64 = substr($b64, strpos($b64, ',') + 1);
            }
            $bin = base64_decode($b64, true);
            if ($bin !== false) {
                $csv = (string) $bin;
            }
        }
        if (trim($csv) === '') {
            return response()->json(['ok' => false, 'error' => 'no_csv'], 422);
        }

        $apply = (int) $request->input('apply', 0) === 1;
        $res = KitService::forTenant($u->tenant)->importCsv($csv, $apply);
        $status = ($res['ok'] ?? false) ? 200 : 422;

        return response()->json($res, $status);
    }
}
