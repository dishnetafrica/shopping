<?php

namespace App\BOS\Projections;

use App\Models\CustomerProfile;
use App\Models\Invoice;

/**
 * Activity projection (canonical type: Activity) — the collections work for a tenant: one activity per
 * customer who owes money ("Collect USD X — <name>"), ranked by value × urgency. Pure read; derived
 * from live invoices + events. Powers Today (Slice 3) and the Collections capability later.
 */
class CollectionsActivities extends ActivityProjection
{
    public function __construct(private int $tenantId) {}

    /** @return array<int,array> ranked collect activities */
    public function build(): array
    {
        $today = now()->toDateString();
        $rows = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereIn('status', ['issued', 'part_paid'])
            ->where('balance', '>', 0.5)
            ->get(['customer_name', 'customer_phone', 'balance', 'due_at']);

        $byCust = [];
        foreach ($rows as $r) {
            $key = (string) ($r->customer_phone ?: $r->customer_name);
            if ($key === '') continue;
            $byCust[$key] ??= ['name' => (string) $r->customer_name, 'phone' => (string) $r->customer_phone, 'amount' => 0.0, 'count' => 0, 'overdue' => false];
            $byCust[$key]['amount'] += (float) $r->balance;
            $byCust[$key]['count']++;
            if ($r->due_at && (string) $r->due_at < $today) $byCust[$key]['overdue'] = true;
        }

        // Resolve customer ids in one pass (for the timeline deep-link).
        $phones = array_values(array_filter(array_map(fn ($c) => preg_replace('/\D/', '', (string) $c['phone']), $byCust)));
        $ids = $phones ? CustomerProfile::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->whereIn('phone', $phones)->pluck('id', 'phone')->all() : [];

        $acts = [];
        foreach ($byCust as $c) {
            $amount = round($c['amount'], 2);
            $digits = preg_replace('/\D/', '', (string) $c['phone']);
            $acts[] = [
                'type'        => 'collect',
                'customer_id' => (int) ($ids[$digits] ?? 0),
                'title'       => 'Collect USD ' . number_format($amount, 2) . ' — ' . $c['name'],
                'sub'         => $c['count'] . ' invoice' . ($c['count'] > 1 ? 's' : '') . ($c['overdue'] ? ' · overdue' : ''),
                'amount'      => $amount,
                'overdue'     => $c['overdue'],
                'priority'    => $amount * ($c['overdue'] ? 2 : 1),
            ];
        }
        usort($acts, fn ($a, $b) => $b['priority'] <=> $a['priority']);
        return $acts;
    }
}
