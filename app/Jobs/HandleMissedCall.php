<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Isp\MissedCall;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** A customer tried to call the WhatsApp line: tell them how to reach us and alert their salesperson (MissedCall). */
class HandleMissedCall implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;                                  // never a second message to the caller on retry

    public function __construct(public int $tenantId, public array $call) {}

    public function handle(TenantContext $ctx, MissedCall $missed): void
    {
        $ctx->set($this->tenantId);
        $t = Tenant::find($this->tenantId);
        if ($t) {
            $missed->handle($t, $this->call);
        }
    }
}
