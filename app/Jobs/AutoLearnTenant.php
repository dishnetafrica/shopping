<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Bot\Company\CompanyDiscoveryService;
use App\Services\Bot\Discovery\DiscoveryScanner;
use App\Services\Bot\Readiness\ReadinessService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Quietly (re)learn one tenant from its FULL message history — the most data a single pass can use
 * (up to 20,000 messages, no date window). Runs Discovery -> Go-Live readiness -> team consensus and
 * persists everything as PENDING. Unlike RunBusinessDiscovery it does NOT WhatsApp the owner, so it
 * is safe to run automatically on a schedule across every tenant.
 */
class AutoLearnTenant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Full-history cap. Tenants with fewer messages get all of them. */
    public const CAP = 20000;

    public function __construct(public int $tenantId) {}

    public function handle(TenantContext $ctx, DiscoveryScanner $scanner, ReadinessService $readiness, CompanyDiscoveryService $company): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) return;
        $ctx->set($tenant->id);

        try {
            $discovery = $scanner->scan($tenant, self::CAP, null);   // null window = full history
            try { $company->discover($tenant, true); } catch (\Throwable $e) {}
            try { $readiness->evaluate($tenant); } catch (\Throwable $e) {}
            Log::info("AutoLearnTenant: tenant {$tenant->id} scan #{$discovery->id} from {$discovery->sample_messages} messages, readiness {$discovery->readiness}%.");
        } catch (\Throwable $e) {
            Log::error("AutoLearnTenant failed for tenant {$tenant->id}: " . $e->getMessage());
        }
    }
}
