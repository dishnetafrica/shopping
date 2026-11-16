<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\SalesKnowledgeEntry;
use App\Models\Tenant;
use App\Services\Catalog\ProductWriteService;
use App\Services\Sales\Brain\DishNetBotImport;
use App\Services\Sales\Brain\Knowledge;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Sales\SalesOwnership;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load DishNet's current sales information (from its n8n sales bot) into a business FOR REVIEW:
 *   - priced items → catalogue products created SWITCHED OFF (nothing quotes or shows them);
 *   - everything else → Sales Brain drafts carrying management's open questions.
 * Never switches a product on, never approves or publishes. Skips any product name or knowledge entry
 * that already exists, so running it twice changes nothing. Internet-provider businesses only.
 *
 *   php artisan sales-brain:import-dishnet-bot 31
 */
class SalesBrainImportDishNetBotCommand extends Command
{
    protected $signature = 'sales-brain:import-dishnet-bot {tenant : business (tenant) id}';

    protected $description = 'Import DishNet\'s n8n sales-bot content: products switched off, knowledge as drafts (Internet-provider businesses only)';

    public function handle(ProductWriteService $products, SalesBrain $brain): int
    {
        $tenant = Tenant::find((int) $this->argument('tenant'));
        if (! $tenant) {
            $this->error('No such business.');

            return self::FAILURE;
        }
        if (! SalesOwnership::enabled($tenant)) {
            $this->error("{$tenant->name} is not set up as an Internet provider; nothing imported.");

            return self::FAILURE;
        }
        if (! SalesBrain::ready()) {
            $this->error('The Sales Brain tables are missing — run the migrations first.');

            return self::FAILURE;
        }

        $this->info('Products (created switched off — a manager switches each one on after checking it):');
        foreach (DishNetBotImport::products() as $p) {
            $exists = Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($p['name'])])->exists();
            if ($exists) {
                $this->line("  {$p['name']}: already in the catalogue — left as it is");
                continue;
            }
            DB::transaction(fn () => $products->create($tenant, [
                'name' => $p['name'], 'price' => $p['price'], 'category' => $p['category'], 'description' => $p['description'],
                'billing' => $p['billing'], 'service_line' => $p['service_line'], 'stock' => 0, 'active' => false,
            ]));
            $this->line("  {$p['name']}: added, switched off ({$p['billing']}, USD {$p['price']})");
        }

        $this->info('Sales Brain (drafts — nothing is published):');
        foreach (DishNetBotImport::knowledge() as [$topic, $key, $title, $content, $questions]) {
            if (SalesKnowledgeEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('topic', $topic)->where('key', Knowledge::key($key))->exists()) {
                $this->line("  {$topic}/{$key}: already there — left as it is");
                continue;
            }
            $r = $brain->startVersion($tenant, null, $topic, $key, $title, is_string($content) ? $content : null, is_array($content) ? $content : null,
                DishNetBotImport::SOURCE, $questions, 'Imported from the n8n sales bot', 'console: sales-brain:import-dishnet-bot');
            $this->line($r['ok']
                ? "  {$topic}/{$key}: draft v{$r['version']}" . ($questions ? ' with ' . count($questions) . ' question(s) for management' : '')
                : "  {$topic}/{$key}: not imported ({$r['error']})");
        }
        $this->info('Done. Review in Customers → Sales Brain and Products. Hardware needs its real stock entered before the bot can quote it.');

        return self::SUCCESS;
    }
}
