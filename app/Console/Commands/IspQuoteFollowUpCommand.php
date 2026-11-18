<?php

namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Models\Message;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Bot\TimeContext;
use App\Services\Isp\QuoteFollowUp;
use App\Services\Sales\SalesOwnership;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\BotTrace;
use App\Support\MessageLog;
use Illuminate\Console\Command;

/**
 * The AI relationship manager's quotation follow-up for Internet-provider businesses (DishNet, 18 Nov 2026).
 * Scheduled hourly; does something only for a business that switched it on (isp_quote_followup) and only
 * while that business is OPEN by its own business_hours (unknown hours = never send).
 *
 * For each quotation sent 2–14 days ago that is still open (not accepted, converted, declined, expired),
 * whose customer has NOT written since it was sent, it sends touch 1 on day 2 and touch 2 on day 5
 * (QuoteFollowUp), in the customer's salesperson's name, and tells that salesperson. It never writes to:
 * a muted number, staff, a chat a person has taken over, a customer who opted out or asked not to be
 * contacted, or anyone contacted in the last 24 hours. Only the newest quotation per customer is followed.
 * At most isp_quote_followup_daily_cap a day (default 20), 30–90 seconds apart — pacing, not a blast,
 * on an unofficial WhatsApp line.
 */
class IspQuoteFollowUpCommand extends Command
{
    protected $signature = 'isp:quote-followup {--tenant= : only this business} {--dry : show what would be sent} {--no-pause : do not wait between sends (tests)}';

    /** Statuses that mean the quotation is waiting on the customer. */
    public const WAITING = ['sent', 'revised'];

    protected $description = 'Internet provider: follow up open quotations the customer has not answered (day 2 and day 5), in office hours';

