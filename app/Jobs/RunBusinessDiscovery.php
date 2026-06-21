<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Bot\Discovery\DiscoveryDispatcher;
use App\Services\Bot\Discovery\DiscoveryScanner;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Run a Business Discovery scan ~10-15 min after onboarding, then WhatsApp the report to the owner.
 * Dispatch with a delay, e.g. RunBusinessDiscovery::dispatch($tenant->id)->delay(now()->addMinutes(12)).
 * The scan persists as PENDING — nothing activates without the owner's approval in the panel.
 */
class RunBusinessDiscovery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $tenantId) {}

    public function handle(TenantContext $ctx, DiscoveryScanner $scanner, DiscoveryDispatcher $dispatcher): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) return;

        $ctx->set($tenant->id);

        try {
            $discovery = $scanner->scan($tenant);
            $dispatcher->send($tenant, $discovery);
            Log::info("Business Discovery: tenant {$tenant->id} scan #{$discovery->id} readiness {$discovery->readiness}%.");
        } catch (\Throwable $e) {
            Log::error("Business Discovery failed for tenant {$tenant->id}: " . $e->getMessage());
        }
    }
}
