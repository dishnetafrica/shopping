<?php

namespace App\Console\Commands;

use App\Models\AiCampaignDraft;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Console\Command;

/**
 * aic:send-tick (v4.5) — the PACED sender for AI campaigns. Runs every minute; sends at most
 * 2 approved drafts per tenant per tick with jitter, daytime only. The slow pace is the
 * point: fast broadcasting is how WhatsApp numbers get banned (same doctrine as the
 * existing SendCampaign job). Idempotent via campaign_messages claims — a retried tick can
 * never message the same person twice.
 */
class AiCampaignSendCommand extends Command
{
    protected $signature = 'aic:send-tick {--force : ignore the daytime window (testing)}';

    protected $description = 'Send approved AI-campaign drafts, paced (2/tenant/minute, 08:00–20:00)';

    public function handle(WhatsAppManager $wa): int
    {
        $campaigns = Campaign::withoutGlobalScopes()->where('type', 'ai')->where('status', 'sending')->get();
        if ($campaigns->isEmpty()) {
            return 0;
        }

        foreach ($campaigns->groupBy('tenant_id') as $tenantId => $group) {
            $t = Tenant::find($tenantId);
            if (! $t || ! $t->whatsapp_instance) {
                continue;
            }

            // Daytime window in the tenant's timezone — nobody wants marketing at 02:00.
            $tz = (string) ($t->setting('timezone', '') ?: 'Africa/Kampala');
            $hour = (int) now($tz)->format('G');
            if (! $this->option('force') && ($hour < 8 || $hour >= 20)) {
                continue;
            }

            $budget = 2;   // per tenant per minute, across all its AI campaigns
            foreach ($group as $c) {
                if ($budget <= 0) {
                    break;
                }
                $due = AiCampaignDraft::where('tenant_id', $t->id)->where('campaign_id', $c->id)
                    ->where('status', 'approved')->orderBy('id')->limit($budget)->get();

                if ($due->isEmpty()) {
                    // Nothing approved left → campaign finishes when nothing is pending either.
                    $pending = AiCampaignDraft::where('tenant_id', $t->id)->where('campaign_id', $c->id)
                        ->whereIn('status', ['draft', 'approved'])->exists();
                    if (! $pending) {
                        $stats = is_array($c->stats) ? $c->stats : [];
                        $stats['finished_at'] = now()->toIso8601String();
                        $stats['ai_sent'] = AiCampaignDraft::where('campaign_id', $c->id)->where('status', 'sent')->count();
                        $c->update(['status' => 'sent', 'stats' => $stats]);
                    }
                    continue;
                }

                $gateway = $wa->forTenant($t);
                $inst = method_exists($t, 'sendInstance') ? $t->sendInstance() : $t->whatsapp_instance;

                foreach ($due as $d) {
                    if ($budget <= 0) {
                        break;
                    }
                    $budget--;
                    $recipient = \App\Support\Idempotency::recipient($d->phone);

                    // The SAME claim the classic campaign sender uses — one row per
                    // (campaign, recipient), so a crashed/retried tick cannot double-send.
                    if (! CampaignMessage::claim($t->id, $c->id, $recipient)) {
                        $d->update(['status' => 'skipped', 'error' => 'already claimed (duplicate)']);
                        continue;
                    }
                    try {
                        $gateway->sendText($inst, $d->phone, (string) $d->body);
                        $d->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
                        try {
                            CampaignMessage::where('tenant_id', $t->id)->where('campaign_id', $c->id)
                                ->where('recipient', $recipient)->update(['status' => 'sent', 'sent_at' => now()]);
                        } catch (\Throwable $e) { /* claim row status is cosmetic */ }
                    } catch (\Throwable $e) {
                        $d->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 280)]);
                        \Log::warning('aic send failed to ' . $d->phone . ': ' . $e->getMessage());
                    }
                    // Jitter between sends inside the tick — never a metronome.
                    if ($budget > 0) {
                        sleep(random_int(3, 11));
                    }
                }
            }
        }

        return 0;
    }
}
