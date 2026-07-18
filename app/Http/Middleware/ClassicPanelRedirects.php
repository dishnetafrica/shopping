<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Phase D (V3-DEFAULT-MIGRATION-PLAN): retire the classic panel by redirecting its
 * PAGE routes to their v3 workspaces. papi/* endpoints are untouched — v3 consumes
 * them — and any classic screen without a true v3 home stays reachable (honest tail).
 *
 * Safety properties, in the spirit of the 2026-07-18 incident:
 *   - Gated per tenant: setting `v3_redirects`, DEFAULT OFF. Deploying this is a no-op.
 *   - `?classic=1` bypasses every redirect (deep-link + support escape, kept one quarter).
 *   - GET only; signed-in users only; unmapped paths pass straight through.
 *   - Flip / rollback at runtime: `php artisan v3:retire {tenant}` / `--off`. No deploy.
 */
class ClassicPanelRedirects
{
    /** classic page → v3 workspace key (only where a true v3 home exists). */
    public const MAP = [
        '/panel/chats'            => 'chats',
        '/panel/email-center'     => 'communication',
        '/panel/customers360'     => 'customers',
        '/panel/leads'            => 'leads',
        '/panel/invoices'         => 'invoices',
        '/panel/invoiced'         => 'invoices',
        '/panel/payments'         => 'payments',
        '/panel/payments-manager' => 'payments',
        '/panel/orders-costs'     => 'salesOrders',
        '/panel/purchases'        => 'purchase.process',
        '/panel/approvals'        => 'purchase.process',
        '/panel/buy-list'         => 'procurement.hub',
        '/panel/sourcing'         => 'procurement.hub',
        '/panel/suppliers'        => 'suppliers',
        '/panel/supplier-debt'    => 'suppliers',
        '/panel/inventory-count'  => 'warehouse',
        '/panel/trade-cashbook'   => 'cashbook',
        '/panel/cashbook'         => 'cashbook',
        '/panel/cash-position'    => 'cashbook',
        '/panel/staff-cash'       => 'cashbook',
        '/panel/my-cash'          => 'cashbook',
        '/panel/expenses'         => 'finance',
        '/panel/pnl'              => 'finance',
        '/panel/pnl-report'       => 'finance',
        '/panel/marketing'        => 'campaigns',
        '/panel/ads'              => 'ads',
        '/panel/setup'            => 'settings',
        '/panel/staff'            => 'settings',
        '/panel/system'           => 'settings',
        '/panel/consignment'      => 'consignments',
        '/panel/guide'            => 'guide',
        '/panel/today'            => 'briefing',
        '/panel/tasks'            => 'worklist',
        '/panel/operations'       => 'worklist',
    ];

    public function handle(Request $request, Closure $next)
    {
        if ($request->method() !== 'GET') {
            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');
        $key  = self::MAP[$path] ?? null;
        if ($key === null || $request->boolean('classic')) {
            return $next($request);
        }

        $u = $request->user();
        if (! $u || ! $u->tenant_id || ! $u->tenant
            || ! (bool) $u->tenant->setting('v3_redirects', false)) {
            return $next($request);
        }

        return redirect('/v3#/'.$key, 302);
    }
}
