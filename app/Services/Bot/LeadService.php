<?php
namespace App\Services\Bot;

use App\Jobs\NotifyOwner;
use App\Models\Lead;
use App\Models\Tenant;
use App\Support\BotTrace;
use Illuminate\Support\Facades\DB;

/**
 * Captures a sales lead (or support ticket) from a WhatsApp message, assigns it to
 * a recipient, notifies the team, and records analytics to bot_events.
 *
 * Assignment modes (settings.lead_assignment_mode):
 *   - round_robin (default): rotate through 'sales' recipients, one owner each.
 *   - claim: notify all recipients; first to reply "CLAIM <id>" wins (atomic).
 *   - manual: leave unassigned; notify managers to assign later.
 *
 * Nothing here ever throws into the message pipeline.
 */
class LeadService
{
    /** Don't re-create / re-notify if the same customer already has an open lead recently. */
    private const DEDUPE_HOURS = 6;

    /**
     * @return array{created:bool,reply:string}
     */
    public function capture(Tenant $tenant, string $phone, ?string $name, string $message, string $intent = 'lead', string $source = 'whatsapp', ?int $conversationId = null): array
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        $intent = $intent === 'ticket' ? 'ticket' : 'lead';
        $interest = $this->summarise($message);
        $score = (new LeadScorer())->score($intent, $message);
        $dedupeKey = LeadDedupe::key($phone, $intent, $interest);

        // Dedupe on CONTENT, not just customer+time: a literal repeat of the same request
        // within the window collapses to one lead, but a different ask ("Starlink" vs
        // "Fiber") from the same person is its own opportunity.
        $recent = Lead::query()
            ->where('customer_phone', $phone)
            ->where('dedupe_key', $dedupeKey)
            ->whereIn('status', Lead::OPEN_STATUSES)
            ->where('created_at', '>=', now()->subHours(self::DEDUPE_HOURS))
            ->latest('id')->first();
        if ($recent) {
            return ['created' => false, 'reply' => $this->ackCustomer($tenant, $intent, true)];
        }

        $lead = Lead::create([
            'tenant_id'       => $tenant->id,
            'customer_phone'  => $phone,
            'customer_name'   => $name ?: null,
            'intent'          => $intent,
            'interest'        => $interest,
            'dedupe_key'      => $dedupeKey,
            'lead_score'      => $score,
            'message'         => mb_substr($message, 0, 1000),
            'source'          => $source ?: 'whatsapp',
            'conversation_id' => $conversationId,
            'status'          => 'new',
        ]);
        $this->event($tenant->id, 'lead_created', $phone, "#{$lead->id} {$intent} [{$score}] via {$source}: {$interest}");

        $mode = (string) $tenant->setting('lead_assignment_mode', 'round_robin');
        $role = $intent === 'ticket' ? 'support' : 'sales';

        if ($mode === 'claim') {
            $this->notifyClaim($tenant, $lead, $role);
        } elseif ($mode === 'manual') {
            $this->notifyManual($tenant, $lead, $role);
        } else {
            $this->assignRoundRobin($tenant, $lead, $role);
        }

