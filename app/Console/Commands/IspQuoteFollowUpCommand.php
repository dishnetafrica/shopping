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
use App\Services\Isp\CallbackNumber;
use App\Services\Isp\ChatFollowUp;
use App\Services\Isp\QuoteFollowUp;
use App\Services\Isp\Winback;
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

    protected $description = 'Internet provider: follow up unanswered quotations (day 2, 5) and enquiries that went quiet (day 1, 4), in office hours';

    public function handle(WhatsAppManager $wa, SalesOwnership $owners): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($q) => $q->whereKey((int) $this->option('tenant')))->get();
        foreach ($tenants as $t) {
            $quotesOn = (bool) $t->setting('isp_quote_followup', false);
            // Quiet enquiries: on with the quotation follow-up unless switched off on its own.
            $chatsOn = (bool) $t->setting('isp_chat_followup', $quotesOn);
            if (! SalesOwnership::enabled($t) || (! $quotesOn && ! $chatsOn)) {
                continue;
            }
            app(\App\Support\TenantContext::class)->set((int) $t->id);
            $tz = TimeContext::zone((string) $t->setting('timezone', ''), (string) $t->setting('city', ''));
            $now = now()->toDateTimeImmutable()->setTimezone(new \DateTimeZone($tz));
            if (TimeContext::isOpen(TimeContext::parseHours((string) $t->setting('business_hours', '')), $now) !== true) {
                $hours = trim((string) $t->setting('business_hours', '')) ?: 'not set';
                if (! $this->option('dry')) {
                    $this->line("{$t->name}: closed now ({$now->format('D H:i')} {$tz}, hours: {$hours}) — nothing sent");
                    continue;
                }
                // A preview sends nothing, so it may run at any hour: show what would go out once open.
                $this->line("{$t->name}: closed now ({$now->format('D H:i')} {$tz}, hours: {$hours}) — PREVIEW of what would go out when open:");
            }
            $this->run1($t, $wa, $owners, $quotesOn, $chatsOn);
        }

        return self::SUCCESS;
    }

    private function run1(Tenant $t, WhatsAppManager $wa, SalesOwnership $owners, bool $quotesOn = true, bool $chatsOn = false): void
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
        // Every automatic follow-up sent today, quotation or quiet chat: one daily cap for both.
        $sentToday = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'out')
            ->where('created_at', '>=', now()->startOfDay())->whereIn('meta->via', ['quote_followup', 'chat_followup'])->count();
        $openQuotes = $quotes;
        if (! $quotesOn) {
            $quotes = collect();
        }

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
        if ($chatsOn) {
            $this->chats($t, $wa, $owners, $skip, $cap, $sentToday + $sent, $openQuotes);
        }
    }

    /**
     * Quiet enquiries (ChatFollowUp): WE answered last and the customer went quiet — touch 1 a day after their
     * last message, touch 2 on day 4. Only live messages count (history copied from the phone is never chased);
     * only sales enquiries (Winback::classify: no spam, no "stop", no support chat, no vendor or partner); a
     * quotation sent after their last message is the quotation follow-up's (above), not this.
     */
    private function chats(Tenant $t, WhatsAppManager $wa, SalesOwnership $owners, array $skip, int $cap, int $usedToday, $quotes): void
    {
        $digits = fn ($p) => preg_replace('/\D/', '', (string) $p) ?? '';
        $live = fn ($q) => $q->whereNull('meta')->orWhereNull('meta->imported');
        $quoteOf = [];
        foreach ($quotes as $q) {
            $quoteOf[$digits($q->customer_phone)] ??= $q;    // newest open quotation per customer
        }
        $phones = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('direction', 'in')->where($live)
            ->where('created_at', '>=', now()->subDays(ChatFollowUp::MAX_DAYS + 1))->distinct()->pluck('customer_phone');
        $nowTs = now()->getTimestamp();                     // the application clock (tests move it), never time()
        $sent = 0;
        $owed = [];
        $due = [];
        foreach ($phones as $phone) {
            $phone = $digits($phone);
            if ($phone === '' || isset($skip[$phone])) {
                continue;
            }
            $rows = Message::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)->where($live)
                ->where('created_at', '>=', now()->subDays(30))->orderBy('created_at')->orderBy('id')->get(['direction', 'body', 'created_at'])
                ->map(fn ($m) => [$m->created_at->getTimestamp(), $m->direction === 'out', (string) $m->body])->all();
            $lastIn = 0;
            $lastOut = 0;
            foreach ($rows as $r) {
                if ($r[1]) {
                    $lastOut = max($lastOut, $r[0]);
                } else {
                    $lastIn = max($lastIn, $r[0]);
                }
            }
            if ($lastIn === 0 || $lastOut <= $lastIn) {
                if ($lastIn > 0 && $lastIn < $nowTs - 3600) {
                    $owed[$phone] = $lastIn;                // they wrote last and nobody answered: shown, never chased
                }
                continue;
            }
            $q = $quoteOf[$phone] ?? null;
            if ($q && ($q->last_sent_at ?? $q->created_at)->getTimestamp() > $lastIn) {
                continue;                                   // a quotation they have not answered: the quotation follow-up has it
            }
            $convo = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)->orderByDesc('last_message_at')->first();
            $state = (array) ($convo?->state ?? []);
            $done = count(array_filter((array) ($state['isp_chat_followups'] ?? []), fn ($at) => strtotime((string) $at) > $lastIn));
            $touch = ChatFollowUp::touch(intdiv($nowTs - $lastIn, 86400), $done);
            if ($touch === null) {
                continue;
            }
            $c = Winback::classify($rows, $nowTs);
            $texts = array_map(fn ($r) => $r[2], array_filter($rows, fn ($r) => ! $r[1]));
            $why = $c['skip'] ?? (ChatFollowUp::notACustomer($texts) ? 'not_a_customer'
                : ($c['segment'] === 'existing_customer' ? 'support_chat'
                : (! ChatFollowUp::salesEnquiry($c['segment'], $c['signals']) ? 'no_sales_question' : null)));
            $why ??= $this->blocked($t, (new \DateTimeImmutable())->setTimestamp($lastIn), $phone, $skip);
            if ($why !== null) {
                BotTrace::log($t->id, 'isp_followup', $phone, 'chat_held', $why . ' · touch ' . $touch);   // stage holds 24 characters
                continue;
            }
            $due[] = ['phone' => $phone, 'last_in' => $lastIn, 'touch' => $touch, 'c' => $c, 'q' => $q];
        }

        // More are due than the daily cap allows: the best leads first (ready to buy, then a price question,
        // then a hello), the most recent first within each. The rest are still due tomorrow.
        usort($due, fn ($a, $b) => [ChatFollowUp::rank($a['c']['segment']), -$a['last_in']] <=> [ChatFollowUp::rank($b['c']['segment']), -$b['last_in']]);
        $people = $owners->salespeople($t);
        $summary = [];                                      // recipient => lines: ONE alert per salesperson per run
        foreach ($due as $i => $d) {
            ['phone' => $phone, 'last_in' => $lastIn, 'touch' => $touch, 'c' => $c, 'q' => $q] = $d;
            if ($usedToday + $sent >= $cap) {
                // Codex on #172: what is left stays due only until 14 days after the customer's last message.
                $left = array_slice($due, $i);
                $last = count(array_filter($left, fn ($x) => intdiv($nowTs - $x['last_in'], 86400) >= ChatFollowUp::MAX_DAYS));
                $this->line("  daily cap {$cap} reached — " . count($left) . ' more did not fit today'
                    . ($last ? "; {$last} of them reach 14 days today and will not be followed up" : '; they stay due for the next run'));
                break;
            }

            // A dry run changes nothing: it shows the salesperson the customer has, or would get when there is one.
            $owner = $this->option('dry')
                ? ($owners->ownerOf($t, $phone) ?? (count($people) === 1 ? $people[0] : null))
                : $owners->ownerForEnquiry($t, $phone);
            $name = $q?->customer_name ?: CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', $phone)->value('name');
            $text = ChatFollowUp::message($touch, (string) $c['segment'], (string) $c['service'], $name, $owner?->name, (string) $t->name, $q?->quote_no);
            if ($this->option('dry')) {
                $this->line("  [DRY] quiet chat, touch {$touch} → +{$phone} ({$c['segment']}, {$c['service']}, asked " . date('j M', $lastIn) . "): {$text}");
                $sent++;
                continue;
            }
            if (($sent > 0 || $usedToday > 0) && ! $this->option('no-pause')) {
                sleep(random_int(30, 90));                  // human rhythm on an unofficial line
            }
            try {
                $res = $wa->forTenant($t)->sendText((string) $t->whatsapp_instance, $phone, $text);
                if (is_array($res) && (($res['_ok'] ?? true) === false || isset($res['error']))) {
                    BotTrace::log($t->id, 'isp_followup', $phone, 'chat_send_failed', 'HTTP ' . ($res['_status'] ?? '?'));
                    continue;
                }
            } catch (\Throwable $e) {
                BotTrace::log($t->id, 'isp_followup', $phone, 'chat_send_failed', mb_substr($e->getMessage(), 0, 120));
                continue;
            }
            MessageLog::record($t->id, $phone, (string) $t->whatsapp_instance, 'out', 'bot', $text, null, null, ['via' => 'chat_followup', 'touch' => $touch]);
            $convo = Conversation::withoutGlobalScopes()->where('tenant_id', $t->id)->where('customer_phone', $phone)->orderByDesc('last_message_at')->first();
            if ($convo) {
                $st = (array) ($convo->state ?? []);
                $st['isp_chat_followups'] = array_slice(array_merge((array) ($st['isp_chat_followups'] ?? []), [now()->toIso8601String()]), -6);
                $st['lg_last_out'] = strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));
                $convo->state = $st;
                $convo->save();
            }
            CustomerProfile::withoutGlobalScopes()->where('tenant_id', $t->id)->where('phone', $phone)->update(['last_contacted_at' => now()]);
            $what = ['starlink' => 'Starlink', 'fiber' => 'Fiber', 'both' => 'Starlink and Fiber'][$c['service']] ?? 'internet';
            $line = '• ' . ($name && preg_match('/\p{L}/u', (string) $name) ? $name . ' ' : '') . CallbackNumber::label($t, $phone)
                . " — {$what}" . ($q ? " (quotation {$q->quote_no})" : '') . ', asked ' . date('j M', $lastIn) . ($touch > 1 ? ' — 2nd and last follow-up' : '');
            foreach ($owner && $digits($owner->phone) !== '' ? [$digits($owner->phone)] : $t->ownerAlertNumbers() as $n) {
                $summary[(string) $n][] = $line;
            }
            BotTrace::log($t->id, 'isp_followup', $phone, 'chat_sent', 'touch ' . $touch . ' · ' . $c['segment']);
            $sent++;
        }
        foreach ($summary as $n => $lines) {
            NotifyOwner::dispatch($t->id, "\u{1F4E8} *Follow-ups sent in your name* (" . count($lines) . "):\n" . implode("\n", $lines)
                . "\nThese customers asked us something and then went quiet. If they reply, take it from there.", (string) $n);
        }
        $this->info("{$t->name}: followed up {$sent} quiet chat(s)");
        if ($owed) {
            asort($owed);
            $this->warn('  ' . count($owed) . ' chat(s) where the customer wrote last and nobody has answered: '
                . implode(', ', array_map(fn ($p, $at) => '+' . $p . ' (' . date('j M H:i', $at) . ')', array_keys(array_slice($owed, 0, 10, true)), array_slice($owed, 0, 10, true))));
        }
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
