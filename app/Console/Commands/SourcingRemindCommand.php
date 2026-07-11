<?php

namespace App\Console\Commands;

use App\Http\Controllers\Panel\SourcingController;
use App\Models\SourcingRequest;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Keep nudging the buyer (Jayesh) on WhatsApp for still-unpriced sourcing requests until he
 * replies the Juba price. Nudges every few hours during working hours, capped so it can never
 * spam. First contact happens instantly on request creation; this is the follow-up chaser.
 *
 * Scheduled hourly (routes/console.php). A request stops being nudged once it is priced/closed
 * or once reminder_count hits the cap.
 */
class SourcingRemindCommand extends Command
{
    protected $signature = 'sourcing:remind
        {--tenant= : limit to one tenant id}
        {--gap=3 : hours between nudges}
        {--dry : list who would be nudged without sending}';

    protected $description = 'Nudge the buyer for unpriced sourcing requests until they reply the price.';

    public function handle(): int
    {
        $only = (string) $this->option('tenant');
        $gap  = max(1, (int) $this->option('gap'));
        $dry  = (bool) $this->option('dry');
        $sent = 0;

        $q = Tenant::query()->whereNotNull('whatsapp_instance')->orderBy('id');
        if ($only !== '') $q->where('id', (int) $only);

        foreach ($q->get() as $t) {
            $phone = preg_replace('/[^0-9]/', '', (string) $t->setting('sourcing_buyer_phone', ''));
            if ($phone === '') continue;

            $reqs = SourcingRequest::where('tenant_id', $t->id)
                ->where('status', 'new')
                ->whereNotNull('notified_at')                       // first contact already made
                ->where('reminder_count', '<', SourcingController::REMINDER_CAP)
                ->where(function ($w) use ($gap) {
                    $w->whereNull('last_reminded_at')
                      ->orWhere('last_reminded_at', '<=', now()->subHours($gap));
                })
                ->orderBy('id')->limit(100)->get();

            foreach ($reqs as $r) {
                if ($dry) { $this->line("would nudge t{$t->id} SR-{$r->id} ({$r->item_name}) x" . ($r->reminder_count + 1)); continue; }
                if (SourcingController::sendToBuyer($t, $r, true)) $sent++;
            }
        }

        $this->info(($dry ? '[dry] ' : '') . "sourcing reminders sent: {$sent}");
        return self::SUCCESS;
    }
}
