<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Services\Sales\QuotationIntelligence;
use Illuminate\Http\Request;

/**
 * Sales-facing quotation intelligence endpoint. Returns the deterministic suggestions the sales
 * team sees when working a quotation: dispatch split (ship today vs awaiting stock), low-stock
 * lines, in-stock alternatives, the customer's usual items, volume-discount qualification, segment
 * and available credit. Read-only; reuses QuotationIntelligence. GET /papi/quotation-intel?id=
 */
class QuotationIntelController extends Controller
{
    public function intel(Request $r)
    {
        $t = $r->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'no_tenant'], 403);
        }
        app(\App\Support\TenantContext::class)->set($t->id);

        $q = Quotation::with('items')->where('tenant_id', $t->id)->find((int) $r->input('id'));
        if (! $q) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        return response()->json(app(QuotationIntelligence::class)->for($t, $q));
    }
}
