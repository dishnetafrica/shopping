<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Chat\ChatTranslator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * products:arabic-keywords — Tier 2 of storefront Arabic support: enrich each
 * product's `keywords` with Arabic search terms so searching زيت finds the oils.
 * The storefront search already matches the keywords field — data is all it needs.
 * Idempotent: products whose keywords already contain Arabic script are skipped.
 */
class ArabicKeywordsCommand extends Command
{
    protected $signature   = 'products:arabic-keywords {tenant : Tenant id} {--dry : Preview candidates only} {--limit=0 : Max products this run (0 = all)}';
    protected $description = 'AI-append Arabic search keywords to products (storefront Arabic search)';

    public function handle(ChatTranslator $tr): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t) { $this->error('tenant not found'); return self::FAILURE; }
        if (! $tr->enabled()) { $this->error('OPENAI_API_KEY not configured'); return self::FAILURE; }

        $candidates = Product::withoutGlobalScopes()
            ->where('tenant_id', $t->id)->where('active', true)
            ->get(['id', 'name', 'category', 'keywords'])
            ->filter(fn ($p) => ! ChatTranslator::hasArabic((string) $p->keywords));

        $this->info($candidates->count().' active products have no Arabic keywords yet.');
        if ($this->option('dry')) {
            foreach ($candidates->take(15) as $p) $this->line('  #'.$p->id.'  '.$p->name);
            if ($candidates->count() > 15) $this->line('  … and '.($candidates->count() - 15).' more');
            $this->info('dry run — nothing written. Run without --dry (use --limit for batches).');
            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $done = 0; $failed = 0;
        foreach ($candidates as $p) {
            if ($limit > 0 && $done >= $limit) break;
            $ar = $tr->arabicKeywords((string) $p->name, (string) ($p->category ?? ''));
            if ($ar === null) { $failed++; continue; }
            $p->keywords = trim((string) $p->keywords) !== '' ? trim($p->keywords).', '.$ar : $ar;
            $p->save();
            $done++;
            $this->line(sprintf('  ✓ #%-6d %-44s → %s', $p->id, mb_substr($p->name, 0, 44), mb_substr($ar, 0, 50)));
        }

        Cache::forget('catalogue:'.$t->id);
        Cache::forget('ai_calc_catalogue:'.$t->id);
        $this->info("Done: {$done} enriched · {$failed} failed (rerun later — idempotent) · caches cleared.");
        $this->info('Test it: storefront search for زيت / سكر / أرز.');

        return self::SUCCESS;
    }
}
