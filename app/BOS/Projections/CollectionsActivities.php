<?php

namespace App\BOS\Projections;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * Activity projection (canonical type: Activity) — the collections work for a tenant: one activity per
 * customer who owes money, ranked by value × urgency. Enriched for the modern Today card: status
 * (overdue / due / upcoming), the primary invoice, a human due-label, and city. Pure read; derived from
 * live invoices. Powers Today and the Collections capability.
 */
class CollectionsActivities extends ActivityProjection
{
    public function __construct(private int $tenantId) {}

    /** @return array<int,array> ranked collect activities */
    public function build(): array
    {
        $today = now();
        $rows = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereIn('status', ['issued', 'part_paid'])
            ->where('balance', '>', 0.5)
            ->get(['invoice_no', 'customer_name', 'customer_phone', 'balance', 'due_at']);

        $byCust = [];
        foreach ($rows as $r) {
            $key = (string) ($r->customer_phone ?: $r->customer_name);
            if ($key === '') continue;
            if (! isset($byCust[$key])) {
                $byCust[$key] = ['name' => (string) $r->customer_name, 'phone' => (string) $r->customer_phone,
                    'amount' => 0.0, 'count' => 0, 'due' => null, 'inv' => (string) $r->invoice_no];
            }
            $byCust[$key]['amount'] += (float) $r->balance;
            $byCust[$key]['count']++;
            // The card shows the MOST-overdue (earliest due) invoice as the primary one.
            $due = $r->due_at ? (string) $r->due_at : null;
            if ($due !== null && ($byCust[$key]['due'] === null || $due < $byCust[$key]['due'])) {
                $byCust[$key]['due'] = $due;
                $byCust[$key]['inv'] = (string) $r->invoice_no;
            }
        }

        // Resolve customer id + city in one pass (for the deep-link + the card context).
        $phones = array_values(array_filter(array_map(fn ($c) => preg_replace('/\D/', '', (string) $c['phone']), $byCust)));
        $profiles = $phones ? CustomerProfile::withoutGlobalScopes()->where('tenant_id', $this->tenantId)
            ->whereIn('phone', $phones)->get(['id', 'phone', 'city'])->keyBy('phone') : collect();

        $acts = [];
        foreach ($byCust as $c) {
            $amount = round($c['amount'], 2);
            $digits = preg_replace('/\D/', '', (string) $c['phone']);
            $prof = $profiles->get($digits);
            [$status, $dueLabel] = $this->dueMeta($c['due'], $today);
            $name = $c['name'] !== '' ? $c['name'] : 'Customer';
            $acts[] = [
                'type'        => 'collect',
                'customer_id' => (int) ($prof->id ?? 0),
                'name'        => $name,
                'city'        => (string) ($prof->city ?? ''),
                'invoice_no'  => (string) $c['inv'],
                'count'       => (int) $c['count'],
                'amount'      => $amount,
                'status'      => $status,        // overdue | due | ok
                'overdue'     => $status === 'overdue',
                'due_label'   => $dueLabel,      // "3 days overdue" | "due today" | "due in 5 days" | ""
                // legacy fields (older render / other consumers)
                'title'       => 'Collect USD ' . number_format($amount, 2) . ' — ' . $name,
                'sub'         => $c['count'] . ' invoice' . ($c['count'] > 1 ? 's' : '') . ($status === 'overdue' ? ' · overdue' : ''),
                'priority'    => $amount * ($status === 'overdue' ? 3 : ($status === 'due' ? 2 : 1)),
            ];
        }
        usort($acts, fn ($a, $b) => $b['priority'] <=> $a['priority']);
        return $acts;
    }

    /** @return array{0:string,1:string} [status, human label] from a Y-m-d due date vs today. */
    private function dueMeta(?string $due, Carbon $today): array
    {
        if (! $due) return ['ok', ''];
        try { $d = Carbon::parse($due)->startOfDay(); }
        catch (\Throwable $e) { return ['ok', '']; }
        $days = $today->copy()->startOfDay()->diffInDays($d, false);   // negative = overdue
        if ($days < 0)   return ['overdue', abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' overdue'];
        if ($days === 0) return ['due', 'due today'];
        return ['ok', 'due in ' . $days . ' day' . ($days === 1 ? '' : 's')];
    }
}
