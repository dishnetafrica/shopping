<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductSuggestion;
use App\Models\Tenant;
use App\Services\Research\ProductCopywriter;
use App\Services\Research\ProductResearch;
use App\Services\Research\Providers\AiKnowledgeProvider;
use App\Services\Research\Providers\BarcodeProvider;
use App\Support\BotTrace;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Fill in what the catalogue does not know.
 *
 * DRY RUN BY DEFAULT — like products:enrich, nothing is written without --apply. Run it
 * once to read the table, then again with --apply when the sample looks right.
 *
 *   php artisan products:research --tenant=17                       # see what it would do
 *   php artisan products:research --tenant=17 --apply               # AI only, no web calls
 *   php artisan products:research --tenant=17 --barcode-api --apply # also look barcodes up
 *   php artisan products:research --tenant=17 --descriptions --apply
 *
 * On --barcode-api: Open Food Facts ask that one API call corresponds to one real scan,
 * so this is off by default, throttled when on, and capped per run. It is reasonable for
 * a one-time pass over goods you actually stock; it is not a way to harvest their
 * database. For anything larger, use their published data export.
 */
class ProductsResearchCommand extends Command
{
    protected $signature = 'products:research
        {--tenant= : tenant id (required — research is per shop)}
        {--limit=0 : stop after N products (0 = all)}
        {--only-missing : skip products that already have every field filled}
        {--barcode-api : look barcodes up on Open Food Facts (throttled, capped)}
        {--descriptions : also write a description where there is none}
        {--apply : actually write; without this nothing is saved}';

    protected $description = 'Research missing product details (brand, size, origin, category, description)';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        if ($tenantId <= 0) {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }
        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("No tenant {$tenantId}.");

