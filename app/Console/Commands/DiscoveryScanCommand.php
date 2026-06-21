<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Bot\Discovery\DiscoveryDispatcher;
use App\Services\Bot\Discovery\DiscoveryScanner;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Run a Business Discovery scan on demand.
 *   php artisan discovery:scan --tenant=2            scan + persist (pending)
 *   php artisan discovery:scan --tenant=2 --send     also WhatsApp the report to the owner
 *   php artisan discovery:scan --tenant=2 --export=/path/chat.txt --owner="Pal"
 */
class DiscoveryScanCommand extends Command
{
    protected $signature = 'discovery:scan {--tenant= : tenant id} {--send : send the report to the owner} {--export= : path to a WhatsApp .txt export} {--owner= : owner name(s) in the export, comma-separated}';
    protected $description = 'Analyze WhatsApp + order history and build a Business DNA report (review-gated).';

    public function handle(TenantContext $ctx, DiscoveryScanner $scanner, DiscoveryDispatcher $dispatcher): int
    {
        $id = (int) $this->option('tenant');
        $tenant = Tenant::find($id);
        if (! $tenant) { $this->error("Tenant {$id} not found."); return self::FAILURE; }

        $ctx->set($tenant->id);

        if ($path = $this->option('export')) {
            if (! is_file($path)) { $this->error("Export not found: {$path}"); return self::FAILURE; }
            $owners = array_filter(array_map('trim', explode(',', (string) $this->option('owner'))));
            $discovery = $scanner->scanExport($tenant, (string) file_get_contents($path), $owners);
        } else {
            $discovery = $scanner->scan($tenant);
        }

        $this->info("Scan #{$discovery->id} — readiness {$discovery->readiness}% ({$discovery->report['readiness_band']})");
        $this->line("Messages: {$discovery->sample_messages}  Orders: {$discovery->sample_orders}  Status: {$discovery->status}");

        if ($this->option('send')) {
            $ok = $dispatcher->send($tenant, $discovery);
            $this->line($ok ? 'Report sent to owner.' : 'Not sent (no owner_alert_phone or send failed).');
        }

        $this->line('Review and approve in the panel — nothing is active yet.');
        return self::SUCCESS;
    }
}
