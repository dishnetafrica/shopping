<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Trust\EntityTrust;
use App\Services\Trust\TrustRuntime;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Inventory Trust — resolve an investigation (Spec §7.2 / §10 label factory).
 *
 * Every resolved item is a training label: it updates the acting employee's reputation
 * (clean ↑ / confirmed_loss ↓) and accumulates the dataset that later replaces the hand-set
 * SignalCatalog weights with fitted ones. Outcomes: clean | error | confirmed_loss | unresolved.
 *
 *   php artisan trust:resolve --tenant=17 12 confirmed_loss --value=180000 --notes="short delivery, supplier X"
 *   php artisan trust:resolve --tenant=17            ← lists open investigations
 */
final class TrustResolveCommand extends Command
{
    protected $signature = 'trust:resolve
        {investigation? : trust_investigations id}
        {outcome? : clean|error|confirmed_loss|unresolved}
        {--tenant= : tenant id (required)}
        {--value= : confirmed loss value (optional)}
        {--notes= : short note}
        {--by=owner : who resolved it}';

    protected $description = 'Record the outcome of a trust investigation (feeds reputations + the ML label set).';

    public function handle(TenantContext $ctx): int
    {
        $id = (int) $this->option('tenant');
        if (! $id) {
            $this->warn('Pass --tenant=<id>. Your businesses:');
            foreach (Tenant::orderBy('id')->get() as $tt) {
                $this->line('  --tenant=' . $tt->id . '   ' . $tt->name);
            }
            return self::FAILURE;
        }
        $tenant = Tenant::findOrFail($id);
        $ctx->set($id);

        $invId = $this->argument('investigation');
        if (! $invId) {
            $open = DB::table('trust_investigations')
                ->join('stock_movements', 'stock_movements.id', '=', 'trust_investigations.stock_movement_id')
                ->where('trust_investigations.tenant_id', $id)->whereNull('outcome')
                ->orderByDesc('trust_investigations.expected_loss')
                ->select('trust_investigations.id', 'trust_investigations.source',
                         'trust_investigations.expected_loss', 'stock_movements.type',
                         'stock_movements.sku', 'stock_movements.location', 'stock_movements.qty',
                         'stock_movements.created_by', 'stock_movements.created_at')
                ->get();
            if ($open->isEmpty()) {
                $this->info('No open investigations. 🎉');
                return self::SUCCESS;
            }
            $this->table(['id', 'src', 'E[loss]', 'type', 'sku', 'loc', 'qty', 'actor', 'when'],
                $open->map(fn ($r) => [(string) $r->id, $r->source, number_format((float) $r->expected_loss),
                    $r->type, $r->sku ?: '—', $r->location, (string) $r->qty,
                    $r->created_by ?: '—', substr((string) $r->created_at, 0, 16)])->all());
            return self::SUCCESS;
        }

        $outcome = strtolower((string) $this->argument('outcome'));
        if (! in_array($outcome, ['clean', 'error', 'confirmed_loss', 'unresolved'], true)) {
            $this->error('Outcome must be clean|error|confirmed_loss|unresolved.');
            return self::FAILURE;
        }

        $inv = DB::table('trust_investigations')
            ->where('tenant_id', $id)->where('id', (int) $invId)->first();
        if (! $inv) {
            $this->error('No such investigation for this tenant.');
            return self::FAILURE;
        }

        DB::table('trust_investigations')->where('id', $inv->id)->update([
            'outcome' => $outcome,
            'outcome_value' => $this->option('value') !== null ? (float) $this->option('value') : null,
            'resolved_by' => (string) $this->option('by'),
            'resolved_at' => now(),
            'notes' => (string) ($this->option('notes') ?? ''),
            'updated_at' => now(),
        ]);

        // Reputation feedback: a HUMAN-CONFIRMED outcome outweighs the automatic proxies.
        $m = DB::table('stock_movements')->where('id', $inv->stock_movement_id)->first();
        if ($m && $m->created_by) {
            $rt = TrustRuntime::forTenant($tenant);
            $stat = DB::table('trust_entity_stats')->where([
                'tenant_id' => $id, 'entity_type' => 'actor',
                'entity_key' => $m->created_by, 'txn_type' => '*'])->first();
            $s = $stat ? ['alpha' => (float) $stat->alpha, 'beta' => (float) $stat->beta]
                       : EntityTrust::coldStart();
            if ($outcome === 'clean') {
                $s = EntityTrust::update($s['alpha'], $s['beta'], true);
            } elseif ($outcome === 'confirmed_loss') {
                $val = (float) ($this->option('value') ?? 0);
                $sev = $val > 0 ? min(1.0, $val / max($rt->medianValue() * 4, 0.01)) : 0.6;
                $s = EntityTrust::update($s['alpha'], $s['beta'], false, $sev);
            } // 'error' is process, not person — no reputation change
            DB::table('trust_entity_stats')->updateOrInsert(
                ['tenant_id' => $id, 'entity_type' => 'actor',
                 'entity_key' => $m->created_by, 'txn_type' => '*'],
                ['alpha' => $s['alpha'], 'beta' => $s['beta'],
                 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->info("Investigation {$inv->id} → {$outcome}. Label recorded.");
        return self::SUCCESS;
    }
}