    public function handle(WhatsAppManager $wa, SalesOwnership $owners): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q) => $q->whereKey((int) $this->option('tenant')))->get();
        foreach ($tenants as $t) {
            if (! SalesOwnership::enabled($t) || ! (bool) $t->setting('isp_quote_followup', false)) {
                continue;
            }
            app(\App\Support\TenantContext::class)->set((int) $t->id);
            $tz = TimeContext::zone((string) $t->setting('timezone', ''), (string) $t->setting('city', ''));
            $now = now()->toDateTimeImmutable()->setTimezone(new \DateTimeZone($tz));
            if (TimeContext::isOpen(TimeContext::parseHours((string) $t->setting('business_hours', '')), $now) !== true) {
                $this->line("{$t->name}: closed (or business hours not set) — nothing sent");
                continue;
            }
            $this->run1($t, $wa, $owners);
        }

        return self::SUCCESS;
    }

    private function run1(Tenant $t, WhatsAppManager $wa, SalesOwnership $owners): void
    {
        $digits = fn ($p) => preg_replace('/\D/', '', (string) $p) ?? '';
        $skip = array_flip(array_filter(array_map($digits, array_merge(
            (array) $t->setting('bot_muted', []), User::where('tenant_id', $t->id)->pluck('phone')->all(), $t->ownerAlertNumbers()))));
        $cap = max(1, (int) $t->setting('isp_quote_followup_daily_cap', 20));

        // Only quotations waiting on the customer: "sent", or "revised" by sales. Never one the customer
        // changed in the portal (customer_revised), nor accepted, converted, declined, expired or a draft.
        $quotes = Quotation::withoutGlobalScopes()->where('tenant_id', $t->id)->whereNull('order_id')->whereNull('accepted_at')
            ->whereIn('status', self::WAITING)
            ->where('created_at', '>=', now()->subDays(60))->orderByDesc('id')->get();
        $today = now()->toDateString();
        $sentToday = $quotes->sum(fn ($q) => count(array_filter((array) (($q->meta ?? [])['isp_followups'] ?? []), fn ($at) => str_starts_with((string) $at, $today))));

        $seen = [];
        $sent = 0;
        foreach ($quotes as $q) {
            $phone = $digits($q->customer_phone);
            if ($phone === '' || isset($seen[$phone])) {
                continue;                                   // only the newest quotation per customer
            }
            $seen[$phone] = true;
            if ($q->valid_until && $q->valid_until->isPast() && ! $q->valid_until->isToday()) {
                continue;
            }
            // Counted from when it was last sent: a quotation sales revised and re-sent starts again.
            $sentAt = $q->last_sent_at ?? $q->created_at;
            $followups = array_values(array_filter((array) (($q->meta ?? [])['isp_followups'] ?? []), fn ($at) => (string) $at >= $sentAt->toIso8601String()));
            $touch = QuoteFollowUp::touch((int) $sentAt->diffInDays(now()), count($followups));
            if ($touch === null) {
                continue;
            }
            $why = $this->blocked($t, $sentAt, $phone, $skip);
            if ($why !== null) {
                BotTrace::log($t->id, 'isp_followup', $phone, 'held_' . $why, (string) $q->quote_no);
                continue;
            }
            if ($sentToday + $sent >= $cap) {
                $this->line("  daily cap {$cap} reached");
                break;
            }

            $owner = $owners->ownerOf($t, $phone);
            $text = QuoteFollowUp::message($touch, $q->customer_name ?: CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', $phone)->value('name'),
                $owner?->name, (string) $t->name, (string) $q->quote_no, $q->valid_until?->format('j M Y'));
            if ($this->option('dry')) {
                $this->line("  [DRY] touch {$touch} → +{$phone} ({$q->quote_no}): {$text}");
                $sent++;
                continue;
            }
            if ($sent > 0 && ! $this->option('no-pause')) {
                sleep(random_int(30, 90));                  // human rhythm on an unofficial line
            }
            try {
                $res = $wa->forTenant($t)->sendText((string) $t->whatsapp_instance, $phone, $text);
                if (is_array($res) && (($res['_ok'] ?? true) === false || isset($res['error']))) {
                    BotTrace::log($t->id, 'isp_followup', $phone, 'send_failed', (string) $q->quote_no);
                    continue;
                }
            } catch (\Throwable $e) {
                BotTrace::log($t->id, 'isp_followup', $phone, 'send_failed', mb_substr($e->getMessage(), 0, 120));
                continue;
            }
            MessageLog::record($t->id, $phone, (string) $t->whatsapp_instance, 'out', 'bot', $text, null, null, ['via' => 'quote_followup', 'quote_no' => $q->quote_no, 'touch' => $touch]);
            $meta = (array) ($q->meta ?? []);
            $meta['isp_followups'] = array_merge((array) ($meta['isp_followups'] ?? []), [now()->toIso8601String()]);
            $q->meta = $meta;
            $q->save();
            CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', $phone)->update(['last_contacted_at' => now()]);
            $to = $owner && $digits($owner->phone) !== '' ? [$digits($owner->phone)] : $t->ownerAlertNumbers();
            foreach ($to as $n) {
                NotifyOwner::dispatch($t->id, "\u{1F4E8} Followed up quotation {$q->quote_no} with " . ($q->customer_name ? $q->customer_name . ' ' : '') . \App\Services\Isp\CallbackNumber::label($t, $phone) . " (touch {$touch} of 2). If they reply, take it from there.", (string) $n);
            }
            BotTrace::log($t->id, 'isp_followup', $phone, 'sent_touch_' . $touch, (string) $q->quote_no);
            $sent++;
        }
        $this->info("{$t->name}: followed up {$sent} quotation(s)");
    }

    /** Why this customer must not get a follow-up now, or null. */
    private function blocked(Tenant $t, \DateTimeInterface $sentAt, string $phone, array $skip): ?string
    {
        if (isset($skip[$phone])) {
            return 'muted_or_staff';
        }
        $convo = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)->first();
        if ($convo && $convo->agent_active) {
            return 'human_active';
        }
        if ($convo && ! empty(((array) ($convo->state ?? []))['do_not_contact'])) {
            return 'do_not_contact';
        }
        $p = CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', $phone)->first();
        if ($p && $p->marketing_opted_out_at) {
            return 'opted_out';
        }
        if ($p && $p->last_contacted_at && $p->last_contacted_at->gt(now()->subHours(24))) {
            return 'contacted_recently';
        }
        // Anything we sent them in the last 24 hours (a resend from the panel, a salesperson's message, the
        // bot) counts as contact: a resend does not stamp last_contacted_at (Codex review on #165).
        if (Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)
            ->where('direction', 'out')->where('created_at', '>', now()->subHours(24))->exists()) {
            return 'contacted_recently';
        }
        // They wrote after the quotation was sent: the conversation is live — the bot and their salesperson have it.
        if (Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)
            ->where('direction', 'in')->where('created_at', '>', $sentAt)->exists()) {
            return 'customer_replied';
        }

        return null;
    }
}
