<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tender;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * v4.20 TenderBot drop 2 — deadline escalation (spec: 7 days = HIGH PRIORITY, 48h = CRITICAL,
 * plus a final 24-hour submission check). Runs hourly; each stage fires exactly once per
 * tender (stage marks live inside the report JSON), only while the tender is still open.
 */
class TendersRemindCommand extends Command
{
    protected $signature = 'tenders:remind';

    protected $description = 'WhatsApp deadline escalations for open tenders (7d / 48h / 24h)';

    private const CLOSED = ['submitted', 'won', 'lost', 'ignored', 'cancelled', 'failed'];

    public function handle(TenantContext $ctx): int
    {
        // PLATFORM operation, step 1 — the cross-tenant index read. This scheduled command processes
        // every tenant, so the initial sweep is an explicit, authorized cross-tenant read via
        // withoutGlobalScopes() (there is no single tenant yet to scope to). It groups by tenant_id
        // and resolves each Tenant below.
        $tenders = Tender::withoutGlobalScopes()->whereNotIn('status', self::CLOSED)
            ->whereNotNull('deadline')
            ->whereDate('deadline', '>=', now()->toDateString())
            ->orderBy('deadline')->limit(300)->get();

        $byTenant = [];
        foreach ($tenders as $td) {
            $byTenant[$td->tenant_id][] = $td;
        }
        // PLATFORM operation, step 2 — process each tenant WITH its context established, so any
        // tenant-scoped read in remind() (or a helper it grows) is correctly scoped, and no tenant's
        // id leaks into the next group (finally). remind()'s own Tender::save() is a keyed update and
        // scope-free regardless, but establishing context here is the rule for a platform loop.
        foreach ($byTenant as $tenantId => $list) {
            $tenant = Tenant::find($tenantId);
            if (! $tenant || ! (bool) $tenant->setting('tender_notify', true)) {
                continue;
            }
            $ctx->set((int) $tenantId);
            try {
                foreach ($list as $td) {
                    try {
                        $this->remind($tenant, $td);
                    } catch (\Throwable $e) {
                        \Log::warning('tenders:remind failed for #' . $td->id . ': ' . $e->getMessage());
                    }
                }
            } finally {
                $ctx->clear();
            }
        }

        return self::SUCCESS;
    }

    private function remind(Tenant $tenant, Tender $td): void
    {
        $days = now()->startOfDay()->diffInDays($td->deadline, false);
        if ($days < 0 || $days > 7) {
            return;
        }
        $rep = (array) ($td->report ?? []);
        $sent = (array) ($rep['reminders'] ?? []);
        $label = $this->clip((string) ($td->title ?: $td->organization ?: ('Tender #' . $td->id)), 80);
        $head = null;
        $mark = null;

        if ($days <= 1 && empty($sent['h24'])) {
            $mark = ['h24' => true, 'h48' => true, 'd7' => true];
            $missing = array_slice(array_values(array_filter(array_map(
                fn ($m) => is_array($m) ? trim((string) ($m['document'] ?? '')) : trim((string) $m),
                (array) ($rep['missing_documents'] ?? [])
            ))), 0, 6);
            $sub = (array) ($rep['submission'] ?? []);
            $how = trim(implode(' · ', array_filter([(string) ($sub['method'] ?? ''), (string) ($sub['email'] ?? ''), (string) ($sub['address'] ?? '')])));
            $head = "🔴 FINAL 24 HOURS — {$label}\n"
                . "Deadline: " . $td->deadline->format('D d M') . "\n"
                . ($how !== '' ? "Submit via: {$how}\n" : '')
                . ($missing ? "⚠ Still missing:\n• " . implode("\n• ", $missing) . "\n" : '')
                . "Final check: signed forms, bid security, all mandatory documents, sealed/addressed as instructed. Submit TODAY — do not wait for the last hour.";
        } elseif ($days <= 2 && empty($sent['h48'])) {
            $mark = ['h48' => true, 'd7' => true];
            $head = "🚨 CRITICAL — 48 HOURS LEFT: {$label}\n"
                . "Deadline: " . $td->deadline->format('D d M') . " · status: " . $td->status . "\n"
                . "All open tasks for this tender are now top priority. Confirm quotations are in and documents are ready.";
        } elseif (empty($sent['d7'])) {
            $mark = ['d7' => true];
            $head = "⏳ {$days} days to deadline: {$label}\n"
                . "Deadline: " . $td->deadline->format('D d M') . " · status: " . $td->status . "\n"
                . "This tender is now HIGH PRIORITY — check its tasks and supplier quotations today.";
        }

        if ($head === null) {
            return;
        }
        try {
            \App\Jobs\NotifyOwner::dispatch($tenant->id, $head);
        } catch (\Throwable $e) {
            return;   // don't mark stages we failed to queue
        }
        $rep['reminders'] = array_merge($sent, $mark);
        $td->report = $rep;
        $td->save();
    }

    private function clip(string $s, int $max): string
    {
        $s = trim($s);

        return mb_strlen($s, 'UTF-8') > $max ? rtrim(mb_substr($s, 0, $max - 1, 'UTF-8')) . '…' : $s;
    }
}