            return self::FAILURE;
        }
        if (! ProductResearch::enabled()) {
            $this->error('Research is disabled install-wide (config research.enabled).');

            return self::FAILURE;
        }

        // Without this the BelongsToTenant global scope hides every product on the CLI.
        app(TenantContext::class)->asSuperAdmin(true);

        $apply = (bool) $this->option('apply');
        $useBarcode = (bool) $this->option('barcode-api');
        $wantDesc = (bool) $this->option('descriptions');
        $limit = max(0, (int) $this->option('limit'));

        $providers = [];
        if ($useBarcode) {
            $bp = app(BarcodeProvider::class);
            if ($bp->isEnabled()) {
                $providers[] = $bp;
            }
        }
        $ai = app(AiKnowledgeProvider::class);
        if ($ai->isEnabled()) {
            $providers[] = $ai;
        }
        if (! $providers) {
            $this->error('No research provider is usable — is OPENAI_API_KEY set?');

            return self::FAILURE;
        }
        $research = new ProductResearch($providers);
        $writer = $wantDesc ? app(ProductCopywriter::class) : null;

        $q = Product::where('tenant_id', $tenantId)->whereNull('archived_at')->orderBy('id');
        $total = (clone $q)->count();
        $this->line("Tenant {$tenantId} · {$total} products · "
            . ($apply ? '<fg=yellow>APPLYING</>' : 'dry run')
            . ($useBarcode ? ' · barcode API ON' : '') . ($wantDesc ? ' · descriptions ON' : ''));

        $counts = ['apply' => 0, 'review' => 0, 'keep' => 0, 'skip' => 0];
        $seen = 0; $touched = 0; $queued = 0; $barcodeCalls = 0; $samples = [];
        $capOff = (int) config('research.barcode.bulk_cap', 250);
        $delay = max(0, (int) config('research.barcode.bulk_delay', 1100)) * 1000;

        $bar = $this->output->createProgressBar($limit > 0 ? min($limit, $total) : $total);
        $bar->start();

        foreach ($q->cursor() as $p) {
            if ($limit > 0 && $seen >= $limit) {
                break;
            }
            if (ProductResearch::capReached($tenantId)) {
                $bar->clear();
                $this->warn("\nDaily research cap reached for this tenant — stopping.");
                $bar->display();
                break;
            }

            $arr = self::toArray($p);
            $missing = ProductResearch::missing($arr);
            if ($this->option('only-missing') && ! $missing && ! ($wantDesc && trim((string) $p->description) === '')) {
                $seen++; $bar->advance();
                continue;
            }

            $ean = BarcodeProvider::normalise((string) ($p->barcode ?? ''));
            $throttle = $useBarcode && $ean !== '' && $barcodeCalls < $capOff;
            if ($useBarcode && $ean !== '' && $barcodeCalls >= $capOff) {
                $arr['barcode'] = '';                       // over the per-run cap — do not call out again
            }

            $findings = $research->gather($arr);
            ProductResearch::countCall($tenantId);
            if ($throttle) {
                $barcodeCalls++;
                if ($delay > 0) {
                    usleep($delay);
                }
            }

            $verified = false;
            foreach ($findings as $f) {
                if ($f->source === 'barcode') { $verified = true; break; }
            }

            if ($writer && trim((string) ($p->description ?? '')) === '') {
                $facts = $arr;
                foreach ($findings as $f) {                 // write from the researched facts too
                    if (in_array($f->field, ['brand', 'unit_size', 'origin_country', 'category'], true)
                        && trim((string) ($facts[$f->field] ?? '')) === '' && $f->confidence >= 0.55) {
                        $facts[$f->field] = $f->value;
                    }
                }
                $facts['shop'] = (string) $p->getRelationValue('tenant')?->name ?: '';
                $d = $writer->write($facts, $verified);
                ProductResearch::countCall($tenantId);
                if ($d) {
                    $findings[] = ProductResearch::validate($d);
                }
            }

            $plan = ProductResearch::plan($arr, $findings);
            foreach ($plan as $row) {
                $counts[$row['decision']] = ($counts[$row['decision']] ?? 0) + 1;
            }

            $writes = ProductResearch::applied($plan);
            if ($writes) {
                $touched++;
                if (count($samples) < 8) {
                    $samples[] = [mb_substr((string) $p->name, 0, 38), implode(', ', array_keys($writes)),
                        mb_substr((string) reset($writes), 0, 34)];
                }
                if ($apply) {
                    foreach ($writes as $col => $val) {
                        $p->{$col} = $val;
                    }
                    $p->save();
                }
            }

            foreach ($plan as $row) {
                if ($row['decision'] !== 'review') {
                    continue;
                }
                $queued++;
                if ($apply) {
                    self::queue($tenantId, (int) $p->id, $row);
                }
            }

            $seen++; $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        if ($samples) {
            $this->table(['product', 'fields filled', 'first value'], $samples);
        }
        $this->line(sprintf(
            'Looked at %d · filled %d products · queued %d for review · barcode lookups %d',
            $seen, $touched, $queued, $barcodeCalls
        ));
        $this->line(sprintf(
            'Decisions — apply %d · review %d · kept (already filled, untouched) %d · skipped %d',
            $counts['apply'], $counts['review'], $counts['keep'], $counts['skip']
        ));
        if (! $apply) {
            $this->warn('Dry run — nothing was written. Re-run with --apply.');
        } else {
            BotTrace::log($tenantId, 'products:research', 'cli', 'research_run',
                "seen={$seen} filled={$touched} queued={$queued} off={$barcodeCalls}", 0);
            $this->info('Done. Review queue: Products → Suggestions in the panel.');
        }

        return self::SUCCESS;
    }

    /** @return array<string,string> */
    public static function toArray(Product $p): array
    {
        return [
            'name'           => (string) ($p->name ?? ''),
            'barcode'        => (string) ($p->barcode ?? ''),
            'brand'          => (string) ($p->brand ?? ''),
            'category'       => (string) ($p->category ?? ''),
            'unit_size'      => (string) ($p->unit_size ?? ''),
            'origin_country' => (string) ($p->origin_country ?? ''),
            'description'    => (string) ($p->description ?? ''),
            'unit_label'     => (string) ($p->unit_label ?? ''),
            'pack_size'      => (string) ($p->pack_size ?? ''),
            'moq'            => (string) ($p->moq ?? ''),
        ];
    }

    /** One pending row per (product, field) — a re-run refreshes rather than duplicates. */
    private static function queue(int $tenantId, int $productId, array $row): void
    {
        $f = $row['finding'];
        ProductSuggestion::updateOrCreate(
            ['product_id' => $productId, 'field' => $f->field, 'status' => 'pending'],
            [
                'tenant_id'       => $tenantId,
                'current_value'   => $row['current'] !== '' ? $row['current'] : null,
                'suggested_value' => $f->value,
                'confidence'      => round($f->confidence, 3),
                'source'          => $f->source,
                'note'            => mb_substr($f->note, 0, 255) ?: null,
            ],
        );
    }
}
