<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * products:enrich
 * ---------------
 * Classifies products into the controlled product_type vocabulary, in BATCHES (many products
 * per API call) so a large catalogue costs a few hundred calls, not tens of thousands.
 *
 * Default is a DRY RUN: prints a summary (+ a sample of rows) and writes nothing.
 * Pass --apply to persist: apply-rows -> product_type + status 'approved'; review-rows ->
 * status 'needs_review'; skip/unclassified -> untouched.
 *
 *   php artisan products:enrich --tenant=1 --limit=30            # try 30 first, eyeball quality
 *   php artisan products:enrich --tenant=1 --only-missing        # full dry-run (only un-typed)
 *   php artisan products:enrich --tenant=1 --only-missing --apply # write it
 *   php artisan products:enrich --tenant=1 --batch=25            # smaller batches if a model truncates
 */
class EnrichProductsCommand extends Command
{
    protected $signature = 'products:enrich
        {--tenant= : Tenant id (default: all tenants)}
        {--limit=0 : Max products to classify (0 = no limit)}
        {--batch=40 : Products per API call}
        {--only-missing : Only classify products with no product_type yet}
        {--apply : Persist the plan (default is dry-run)}';

    protected $description = 'Classify products into the controlled product_type vocabulary (batched, dry-run by default).';

    public function handle(): int
    {
        $svc = ProductEnrichmentService::fromConfig();
        if (! $svc->isEnabled()) {
            $this->error('Enrichment is disabled — set OPENAI_API_KEY in the environment.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $batch = max(1, (int) $this->option('batch'));

        // CLI has no "active tenant", so the BelongsToTenant global scope would hide every product.
        // Run as super-admin to bypass it; --tenant still scopes via the explicit where below.
        app(TenantContext::class)->asSuperAdmin(true);

        $q = Product::query();
        if ($t = $this->option('tenant')) $q->where('tenant_id', $t);
        if ($this->option('only-missing')) {
            $q->where(fn ($w) => $w->whereNull('product_type')->orWhere('product_type', ''));
        }
        if ($limit > 0) $q->limit($limit);

        $products = $q->get(['id', 'name', 'category', 'product_type'])
            ->map(fn ($p) => [
                'id' => $p->id, 'name' => (string) $p->name,
                'category' => (string) ($p->category ?? ''), 'current' => (string) ($p->product_type ?? ''),
            ])->all();

        $total = count($products);
        if (! $total) {
            $this->info('No products to enrich.');
            return self::SUCCESS;
        }

        $this->info(sprintf('%s %d product(s) in batches of %d…', $apply ? 'Enriching' : '[DRY RUN] Classifying', $total, $batch));

        $rows = [];
        $summary = ['apply' => 0, 'review' => 0, 'skip' => 0, 'unclassified' => 0];
        $bar = $this->output->createProgressBar((int) ceil($total / $batch));
        $bar->start();

        foreach (array_chunk($products, $batch) as $chunk) {
            $items = array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'category' => $p['category']], $chunk);
            $results = $svc->classifyMany($items);   // [id => validated|null]

            foreach ($chunk as $p) {
                $v = $results[$p['id']] ?? null;
                if ($v === null) {
                    $summary['unclassified']++;
                    $rows[] = ['id' => $p['id'], 'name' => $p['name'], 'current' => $p['current'], 'type' => null, 'conf' => null, 'decision' => 'unclassified'];
                    continue;
                }
                $d = ProductEnrichmentService::decision($v);
                $summary[$d] = ($summary[$d] ?? 0) + 1;
                $rows[] = ['id' => $p['id'], 'name' => $p['name'], 'current' => $p['current'], 'type' => $v['product_type'], 'conf' => $v['confidence'], 'decision' => $d];
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        // Show a sample (full table only makes sense for small runs).
        $sample = array_slice(array_filter($rows, fn ($r) => $r['decision'] !== 'unclassified'), 0, 40);
        if (! $sample) $sample = array_slice($rows, 0, 40);
        $this->table(
            ['id', 'name', 'current', '→ type', 'conf', 'decision'],
            array_map(fn ($r) => [
                $r['id'], mb_strimwidth($r['name'], 0, 34, '…'), $r['current'] ?: '—',
                $r['type'] ?? '—', $r['conf'] !== null ? number_format($r['conf'], 2) : '—', strtoupper($r['decision']),
            ], $sample)
        );
        if (count($rows) > count($sample)) $this->line('… showing ' . count($sample) . ' of ' . count($rows) . ' rows.');

        $s = $summary;
        $this->line(sprintf('apply=%d  review=%d  skip=%d  unclassified=%d', $s['apply'], $s['review'], $s['skip'], $s['unclassified']));

        if (! $apply) {
            $this->comment('Dry run — nothing written. Re-run with --apply to persist.');
            return self::SUCCESS;
        }

        $applied = $queued = 0;
        foreach ($rows as $r) {
            if (! in_array($r['decision'], ['apply', 'review'], true)) continue;
            $product = Product::find($r['id']);
            if (! $product) continue;
            if ($r['decision'] === 'apply') {
                $product->product_type = $r['type'];
                $product->product_type_confidence = $r['conf'];
                $product->product_type_status = 'approved';
                $applied++;
            } else {
                $product->product_type_confidence = $r['conf'];
                $product->product_type_status = 'needs_review';
                if (($product->product_type ?? '') === '') $product->product_type = $r['type'];
                $queued++;
            }
            $product->save();
        }
        $this->info("Applied {$applied}, queued for review {$queued}.");
        return self::SUCCESS;
    }
}
