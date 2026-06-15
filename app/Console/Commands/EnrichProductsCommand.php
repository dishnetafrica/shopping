<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * products:enrich
 * ---------------
 * Classifies products into the controlled product_type vocabulary.
 *
 * Default is a DRY RUN: it prints the plan (what it WOULD do) and writes nothing.
 * Pass --apply to persist:
 *   - apply  rows  -> product_type set, status 'approved'
 *   - review rows  -> suggested type stored, status 'needs_review' (surfaced to admin)
 *   - skip   rows  -> left untouched
 *
 *   php artisan products:enrich --tenant=3            # dry run for one tenant
 *   php artisan products:enrich --tenant=3 --limit=50 # cap how many are classified
 *   php artisan products:enrich --tenant=3 --apply    # write results
 *   php artisan products:enrich --only-missing        # skip already-enriched products
 */
class EnrichProductsCommand extends Command
{
    protected $signature = 'products:enrich
        {--tenant= : Tenant id (default: all tenants)}
        {--limit=0 : Max products to classify (0 = no limit)}
        {--only-missing : Only classify products with no product_type yet}
        {--apply : Persist the plan (default is dry-run)}';

    protected $description = 'Classify products into the controlled product_type vocabulary (dry-run by default).';

    public function handle(): int
    {
        $svc = ProductEnrichmentService::fromConfig();
        if (! $svc->isEnabled()) {
            $this->error('Enrichment is disabled — set OPENAI_API_KEY (and shopbot.enrichment.enabled).');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        // CLI has no "active tenant", so the BelongsToTenant global scope would hide every
        // product (tenant_id = null). Run as super-admin to bypass it; --tenant still scopes
        // via the explicit where below.
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
                'category' => (string) ($p->category ?? ''), 'product_type' => (string) ($p->product_type ?? ''),
            ])->all();

        if (! $products) {
            $this->info('No products to enrich.');
            return self::SUCCESS;
        }

        $this->info(sprintf('%s %d product(s)…', $apply ? 'Enriching' : '[DRY RUN] Classifying', count($products)));
        $plan = $svc->plan($products);

        $this->table(
            ['id', 'name', 'current', '→ type', 'conf', 'decision'],
            array_map(fn ($r) => [
                $r['id'], mb_strimwidth($r['name'], 0, 34, '…'), $r['current'] ?: '—',
                $r['product_type'] ?? '—', $r['confidence'] !== null ? number_format($r['confidence'], 2) : '—',
                strtoupper($r['decision']),
            ], $plan['rows'])
        );

        $s = $plan['summary'];
        $this->line(sprintf(
            'apply=%d  review=%d  skip=%d  unclassified=%d',
            $s['apply'] ?? 0, $s['review'] ?? 0, $s['skip'] ?? 0, $s['unclassified'] ?? 0
        ));

        if (! $apply) {
            $this->comment('Dry run — nothing written. Re-run with --apply to persist.');
            return self::SUCCESS;
        }

        $applied = $queued = 0;
        foreach ($plan['rows'] as $r) {
            if (! in_array($r['decision'], ['apply', 'review'], true) || ! $r['id']) continue;
            $product = Product::find($r['id']);
            if (! $product) continue;

            if ($r['decision'] === 'apply') {
                $product->product_type = $r['product_type'];
                $product->product_type_confidence = $r['confidence'];
                $product->product_type_status = 'approved';
                $applied++;
            } else { // review
                $product->product_type_confidence = $r['confidence'];
                $product->product_type_status = 'needs_review';
                // suggested type is kept in product_type only if none is set yet, so an approved
                // value is never silently overwritten by a low-confidence suggestion.
                if (($product->product_type ?? '') === '') $product->product_type = $r['product_type'];
                $queued++;
            }
            $product->save();
        }

        $this->info("Applied {$applied}, queued for review {$queued}.");
        return self::SUCCESS;
    }
}
