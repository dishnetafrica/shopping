<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Bot\Company\CompanyDiscoveryService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Multi-employee discovery: learn the company (consensus across staff), not one employee.
 *   php artisan company:discover --tenant=2
 *   php artisan company:discover --tenant=2 --no-save
 */
class CompanyDiscoverCommand extends Command
{
    protected $signature = 'company:discover {--tenant=} {--no-save}';
    protected $description = 'Build Company DNA by running discovery per employee and taking consensus.';

    public function handle(TenantContext $ctx, CompanyDiscoveryService $svc): int
    {
        $tenant = Tenant::find((int) $this->option('tenant'));
        if (! $tenant) { $this->error('Tenant not found.'); return self::FAILURE; }
        $ctx->set($tenant->id);

        $c = $svc->discover($tenant, ! $this->option('no-save'));
        $rep = $c['report'];

        $this->info("Company DNA — {$c['employee_count']} employee(s): " . implode(', ', $c['employees']));
        $this->newLine();

        $this->line('Common company rules:');
        foreach ($rep['common_company_rules'] as $r) $this->line('  • ' . $r);

        $this->newLine();
        $this->line('Employee variations:');
        foreach ($rep['employee_variations'] as $v) $this->line("  • {$v['employee']}: {$v['notes']}");

        if ($rep['conflicting_information']) {
            $this->newLine();
            $this->line('Conflicting information:');
            foreach ($rep['conflicting_information'] as $cf) {
                $this->line("  • {$cf['fact']}: " . implode(' vs ', $cf['values']) . " → using {$cf['resolved']}");
            }
        }

        $cl = $rep['confidence_levels'];
        $this->newLine();
        $this->line("Confidence — products {$cl['products']}%  faqs {$cl['faqs']}%  delivery {$cl['delivery']}%  offers {$cl['offers']}%  overall {$cl['overall']}%");
        $this->newLine();
        $this->line($this->option('no-save') ? 'Not saved (--no-save).' : 'Company & Employee memory saved.');

        return self::SUCCESS;
    }
}
