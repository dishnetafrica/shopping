<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\FollowupProposal;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\BotTrace;
use App\Support\MessageLog;
use Illuminate\Console\Command;

/**
 * relationship:autosend — full autonomy, WITH RAILS (2026-07-19, owner's call).
 *
 * Runs after the 07:30 plan. Sends approved-quality proposals by itself, but every
 * rail below exists because unattended volume on an unofficial WhatsApp line is the
 * ban vector, and a nudge into a live conversation is the trust vector:
 *
 *   1. Flag-gated: followups_auto_send (default OFF).
 *   2. Business hours only (watchdog_hours, default 7-21 local).
 *   3. Confidence gate: only 'high' auto-sends by default
 *      (followups_auto_min_confidence: high|medium|any). The rest WAIT in the inbox.
 *   4. Human-active skip: agent_active chat = a person is working it — never interfere.
 *   5. Fresh-conversation skip: customer wrote in the last 12h — they're already
 *      engaged; crossing messages looks robotic (followups_auto_quiet_hours: 12).
 *   6. Daily cap (followups_auto_daily_cap, default 10) — pacing beats banning.
 *   7. Staggered sends: 45–120s apart via delayed queue jobs — human rhythm.
 *   8. Full transparency: every decision BotTraced, and the owner gets ONE summary
 *      ("🤖 Auto-sent 6 · held 4: 2 low-confidence, 1 human-active, 1 over cap").
 *
 * Attribution already tracks these (sent status + timestamps), and the monthly review
 * will show auto vs manual performance — the data to tighten or loosen the rails.
 */
class RelationshipAutosendCommand extends Command
{
    protected $signature   = 'relationship:autosend {--tenant=0} {--dry : Decide everything, send nothing}';
    protected $description = 'Auto-send the AI plan (flag-gated, capped, paced, confidence-gated)';

    public function handle(WhatsAppManager $wa): int
    {
        $tenants = Tenant::query()
            ->when((int) $this->option('tenant') > 0, fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->get();

        foreach ($tenants as $t) {
            app(\App\Support\TenantContext::class)->set((int) $t->id); // scope this tenant's models
            if (! (bool) $t->setting('followups_auto_send', false)) continue;

            // rail 2: local business hours
            $offset = (int) $t->setting('tz_offset', 3);
            $hour = ((int) now()->utc()->format('G') + $offset + 24) % 24;
            [$h0, $h1] = array_map('intval', array_pad(explode('-', (string) $t->setting('watchdog_hours', '7-21')), 2, 21));
            if ($hour < $h0 || $hour >= $h1) { $this->line("{$t->name}: outside business hours — nothing sent"); continue; }

            $minConf = (string) $t->setting('followups_auto_min_confidence', 'high');
            $allowed = $minConf === 'any' ? ['high', 'medium', 'low'] : ($minConf === 'medium' ? ['high', 'medium'] : ['high']);
            $cap = max(1, (int) $t->setting('followups_auto_daily_cap', 10));
            $quietH = max(1, (int) $t->setting('followups_auto_quiet_hours', 12));

            $sentToday = FollowupProposal::where('tenant_id', $t->id)->where('status', 'sent')
                ->where('updated_at', '>', now()->startOfDay())->count();

            $open = FollowupProposal::where('tenant_id', $t->id)->where('status', 'draft')
                ->orderByRaw("case confidence when 'high' then 0 when 'medium' then 1 else 2 end")
                ->orderBy('id')->get();

            $owners = array_map(fn($n) => preg_replace('/[^0-9]/', '', (string) $n), $t->ownerAlertNumbers());
            $sent = 0; $held = ['confidence' => 0, 'human_active' => 0, 'fresh_chat' => 0, 'over_cap' => 0, 'owner_number' => 0];
            foreach ($open as $i => $p) {
                if (in_array(preg_replace('/[^0-9]/', '', (string) $p->customer_phone), $owners, true)) {
                    $held['owner_number']++;
                    BotTrace::log($t->id, 'autosend', $p->customer_phone, 'held_owner_number', $p->trigger);
                    continue;
                }
                if (! in_array((string) $p->confidence, $allowed, true)) { $held['confidence']++; continue; }
                if ($sentToday + $sent >= $cap) { $held['over_cap']++; continue; }

                $convo = Conversation::withoutGlobalScope('tenant')->where('tenant_id', $t->id)
                    ->where('customer_phone', $p->customer_phone)->first();
                if ($convo && $convo->agent_active) { $held['human_active']++;
                    BotTrace::log($t->id, 'autosend', $p->customer_phone, 'held_human_active', $p->trigger); continue; }
                if ($convo && $convo->last_inbound_at && $convo->last_inbound_at->gt(now()->subHours($quietH))) {
                    $held['fresh_chat']++;
                    BotTrace::log($t->id, 'autosend', $p->customer_phone, 'held_fresh_chat', $p->trigger); continue; }

                if ($this->option('dry')) {
                    $this->line("  [DRY] would send {$p->trigger} → {$p->customer_phone} (".$p->confidence.')');
                    $sent++;
                    continue;
                }

                try {
                    // rail 7: human rhythm — stagger real sends 45–120s apart
                    if ($sent > 0) sleep(random_int(45, 120));
                    $wa->forTenant($t)->sendText($t->whatsapp_instance, $p->customer_phone, (string) $p->draft);
                    $msg = MessageLog::record($t->id, $p->customer_phone, (string) $t->whatsapp_instance,
                        'out', 'agent', (string) $p->draft);
                    $p->status = 'sent';
                    $p->approved_by = 'auto';
                    $p->sent_message_id = $msg?->id;
                    $p->save();
                    CustomerProfile::withoutGlobalScopes()->updateOrCreate(
                        ['tenant_id' => $t->id, 'phone' => $p->customer_phone], []
                    )->forceFill(['last_contacted_at' => now()])->save();
                    BotTrace::log($t->id, 'autosend', $p->customer_phone, 'auto_sent', $p->trigger);
                    $sent++;
                } catch (\Throwable $e) {
                    BotTrace::log($t->id, 'autosend', $p->customer_phone, 'auto_send_failed', mb_substr($e->getMessage(), 0, 120));
                }
            }

            $heldTxt = implode(', ', array_filter([
                $held['confidence'] ? $held['confidence'].' low-confidence' : '',
                $held['human_active'] ? $held['human_active'].' human-active' : '',
                $held['fresh_chat'] ? $held['fresh_chat'].' fresh-chat' : '',
                $held['over_cap'] ? $held['over_cap'].' over cap' : '',
                $held['owner_number'] ? $held['owner_number'].' owner-number' : '',
            ]));
            $summary = "\u{1F916} Auto-sent {$sent} follow-up(s)".($heldTxt !== '' ? " · held for you: {$heldTxt} (see AI Follow-ups)" : '');
            $this->info("{$t->name}: {$summary}");
            if (! $this->option('dry') && ($sent > 0 || $heldTxt !== '')) {
                \App\Jobs\NotifyOwner::dispatch($t->id, $summary);
            }
        }

        return self::SUCCESS;
    }
}
