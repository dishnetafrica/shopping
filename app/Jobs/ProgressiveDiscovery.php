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
 * Phase-2 progressive learning. Onboarding does a fast 30-day scan (Phase 1); afterwards this job
 * widens the window in the background — 90 → 180 → 365 days — re-running discovery + readiness each
 * time and chaining to the next window with a delay. It never blocks onboarding and each pass is
 * just a richer PENDING report; nothing activates without owner approval.
 *
 * Kick off with: ProgressiveDiscovery::dispatch($tenantId)->delay(now()->addMinutes(20));
 */
class ProgressiveDiscovery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Widening windows, in days. */
    private const WINDOWS = [90, 180, 365];

    public function __construct(public int $tenantId, public int $step = 0) {}

    public function handle(TenantContext $ctx, DiscoveryScanner $scanner, ReadinessService $readiness, CompanyDiscoveryService $company): void
    {
        if (! isset(self::WINDOWS[$this->step])) return;
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) return;

        $ctx->set($tenant->id);
        $window = self::WINDOWS[$this->step];

        try {
            $discovery = $scanner->scan($tenant, 20000, $window);
            try { $company->discover($tenant, true); } catch (\Throwable $e) {}
            try { $readiness->evaluate($tenant); } catch (\Throwable $e) {}
            Log::info("ProgressiveDiscovery: tenant {$tenant->id} window {$window}d scan #{$discovery->id} readiness {$discovery->readiness}%.");
        } catch (\Throwable $e) {
            Log::error("ProgressiveDiscovery failed for tenant {$tenant->id} window {$window}d: " . $e->getMessage());
        }

        // Chain to the next, larger window after a cooldown so we never hammer the box.
        if (isset(self::WINDOWS[$this->step + 1])) {
            self::dispatch($this->tenantId, $this->step + 1)->delay(now()->addMinutes(30));
        }
    }
}
