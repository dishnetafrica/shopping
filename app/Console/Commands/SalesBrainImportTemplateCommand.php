<?php

namespace App\Console\Commands;

use App\Models\SalesKnowledgeEntry;
use App\Models\Tenant;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Sales\Brain\TemplateTerms;
use App\Services\Sales\SalesOwnership;
use Illuminate\Console\Command;

/**
 * Load the terms from DishNet's UISP quote template into a business's Sales Brain as DRAFTS, each with
 * the questions management must settle first. It never approves or publishes anything, and it skips
 * any terms entry that already exists, so running it twice changes nothing.
 *
 *   php artisan sales-brain:import-template 31
 */
class SalesBrainImportTemplateCommand extends Command
{
    protected $signature = 'sales-brain:import-template {tenant : business (tenant) id}';

    protected $description = 'Import the UISP quote template terms into a business\'s Sales Brain as drafts (Internet-provider businesses only)';

    public function handle(SalesBrain $brain): int
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

        foreach (TemplateTerms::all() as $key => $t) {
            if (SalesKnowledgeEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('topic', 'terms')->where('key', $key)->exists()) {
                $this->line("  terms/{$key}: already there — left as it is");
                continue;
            }
            $r = $brain->startVersion($tenant, null, 'terms', $key, $t['title'], null, ['title' => $t['title'], 'items' => $t['items']],
                TemplateTerms::SOURCE, $t['questions'], 'Imported from the UISP quote template', 'console: sales-brain:import-template');
            $this->line($r['ok']
                ? "  terms/{$key}: draft v{$r['version']} with " . count($t['questions']) . ' question(s) for management'
                : "  terms/{$key}: not imported ({$r['error']})");
        }
        $this->info('Done. Nothing is published: a manager must settle the questions, approve and publish each one.');

        return self::SUCCESS;
    }
}
