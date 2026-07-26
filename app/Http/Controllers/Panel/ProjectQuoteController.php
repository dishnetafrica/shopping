<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\Sales\ProjectQuoteService;
use Illuminate\Http\Request;

/**
 * Project Quotes API (Asset Lifecycle · Phase 2 · S8). V3-native. Building a project quote (which writes
 * a real quotation) requires owner/manager; reading a quote's sections is open to any tenant user.
 * Tenant-scoped.
 */
class ProjectQuoteController extends Controller
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

    /**
     * POST /papi/project-quotes/from-kits — build a project quote from kits (+ optional extras).
     * Body: customer_name, customer_phone, kit_ids[] (or kit_id), extras[], extras_title, currency, send.
     */
    public function fromKits(Request $request)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);
        if (! $this->isManager($u)) return response()->json(['ok' => false, 'error' => 'not_allowed'], 403);

        $d = $request->only([
            'customer_name', 'customer_phone', 'kit_ids', 'kit_id', 'extras', 'extras_title', 'currency', 'send',
        ]);
        $res = ProjectQuoteService::forTenant($u->tenant)->fromKits($d);
        $status = ($res['ok'] ?? false) ? 200 : 422;

        return response()->json($res, $status);
    }

    /** GET /papi/project-quotes/{quotation} — a quotation's section grouping (if it's a project quote). */
    public function show(Request $request, int $quotation)
    {
        $u = $this->tenantUser($request);
        if (! $u) return response()->json(['ok' => false], 403);

        $q = Quotation::where('tenant_id', $u->tenant_id)->find($quotation);
        if (! $q) return response()->json(['ok' => false, 'error' => 'not_found'], 404);

        return response()->json([
            'ok' => true,
            'quote_no' => (string) $q->quote_no,
            'total' => (float) $q->total,
            'currency' => (string) $q->currency,
            'project' => ProjectQuoteService::forTenant($u->tenant)->sectionsFor($q),
        ]);
    }
}
