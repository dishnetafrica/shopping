<?php

namespace App\Console\Commands;

use App\Models\FollowupProposal;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * relationship:review — V4.3 learning-lite, monthly. A REPORT, never a rewrite:
 * per-trigger performance, top skip reasons (the team's own words), escalations by
 * reason, memory completeness. Humans read it and adjust settings/cadences —
 * the system NEVER modifies its own prompts. Ever.
 */
class RelationshipReviewCommand extends Command
{
    protected $signature   = 'relationship:review {--tenant=0} {--days=30}';
    protected $description = 'V4.3 monthly relationship report: what worked, what got skipped, why we escalated';

    public function handle(): int
    {
        $days = max(7, (int) $this->option('days'));
        $tenants = Tenant::query()
            ->when((int) $this->option('tenant') > 0, fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->get();

        foreach ($tenants as $t) {
            app(\App\Support\TenantContext::class)->set((int) $t->id); // scope this tenant's models
            if (! (bool) $t->setting('relationship_memory', false)) continue;
            $this->line('');
            $this->info("═══ {$t->name} — relationship review, last {$days} days ═══");
            $since = now()->subDays($days);

            // per-trigger performance
            $rows = FollowupProposal::where('tenant_id', $t->id)->where('created_at', '>', $since)
                ->get()->groupBy('trigger');
            $this->line('— The virtual salesperson —');
            foreach ($rows as $trigger => $set) {
                $sent = $set->where('status', 'sent');
                $conv = $sent->whereNotNull('influenced_order_id');
                $rev  = Order::withoutGlobalScopes()->whereIn('id', $conv->pluck('influenced_order_id'))->sum('total');
                $pct  = $sent->count() ? round(100 * $conv->count() / $sent->count()) : 0;
                $this->line(sprintf('  %-14s proposed %-3d sent %-3d converted %-3d (%d%%)  influenced revenue %s',
                    $trigger, $set->count(), $sent->count(), $conv->count(), $pct, number_format((float) $rev, 0)));
                if ($sent->count() >= 10 && $pct >= 90) {
                    $this->info("     ↑ {$trigger} has EARNED auto-send consideration (≥90% over ≥10 sends)");
                }
            }

            // the team's own words
            $skips = FollowupProposal::where('tenant_id', $t->id)->where('status', 'skipped')
                ->where('created_at', '>', $since)->whereNotNull('skip_reason')
                ->select('skip_reason', DB::raw('COUNT(*) n'))->groupBy('skip_reason')
                ->orderByDesc('n')->limit(8)->get();
            if ($skips->count()) {
                $this->line('— Why the team skipped (their words — adjust the triggers accordingly) —');
                foreach ($skips as $s) $this->line("  {$s->n}× \"{$s->skip_reason}\"");
            }

            // escalations by reason
            $esc = DB::table('bot_traces')->where('tenant_id', $t->id)->where('trace', 'escalation')
                ->where('created_at', '>', $since)
                ->select('stage', DB::raw('COUNT(*) n'))->groupBy('stage')->orderByDesc('n')->get();
            if ($esc->count()) {
                $this->line('— Escalations by reason —');
                foreach ($esc as $e) $this->line("  {$e->n}× ".str_replace('_', ' ', (string) $e->stage));
            }

            // memory completeness
            $profiles = \App\Models\CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id);
            $total = (clone $profiles)->count();
            $withFacts = (clone $profiles)->whereNotNull('facts')->count();
            // what the shop LEARNED (nightly study sessions)
            $lex = (array) $t->setting('shop_lexicon', []);
            $log = (array) $t->setting('learning_log', []);
            if ($lex || $log) {
                $this->line('— What the shop learned (nightly study) —');
                $this->line('  vocabulary glossary: '.count($lex).' term(s)'.($lex ? ' · newest: "'
                    .(end($lex)['term'] ?? '').'" = '.(end($lex)['means'] ?? '') : ''));
                $gaps = collect($log)->pluck('gaps')->flatten()->countBy()->sortDesc()->take(5);
                foreach ($gaps as $g => $n) $this->line("  {$n}× gap: {$g}");
                $demand = collect($log)->pluck('demand')->flatten()->countBy()->sortDesc()->take(5);
                foreach ($demand as $d => $n) $this->line("  {$n}× asked-but-not-stocked: {$d}  ← purchasing signal");
                $this->comment('  Vocabulary is data the bot now uses. Gaps and demand are for YOUR decisions.');
            }

            $this->line('— Memory —');
            $this->line('  '.$withFacts.' of '.$total.' profiles carry learned facts ('
                .($total ? round(100 * $withFacts / $total) : 0).'%)');
        }
        $this->line('');
        $this->comment('This report informs HUMAN decisions. Nothing here rewrites a prompt.');

        return self::SUCCESS;
    }
}