        return ['created' => true, 'reply' => $this->ackCustomer($tenant, $intent, false)];
    }

    /**
     * Atomic claim: first valid CLAIM wins. Returns a reply string for the claimer,
     * or null if the id/number isn't eligible (so the pipeline can ignore it).
     */
    public function claim(Tenant $tenant, int $leadId, string $byPhone): ?string
    {
        $byPhone = preg_replace('/[^0-9]/', '', $byPhone);

        // Only configured recipients may claim.
        $recipientPhones = array_map(fn ($r) => $r['phone'], $tenant->leadRecipients());
        if (! in_array($byPhone, $recipientPhones, true)) return null;

        $lead = Lead::query()->where('id', $leadId)->first();
        if (! $lead) return "Lead #{$leadId} not found.";

        // Atomic: only assign if still unclaimed.
        $won = Lead::query()
            ->where('id', $leadId)
            ->whereNull('assigned_to')
            ->update(['assigned_to' => $byPhone, 'status' => 'assigned', 'claimed_at' => now()]);

        if ($won) {
            $this->event($tenant->id, 'lead_claimed', $lead->customer_phone, "#{$leadId} by {$byPhone}");
            $this->event($tenant->id, 'lead_assigned', $lead->customer_phone, "#{$leadId} → {$byPhone}");
            $lead->refresh();
            return $this->leadCard($lead, "✅ You claimed lead #{$leadId}. It's yours.");
        }

        $lead->refresh();
        $who = $lead->assigned_to ? "+{$lead->assigned_to}" : 'someone else';
        return "⛔ Lead #{$leadId} was already claimed by {$who}.";
    }

    // ---------------------------------------------------------------- assignment

    private function assignRoundRobin(Tenant $tenant, Lead $lead, string $role): void
    {
        $recipients = $tenant->leadRecipients($role);
        $pick = $tenant->nextRoundRobin($recipients);
        if (! $pick) { $this->notifyManual($tenant, $lead, $role); return; }

        $lead->update(['assigned_to' => $pick['phone'], 'status' => 'assigned']);
        $this->event($tenant->id, 'lead_assigned', $lead->customer_phone, "#{$lead->id} → {$pick['phone']}");
        NotifyOwner::dispatch($tenant->id, $this->leadCard($lead, "🔥 New lead assigned to you (#{$lead->id})"), $pick['phone']);
    }

    private function notifyClaim(Tenant $tenant, Lead $lead, string $role): void
    {
        $msg = $this->leadCard($lead, "🔥 New lead (#{$lead->id})") . "\n\nReply *CLAIM {$lead->id}* to take it.";
        foreach ($tenant->leadRecipients($role) as $r) {
            NotifyOwner::dispatch($tenant->id, $msg, $r['phone']);
        }
    }

    private function notifyManual(Tenant $tenant, Lead $lead, string $role): void
    {
        $recipients = $tenant->leadRecipients('manager');
        if (! $recipients) $recipients = $tenant->leadRecipients($role);
        $msg = $this->leadCard($lead, "🔥 New lead (#{$lead->id}) — please assign");
        foreach ($recipients as $r) {
            NotifyOwner::dispatch($tenant->id, $msg, $r['phone']);
        }
    }

    // ---------------------------------------------------------------- status

    public function markWon(Lead $lead): void
    {
        $lead->update(['status' => 'won']);
        $this->event($lead->tenant_id, 'lead_won', $lead->customer_phone, "#{$lead->id}");
    }

    public function markLost(Lead $lead): void
    {
        $lead->update(['status' => 'lost']);
        $this->event($lead->tenant_id, 'lead_lost', $lead->customer_phone, "#{$lead->id}");
    }

    // ---------------------------------------------------------------- helpers

    private function leadCard(Lead $lead, string $head): string
    {
        $name = $lead->customer_name ?: '(unknown)';
        $kind = $lead->intent === 'ticket' ? 'Issue' : 'Interest';
        $band = (new LeadScorer())->band((int) $lead->lead_score);
        return $head . "\n\n"
            . "Priority: {$band} ({$lead->lead_score})\n"
            . "Name: {$name}\n"
            . "Phone: +{$lead->customer_phone}\n"
            . "{$kind}: " . ($lead->interest ?: '—') . "\n"
            . "Message: " . mb_substr((string) $lead->message, 0, 200);
    }

    private function ackCustomer(Tenant $tenant, string $intent, bool $repeat): string
    {
        $shop = $tenant->name ?: 'our team';
        if ($intent === 'ticket') {
            return $repeat
                ? "🙏 We've already logged your issue — {$shop} will get back to you shortly."
                : "🙏 Thanks for letting us know. We've logged your issue and {$shop} will get back to you shortly.";
        }
        return $repeat
            ? "🙏 Thanks — we've already passed your request to our team. They'll reach you shortly."
            : "🙏 Thanks for your interest! Our team will reach out to you very shortly.";
    }

    private function summarise(string $message): string
    {
        $m = trim(preg_replace('/\s+/', ' ', $message));
        return mb_substr($m, 0, 120);
    }

    private function event(int $tenantId, string $stage, string $phone, string $detail): void
    {
        BotTrace::log($tenantId, 'lead_' . substr(md5($detail . microtime()), 0, 8), $phone, $stage, $detail);
    }
}
