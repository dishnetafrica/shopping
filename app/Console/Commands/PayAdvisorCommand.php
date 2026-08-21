<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Finance\PayAdvisor;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Supplier Pay Advisor reminder (daily). ADVISORY ONLY — it never pays anything.
 *
 * For each tenant that has WhatsApp linked, the `pay_advisor_enabled` setting ON, and a
 * `pay_advisor_notify_phone` set, it WhatsApps ONE digest of suppliers whose balance is overdue
 * (older than the sooner of the flat threshold or the supplier's own term). A supplier is reminded
 * when it NEWLY becomes overdue, then at most once a week while it stays overdue (state kept in the
 * `pay_advisor_state` setting) — so it never spams.
 *
 * PREVIEW-FIRST: with --dry, or when the tenant is not enabled, or when no recipient number is set,
 * it prints the exact digest it WOULD send and sends nothing. Flip `pay_advisor_enabled` on and set
 * `pay_advisor_notify_phone` only once the preview looks right.
 */
class PayAdvisorCommand extends Command
{
    protected $signature = 'suppliers:pay-advisor
        {--tenant= : limit to one tenant id or slug}
        {--dry : print the digest that would be sent, and send nothing}';

    protected $description = 'WhatsApp the owner a digest of suppliers due for payment (advisory only).';

    private const WEEKLY = 7 * 86400;

    public function handle(TenantContext $ctx, WhatsAppManager $wa, PayAdvisor $advisor): int
    {
        $only = (string) $this->option('tenant');
        $dry = (bool) $this->option('dry');
        $tenantsChecked = 0; $sent = 0;

        $q = Tenant::query()->whereNotNull('whatsapp_instance')->orderBy('id');
        if ($only !== '') {
            $q->where(fn ($w) => $w->where('id', ctype_digit($only) ? (int) $only : 0)->orWhere('slug', $only));
        }

        $now = Carbon::now();

        foreach ($q->get() as $t) {
            $enabled = (bool) $t->setting('pay_advisor_enabled', false);
            $recipient = trim((string) $t->setting('pay_advisor_notify_phone', ''));
            $flatDays = (int) $t->setting('pay_advisor_flat_days', PayAdvisor::FLAT_DAYS);
            $bufferPct = ((float) $t->setting('pay_advisor_buffer_pct', 50)) / 100.0;
            // Preview whenever we can't (or shouldn't yet) actually send.
            $preview = $dry || ! $enabled || $recipient === '';

            $ctx->set($t->id);
            $tenantsChecked++;

            $advisories = $advisor->forTenant($t, $now, $flatDays ?: PayAdvisor::FLAT_DAYS, $bufferPct ?: PayAdvisor::BUFFER_PCT);
            $actionable = array_values(array_filter($advisories, fn ($a) => in_array($a['state'], ['pay', 'tension'], true) && $a['overdue'] > 0.005));

            // once + weekly: only remind suppliers not reminded within the last 7 days.
            $state = (array) $t->setting('pay_advisor_state', []);
            $due = [];
            foreach ($actionable as $a) {
                $sid = (string) $a['supplier_id'];
                $last = isset($state[$sid]) ? Carbon::parse($state[$sid])->timestamp : 0;
                if ($now->timestamp - $last >= self::WEEKLY) { $due[] = $a; }
            }

            // Preview shows everything currently actionable; a live send respects once+weekly.
            $forMsg = $preview ? $actionable : $due;
            $text = $advisor->digestText($t, $forMsg);

            if ($preview) {
                $reason = $dry ? 'dry-run' : (! $enabled ? 'not enabled' : 'no recipient number');
                $this->line("── {$t->name} (#{$t->id}) — PREVIEW ({$reason}) ──");
                $this->line($text !== '' ? $text : '(nothing overdue)');
                $this->line('');
                continue;
            }

            if ($text === '') { continue; } // enabled + recipient, but nothing new to remind this run

            try {
                $wa->forTenant($t)->sendText($t->whatsapp_instance, $recipient, $text);
                MessageLog::record($t->id, $recipient, $t->whatsapp_instance, 'out', 'system', $text);
                $sent++;
                // Mark the reminded suppliers, and drop any that are no longer actionable.
                $activeIds = array_map(fn ($a) => (string) $a['supplier_id'], $actionable);
                $newState = [];
                foreach ($state as $sid => $ts) {
                    if (in_array((string) $sid, $activeIds, true)) { $newState[(string) $sid] = $ts; }
                }
                foreach ($due as $a) { $newState[(string) $a['supplier_id']] = $now->toIso8601String(); }
                $t->putSetting('pay_advisor_state', $newState);
            } catch (\Throwable $e) {
                Log::warning('suppliers:pay-advisor send_failed', ['tenant' => $t->id, 'err' => $e->getMessage()]);
            }
        }

        $this->info("suppliers:pay-advisor — {$tenantsChecked} tenant(s) checked, {$sent} digest(s) sent" . ($dry ? ' (dry run)' : '') . '.');
        return self::SUCCESS;
    }
}
