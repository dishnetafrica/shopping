<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Relationship\LexiconLearner;
use Illuminate\Console\Command;

/**
 * relationship:learn — the 02:30 study session. Reads the day's chats once,
 * grows the vocabulary glossary (data, never instructions), logs gaps + demand
 * for humans. Rides the relationship_memory flag.
 */
class RelationshipLearnCommand extends Command
{
    protected $signature   = 'relationship:learn {--tenant=0} {--days=1} {--show : Print the current lexicon} {--forget= : Remove a learned term}';
    protected $description = 'Nightly shop-level learning: customer vocabulary + gap/demand log';

    public function handle(LexiconLearner $learner): int
    {
        $tenants = Tenant::query()
            ->when((int) $this->option('tenant') > 0, fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->get();

        foreach ($tenants as $t) {
            if (! (bool) $t->setting('relationship_memory', false)) continue;

            if (($f = trim((string) $this->option('forget'))) !== '') {
                $lex = collect((array) $t->setting('shop_lexicon', []))
                    ->reject(fn ($e) => mb_strtolower((string) ($e['term'] ?? '')) === mb_strtolower($f))->values()->all();
                $t->putSetting('shop_lexicon', $lex);
                $this->info("{$t->name}: forgot \"{$f}\" (".count($lex).' terms remain)');
                continue;
            }
            if ($this->option('show')) {
                foreach ((array) $t->setting('shop_lexicon', []) as $e) {
                    $this->line(sprintf('  %-24s → %-40s (%s · %s)', $e['term'], $e['means'],
                        $e['evidence'] ?? '', $e['learned_at'] ?? ''));
                }
                continue;
            }

            $r = $learner->learn($t, max(1, (int) $this->option('days')));
            $this->info("{$t->name}: +{$r['terms']} vocabulary term(s) · {$r['gaps']} gap(s) and {$r['demand']} demand item(s) logged for the review");
        }

        return self::SUCCESS;
    }
}
