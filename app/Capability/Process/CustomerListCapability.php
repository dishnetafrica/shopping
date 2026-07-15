<?php

namespace App\Capability\Process;

use App\Capability\Contracts\CapabilityContext;
use App\Capability\Contracts\ProcessCapability;
use App\Capability\Dto\WorkspacePayload;
use App\Capability\Support\Money;
use App\Models\CustomerProfile;
use App\Models\Order;
use App\Services\Crm\CustomerWriteService;
use Illuminate\Support\Carbon;

/**
 * Customers directory — a list workspace (not a single-record projection). Returns headline KPIs
 * plus a sortable/filterable table. Reads only; every row links to the Customer 360 object workspace.
 *
 * Per-customer order stats (count / spend / balance / last order) come from ONE grouped query over
 * `orders`, joined in memory to the profile list — no N+1, no per-customer StatementService calls.
 */
final class CustomerListCapability implements ProcessCapability
{
    private const ACTIVE_DAYS = 90;
    private const MAX_ROWS = 500;

    public function key(): string { return 'customers'; }

    public function permission(): string { return 'menu:customers'; }

    public function project(CapabilityContext $ctx): WorkspacePayload
    {
        $t = $ctx->tenantId();
        // Currency-aware reporting: order totals are stored in each order's OWN currency (sell_currency,
        // falling back to the engine's native currency for untagged rows) — NOT the display base. We must
        // convert each to the report currency before summing, or a UGX/SSP total prints with a '$'.
        $rc = \App\Services\Money\ReportingCurrency::forContext($ctx);
        $native = $ctx->nativeCurrency();

        // One grouped pass over orders, keyed by normalised phone AND the order's currency, so a
        // mixed-currency history consolidates correctly instead of adding UGX to USD to SSP.
        // Cancelled / rejected / void orders are NOT sales and NOT receivables — exclude them from
        // spend, count and balance (case-insensitive: the engine uses both 'cancelled' and 'Cancelled').
        $agg = Order::where('tenant_id', $t)
            ->whereRaw("lower(coalesce(status,'')) not in ('cancelled','rejected','void')")
            ->selectRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') as p")
            ->selectRaw("upper(coalesce(nullif(sell_currency,''), ?)) as cur", [$native])
            ->selectRaw('count(*) as orders')
            ->selectRaw('coalesce(sum(total),0) as spend')
            ->selectRaw('coalesce(sum(greatest(total - amount_paid, 0)),0) as balance')
            ->selectRaw('max(created_at) as last_at')
            ->groupBy('p', 'cur')
            ->get()
            ->groupBy('p');

        $customers = CustomerProfile::where('tenant_id', $t)
            ->orderBy('name')
            ->limit(self::MAX_ROWS)
            ->get();

        $now = Carbon::now();
        $activeCount = 0;
        $outstanding = 0.0;
        $accountsOwing = 0;
        $totalSpend = 0.0;
        $totalOrders = 0;
        $rows = [];

        foreach ($customers as $c) {
            $phone = preg_replace('/[^0-9]/', '', (string) $c->phone);
            $curRows = ($phone !== '' && isset($agg[$phone])) ? $agg[$phone] : collect();

            $orders = (int) $curRows->sum('orders');
            // Consolidate each currency bucket into the report display currency, then sum.
            $spend = (float) $rc->consolidate($curRows->map(fn ($r) => ['amount' => (float) $r->spend, 'currency' => (string) $r->cur])->all())['amount'];
            $balance = max(0.0, (float) $rc->consolidate($curRows->map(fn ($r) => ['amount' => (float) $r->balance, 'currency' => (string) $r->cur])->all())['amount']);
            $lastTs = (int) $curRows->max(fn ($r) => $r->last_at ? Carbon::parse($r->last_at)->timestamp : 0);
            $lastAt = $lastTs > 0 ? Carbon::createFromTimestamp($lastTs) : null;

            $totalSpend += $spend;
            $totalOrders += $orders;
            if ($balance > 0) { $outstanding += $balance; $accountsOwing++; }

            $isActive = $lastAt !== null && $lastAt->diffInDays($now) <= self::ACTIVE_DAYS;
            if ($isActive) { $activeCount++; }
            $status = $orders === 0 ? 'New' : ($isActive ? 'Active' : 'Dormant');

            $location = trim(implode(', ', array_filter([(string) $c->city, (string) $c->country]))) ?: (string) ($c->address ?: '—');

            $rows[] = [
                'id'         => ['value' => (string) $c->id, 'sort' => (int) $c->id],
                'customer'   => ['value' => (string) ($c->name ?: 'Unnamed'), 'sub' => (string) ($c->contact_person ?: $c->phone ?: ''), 'avatar' => true],
                'location'   => $location,
                'segment'    => ['value' => (string) ($c->segment ?: '—'), 'badge' => $c->segment ? 'accent' : 'default'],
                'orders'     => $orders,
                'spend'      => ['value' => $rc->fmtConsolidated($spend), 'sort' => $spend],
                'balance'    => $balance > 0
                    ? ['value' => $rc->fmtConsolidated($balance), 'tone' => 'danger', 'sort' => $balance]
                    : ['value' => '—', 'sort' => 0],
                'status'     => ['value' => $status, 'badge' => $status === 'Active' ? 'ok' : ($status === 'Dormant' ? 'warn' : 'default')],
                'last_order' => ['value' => $lastAt ? $lastAt->diffForHumans(null, true) . ' ago' : 'never', 'sort' => $lastAt ? $lastAt->timestamp : 0],
                'country'    => (string) ($c->country ?: ''),
                'link'       => '/api/v1/workspace/customer/' . $c->id,
            ];
        }

        $total = $customers->count();
        $newThisMonth = CustomerProfile::where('tenant_id', $t)
            ->where('created_at', '>=', $now->copy()->startOfMonth())->count();
        $avgOrder = $totalOrders > 0 ? $totalSpend / $totalOrders : 0.0;

        $columns = [
            ['key' => 'id',         'label' => 'ID',         'type' => 'num', 'sortable' => true],
            ['key' => 'customer',   'label' => 'Customer',   'type' => 'avatar', 'sortable' => true],
            ['key' => 'location',   'label' => 'Location',   'sortable' => true],
            ['key' => 'segment',    'label' => 'Segment',    'type' => 'badge', 'sortable' => true],
            ['key' => 'orders',     'label' => 'Orders',     'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'spend',      'label' => 'Total spend', 'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'balance',    'label' => 'Balance',    'align' => 'right', 'type' => 'num', 'sortable' => true],
            ['key' => 'status',     'label' => 'Status',     'type' => 'badge', 'sortable' => true],
            ['key' => 'last_order', 'label' => 'Last order', 'align' => 'right', 'sortable' => true],
        ];
        $filters = [
            ['key' => 'status',  'label' => 'Status'],
            ['key' => 'segment', 'label' => 'Segment'],
            ['key' => 'country', 'label' => 'Country'],
        ];

        return (new WorkspacePayload)
            ->object([
                'type' => 'list:customers', 'title' => 'Customers',
                'subtitle' => number_format($total) . ' total · ' . number_format($activeCount) . ' active · ' . $rc->fmtConsolidated($outstanding) . ' outstanding',
            ])
            ->financials([
                ['key' => 'total', 'label' => 'Total customers', 'value' => $total, 'foot' => number_format($activeCount) . ' active'],
                ['key' => 'new', 'label' => 'New this month', 'value' => $newThisMonth, 'tone' => 'ok', 'foot' => 'this calendar month'],
                ['key' => 'outstanding', 'label' => 'Outstanding balance', 'value' => $rc->fmtConsolidated($outstanding), 'tone' => $outstanding > 0 ? 'warn' : 'ok', 'foot' => 'across ' . $accountsOwing . ' accounts'],
                ['key' => 'avg', 'label' => 'Avg. order value', 'value' => $rc->fmtConsolidated($avgOrder), 'foot' => number_format($totalOrders) . ' orders'],
            ])
            ->table($columns, $rows, $filters)
            ->actions([
                ['key' => 'add_customer', 'label' => 'New customer', 'method' => 'POST', 'endpoint' => '/api/v1/workspace/customers/_/add_customer', 'actor' => 'human', 'tone' => 'primary', 'schema' => self::formSchema()],
                ['key' => 'export', 'label' => 'Export', 'method' => 'GET', 'endpoint' => '/api/v1/workspace/customers', 'actor' => 'human', 'tone' => 'default', 'icon' => 'export'],
            ])
            ->meta(['capability' => 'customers', 'ttl' => 60, 'permissions' => ['menu:customers'], 'note' => 'Segment column omitted — not in the frozen schema; add a nullable customer_profiles.segment field to populate it.']);
    }

