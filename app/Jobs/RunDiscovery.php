<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Discovery\DiscoveryService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * v4.59 — a discovery run, off the request.
 *
 * A CSV of two thousand rows, or an API that takes its time, must not hold a browser open. The
 * results go nowhere yet — Milestone 1 stops at the log — so this job's job is to prove the pipeline
 * runs somewhere other than a controller.
 */
class RunDiscovery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;                   // a discovery run is not idempotent; a retry would double-log

    public function __construct(
        public int $tenantId,
        public array $query,
        public ?int $userId = null,
        public ?array $only = null,
    ) {}

    public function handle(DiscoveryService $svc): void
    {
        $t = Tenant::find($this->tenantId);
        if (! $t) {
            return;
        }
        // Console and queue workers have no tenant in context, and every scoped query would silently
        // return nothing — the trap that made stamp:check report an empty shop full of payments.
        app(TenantContext::class)->set((int) $t->id);

        $svc->run($t, $this->query, $this->userId, $this->only);
    }
}
