<?php

namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Trial + subscription lifecycle. Feature ENFORCEMENT is already automatic (Tenant::effectivePlan
 * returns 'pro' during the trial and drops to 'free' the moment it lapses or paid_until passes), so
 * this command does not gate anything — it only NOTIFIES: nudge before the trial ends, tell them the
 * day it ends, and dun a lapsed paid plan. It also sets a `billing_notice` on the tenant so the
 * panel can show a matching banner. Runs once daily; each notice fires at most once (stored markers).
 */
class BillingLifecycleCommand extends Command
{
    protected $signature = 'billing:lifecycle';
    protected $description = 'Trial-ending reminders, trial-expiry notices, and lapsed-payment dunning.';

    public function handle(): int
    {
        $now  = now();
        $sent = 0;

        foreach (Tenant::all() as $t) {
            if ($this->processTenant($t, $now)) $sent++;
        }

        $this->info("billing:lifecycle sent {$sent} notices.");
        return self::SUCCESS;
    }

    private function processTenant(Tenant $t, Carbon $now): bool
    {
        // 1) On trial — remind at 5 days and 1 day left (each once).
        if ($t->onTrial()) {
            $left = $t->trialDaysLeft();
            foreach ([5, 1] as $mark) {
                if ($left <= $mark && ! $this->already($t, "trial_remind_{$mark}")) {
                    $this->notice(
                        $t,
                        "trial_remind_{$mark}",
                        $left <= 1
                            ? "⏳ Your CloudBSS free trial ends tomorrow. Subscribe from your panel → Billing to keep your AI assistant, quotations and POS running."
                            : "⏳ Your CloudBSS free trial ends in {$left} days. Subscribe any time from your panel → Billing to keep all features.",
                        "Trial ends in {$left} day" . ($left === 1 ? '' : 's') . " — subscribe to keep Pro features."
                    );
                    return true;
                }
            }
            return false;
        }

        // 2) Trial just ended and they never paid → one "you're on Free now" notice.
        $lapsedTrial = $t->trial_ends_at && $t->trial_ends_at->isPast()
            && ! in_array($t->plan, ['starter', 'pro'], true);
        if ($lapsedTrial && ! $this->already($t, 'trial_ended')) {
            $this->notice(
                $t,
                'trial_ended',
                "Your CloudBSS trial has ended — your shop is now on the Free plan. Subscribe from your panel → Billing to restore your AI assistant, quotations and delivery tools.",
                "Trial ended — you're on Free. Subscribe to restore Pro features."
            );
            return true;
        }

        // 3) Paid plan whose paid_until has lapsed → dunning (re-notify at most weekly).
        $paidLapsed = in_array($t->plan, ['starter', 'pro'], true)
            && $t->paid_until && $t->paid_until->isPast();
        if ($paidLapsed) {
            $last = $t->setting('dun_last');
            if (! $last || Carbon::parse($last)->diffInDays($now) >= 7) {
                $t->putSetting('dun_last', $now->toIso8601String());
                $this->notice(
                    $t,
                    null,
                    "Your CloudBSS subscription has expired and premium features are paused. Renew from your panel → Billing to switch everything back on.",
                    "Subscription expired — renew to restore features."
                );
                return true;
            }
            return false;
        }

        // Healthy paid or active trial with nothing due — clear any stale banner.
        if ($t->setting('billing_notice')) $t->putSetting('billing_notice', null);
        return false;
    }

    /** Fire a notice: WhatsApp the owner (their number is up in this path) + set the panel banner. */
    private function notice(Tenant $t, ?string $marker, string $whatsapp, string $banner): void
    {
        $t->putSetting('billing_notice', $banner);
        if ($marker) $this->mark($t, $marker);

        try {
            NotifyOwner::dispatch($t->id, $whatsapp);
        } catch (\Throwable $e) {
            Log::warning('billing.notice_failed', ['tenant' => $t->id, 'err' => $e->getMessage()]);
        }
    }

    private function already(Tenant $t, string $marker): bool
    {
        return (bool) $t->setting("lifecycle.$marker");
    }

    private function mark(Tenant $t, string $marker): void
    {
        $t->putSetting("lifecycle.$marker", now()->toIso8601String());
    }
}