    /** Fields for the add/edit customer form. Phone is the identity key (required). */
    public static function formSchema(): array
    {
        return [
            'business_name'  => 'text',
            'contact_person' => 'text',
            'phone'          => 'text',
            'email'          => 'text',
            'segment'        => 'enum:,Wholesale,Retail,Distributor,Institutional,Walk-in',
            'city'           => 'text',
            'country'        => 'enum:,Uganda,South Sudan,Kenya,Tanzania,Rwanda',
            'credit_limit'   => 'money',
        ];
    }

    public function summary(CapabilityContext $ctx, array $scope = []): array
    {
        return ['customers' => CustomerProfile::where('tenant_id', $ctx->tenantId())->count()];
    }

    public function command(CapabilityContext $ctx, string $action, array $input): array
    {
        if ($action === 'search') {
            $q = trim((string) ($input['q'] ?? ''));
            $digits = preg_replace('/[^0-9]/', '', $q);
            $rows = CustomerProfile::where('tenant_id', $ctx->tenantId())
                ->when($q !== '', fn ($qq) => $qq->where(fn ($w) => $w
                    ->where('name', 'ilike', '%' . $q . '%')
                    ->orWhere('contact_person', 'ilike', '%' . $q . '%')
                    ->when($digits !== '', fn ($d) => $d->orWhere('phone', 'like', '%' . $digits . '%'))))
                ->orderBy('name')->limit(20)->get();
            $items = $rows->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => (string) ($c->name ?: $c->phone ?: 'Customer'),
                'phone' => (string) ($c->phone ?? ''),
                'sub' => trim(implode(' · ', array_filter([(string) $c->contact_person, (string) $c->phone, trim(implode(', ', array_filter([(string) $c->city, (string) $c->country])))]))),
            ])->all();

            return ['ok' => true, 'items' => $items];
        }

        if ($action === 'add_customer') {
            try {
                $c = app(CustomerWriteService::class)->create($ctx->tenant, $input);
            } catch (\InvalidArgumentException $e) {
                return ['ok' => false, 'error' => $e->getMessage(), 'message' => 'A phone number is required to add a customer.'];
            }

            return ['ok' => true, 'id' => (int) $c->id, 'message' => 'Customer "' . ($c->name ?: $c->phone) . '" added.'];
        }

        return ['ok' => false, 'error' => 'unknown_action'];
    }
}
