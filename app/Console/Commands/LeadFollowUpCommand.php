<?php
namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\BotTrace;
use App\Support\MessageLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Warm-lead re-engagement. A customer asks something ("minimum order quantity?"), the bot replies,
 * then they go quiet. After a per-tenant window we send ONE catalogue follow-up so the lead doesn't
 * go cold. Pull, not push — and tightly governed (the reason-coded outbound rule, ADR-0002):
 *   - opt-in per tenant: settings.reengage_followup.enabled (default OFF)
 *   - ONCE per conversation (state.catalogue_followup_at)
 *   - never while a human has taken over, mid-cart, or do-not-contact
 *   - only warm leads by default (an open Lead exists); set require_lead=false to relax
 *   - office-hours gated by the schedule; an outer window so stale chats are never re-engaged
 *   - never sends an empty follow-up — needs a catalogue link and/or catalog_files
 *
 * Per-tenant settings under `reengage_followup`:
 *   enabled(false) · delay_min(10) · max_min(180) · message(...) · link('') · require_lead(true)
 */
class LeadFollowUpCommand extends Command
{
    protected $signature = 'bot:lead-followup';
    protected $description = 'Send ONE catalogue follow-up to a warm lead who asked then went quiet';

    private const DEFAULT_MSG = "Hi \u{1F44B} Just following up on your enquiry. Here's our catalogue in case it helps — and I'd be happy to prepare a quote whenever you're ready.";

    public function handle(WhatsAppManager $wa): int
    {
        $sent = 0;

        Conversation::withoutGlobalScopes()
            ->whereNotNull('last_inbound_at')
            ->where('agent_active', false)
            ->where('last_inbound_at', '<=', now()->subMinutes(3))    // coarse pre-filter
            ->where('last_inbound_at', '>=', now()->subHours(24))     // coarse outer bound
            ->orderBy('last_inbound_at')
            ->limit(400)->get()
            ->each(function (Conversation $c) use ($wa, &$sent) {
                try {
                    $tenant = Tenant::find($c->tenant_id);
                    if (! $tenant) return;

                    $cfg = (array) $tenant->setting('reengage_followup', []);
                    if (! ($cfg['enabled'] ?? false)) return;                        // opt-in only

                    $state = (array) $c->state;
                    if (! empty($state['catalogue_followup_at'])) return;            // once only
                    if (! empty($state['do_not_contact'])) return;                   // do-not-contact
                    if ((int) ($state['human_until'] ?? 0) > time()) return;         // human handling
                    if (! empty($c->cart)) return;                                   // mid-cart — don't interrupt

                    $delay = max(1, (int) ($cfg['delay_min'] ?? 10));
                    $maxm  = max($delay + 1, (int) ($cfg['max_min'] ?? 180));
                    $inAt  = $c->last_inbound_at;
                    if (! $inAt) return;
                    if ($inAt->gt(now()->subMinutes($delay))) return;               // not quiet long enough yet
                    if ($inAt->lt(now()->subMinutes($maxm))) return;                // too old to re-engage
                    if (! $c->last_message_at || $c->last_message_at->lte($inAt)) return; // we must have replied first

                    $phone = preg_replace('/[^0-9+]/', '', (string) $c->customer_phone);
                    if ($phone === '') return;

                    if (($cfg['require_lead'] ?? true)) {
                        $warm = Lead::withoutGlobalScopes()
                            ->where('tenant_id', $tenant->id)
                            ->where('customer_phone', $phone)
                            ->whereIn('status', Lead::OPEN_STATUSES)
                            ->exists();
                        if (! $warm) return;
                    }

                    // Catalogue payload: configured link and/or the tenant's catalog_files.
                    $link  = trim((string) ($cfg['link'] ?? ''));
                    $files = collect((array) $tenant->setting('catalog_files', []))
                        ->map(fn ($m) => ['label' => (string) ($m['label'] ?? 'Catalogue'), 'url' => (string) ($m['url'] ?? '')])
                        ->filter(fn ($m) => $m['url'] !== '')->values()->all();
                    if ($link === '' && ! $files) return;   // nothing to send → never send an empty follow-up

                    $msg = trim((string) ($cfg['message'] ?? self::DEFAULT_MSG));
                    if ($link !== '') $msg .= "\n" . $link;

                    $g = $wa->forTenant($tenant);
                    $g->sendText($tenant->whatsapp_instance, $phone, $msg);
                    MessageLog::record($tenant->id, $phone, $tenant->whatsapp_instance, 'out', 'bot', $msg, null, null,
                        ['via' => 'reengage', 'reason' => 'CATALOGUE_FOLLOWUP']);
                    foreach (array_slice($files, 0, 2) as $f) {
                        $g->sendDocument($tenant->whatsapp_instance, $phone, $f['url'], ($f['label'] ?: 'Catalogue') . '.pdf', $f['label']);
                    }

                    $state['catalogue_followup_at'] = now()->toIso8601String();
                    $c->state = $state;
                    $c->save();
                    BotTrace::log($tenant->id, 'reengage', $phone, 'catalogue_followup', 'sent after ' . $delay . 'm quiet');
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('bot:lead-followup failed for conversation ' . $c->id . ': ' . $e->getMessage());
                }
            });

        $this->info("bot:lead-followup — sent {$sent}.");
        return self::SUCCESS;
    }
}
