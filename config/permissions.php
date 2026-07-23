<?php

/**
 * RBAC configuration (Constitution v1.0 · P0 Security). Consumed by App\Http\Middleware\PermissionGate.
 *
 * Rollout is GLOBAL, not a per-tenant opt-in (deny-by-default is the destination, not a feature a tenant
 * turns on). Ships 'off' (dormant). Sequence: off → report (observe the logs) → enforce.
 */
return [

    // off | report | enforce   — see PermissionGate. Default OFF so this ships with zero behaviour change.
    'mode' => env('RBAC_MODE', 'off'),

    'log_channel' => env('RBAC_LOG_CHANNEL', 'stack'),

    /**
     * Route → required Access action. Only SENSITIVE (mutating / money / admin) routes need an entry.
     * Key forms:
     *   'name:<route.name>'          preferred if the route is named, else
     *   '<METHOD> <uri-pattern>'     e.g. 'POST papi/suppliers/{id}/pay' (matches route()->uri()).
     * Every action here must exist in App\Support\Access::MATRIX. Actions marked NEW below are added to
     * MATRIX in Phase 0 (see RBAC-DESIGN-v1.md) — until then PermissionGate reports them as would-deny.
     */
    'routes' => [

        // ---- Money ----
        'POST papi/payments'                 => 'payment.record',
        'POST papi/record-payment'           => 'payment.record',
        'POST papi/invoice-pay'              => 'payment.record',
        'POST papi/expenses'                 => 'expense.book',    // NEW
        'POST papi/cashbook/add'             => 'cashbook.write',  // NEW
        'POST papi/ledger/manual'            => 'settings.manage', // owner-only manual GL
        'POST papi/partners/distribute'      => 'settings.manage', // owner-only
        'POST papi/suppliers/{id}/pay'       => 'supplier.pay',

        // ---- Billing documents ----
        'POST papi/invoice-void'             => 'invoice.void',

        // ---- Operations ----
        'GET papi/delete-product'            => 'product.delete',  // NEW
        'GET papi/update-status'             => 'order.status',    // NEW
        'POST papi/suppliers'                => 'supplier.manage', // NEW
        'POST papi/suppliers/{id}'           => 'supplier.manage', // NEW
        'POST papi/order-costs/{id}/delete'  => 'order.delete',

        // ---- Administration ----
        'POST papi/staff'                    => 'staff.manage',
        'POST papi/staff/{id}'               => 'staff.manage',

        // Settings writes are already canMenu('settings')-gated in the controller; add their routes here
        // in Phase 0 so the middleware is the single, consistent gate.
    ],
];
