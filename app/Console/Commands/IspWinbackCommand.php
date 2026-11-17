<?php

namespace App\Console\Commands;

use App\Jobs\NotifyOwner;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Isp\Winback;
use App\Services\Sales\SalesOwnership;
use App\Services\WhatsApp\EvolutionAdmin;
use App\Services\WhatsApp\EvolutionHistory;
use Illuminate\Console\Command;

/**
 * Win-back list for an Internet-provider business (DishNet, 17 Nov 2026: "how we can reach old numbers as
 * relationship manager so we can chase them").
 *
 * Reads the WhatsApp line's whole history (EvolutionHistory), sorts each past customer with Winback, and —
 * with --apply — gives each one to a salesperson as a lead (their existing salesperson, or the next in turn:
 * SalesOwnership), with what the customer said, a suggested first message and a follow-up date. Each
 * salesperson gets at most --per-day a day, best first, and ONE WhatsApp summary — not one alert per lead.
 *
 * It sends NOTHING to customers. Salespeople message them personally, one at a time, from the inbox: a
 * burst of messages from an unofficial WhatsApp connection to people who have not written for months is
 * how a number gets banned, and a personal message is what wins a customer back.
 *
 * Left out: staff and muted numbers, the old bot's staff-alert receivers (listed so they can be muted),
 * forwarded scams, anyone whose last word was "stop", customers already in an open lead, anyone who wrote
 * to ShopBot in the last 14 days (already being served), and anyone imported before (safe to re-run).
 *
 *   php artisan isp:winback 31                 # dry run: what it would do, nothing written
 *   php artisan isp:winback 31 --apply
 */
class IspWinbackCommand extends Command
{
    protected $signature = 'isp:winback {tenant} {--apply : create the leads and tell each salesperson}
        {--per-day=15 : most follow-ups due per salesperson per day} {--since= : only customers who wrote since this date (YYYY-MM-DD)}
        {--sample=8 : example leads to print}';

    protected $description = 'Internet provider: turn the WhatsApp history into a win-back lead list for salespeople (dry run unless --apply)';

    public function handle(EvolutionAdmin $evo, EvolutionHistory $history, SalesOwnership $owners): int
    {
        $tenant = Tenant::find((int) $this->argument('tenant'));
        if (! $tenant || ! SalesOwnership::enabled($tenant)) {
            $this->error('No such Internet-provider business.');

            return self::FAILURE;
        }
        if (! $evo->configured() || (string) $tenant->whatsapp_instance === '') {
            $this->error('This business has no Evolution WhatsApp line to read.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $perDay = max(1, (int) $this->option('per-day'));
        $since = $this->option('since') ? strtotime((string) $this->option('since')) : 0;
        $now = time();
        $this->info(($apply ? '' : '[DRY RUN — nothing is written] ') . "{$tenant->name}: reading the WhatsApp history…");

        $chats = $history->chats((string) $tenant->whatsapp_instance);
        $this->line('  ' . count($chats) . ' conversations');

        $internal = self::digitsList(array_merge(
            (array) $tenant->setting('bot_muted', []),
            User::where('tenant_id', $tenant->id)->pluck('phone')->all(),
            $tenant->ownerAlertNumbers(),
            [(string) $tenant->whatsapp_number],
        ));
        $openLead = array_flip(self::digitsList(Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereIn('status', Lead::OPEN_STATUSES)->pluck('customer_phone')->all()));
        $imported = array_flip(self::digitsList(Lead::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('dedupe_key', 'like', 'winback:%')->pluck('customer_phone')->all()));
        $active = array_flip(self::digitsList(Message::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('direction', 'in')
            ->where('created_at', '>=', now()->subDays(14))->distinct()->pluck('customer_phone')->all()));

        $skip = [];
        $staff = [];
        $picked = [];
        foreach ($chats as $phone => $chat) {
            $phone = (string) $phone;
            $why = match (true) {
                in_array($phone, $internal, true) => 'staff_or_muted',
                isset($imported[$phone]) => 'already_imported',
                isset($openLead[$phone]) => 'open_lead',
                isset($active[$phone]) => 'wrote_in_last_14_days',
                default => null,
            };
            $c = $why === null ? Winback::classify($chat['rows'], $now) : null;
            $why ??= $c['skip'];
            if ($why === null && $since && $c['last_in'] < $since) {
                $why = 'before_since';
            }
            if ($why !== null) {
                $skip[$why] = ($skip[$why] ?? 0) + 1;
                if ($why === 'staff_phone') {
                    $staff[] = $phone;
                }
                continue;
            }
            // WhatsApp hid the number (an @lid chat with none from Evolution). Replies to such a chat DO
            // arrive: live chat, 16 Nov 2026 — our bot asked a hidden-id customer for their area and they
            // answered it within a minute. They can be messaged from the inbox, not called.
            $picked[$phone] = $c + ['name' => (string) ($chat['name'] ?? ''), 'hidden' => ! empty($chat['hidden'])];
        }

        $bySeg = array_count_values(array_column($picked, 'segment'));
        arsort($bySeg);
        $this->newLine();
        $this->info('To chase: ' . count($picked));
        foreach ($bySeg as $seg => $n) {
            $this->line(sprintf('  %-18s %4d  %s', $seg, $n, Winback::SEGMENTS[$seg] ?? ''));
        }
        $this->line('  of which we never answered their last message: ' . count(array_filter($picked, fn ($c) => $c['unanswered'])));
        $this->line('  of which WhatsApp hides the phone number (message from the inbox; cannot be called): ' . count(array_filter($picked, fn ($c) => $c['hidden'])));
        $this->info('Left out:');
        ksort($skip);
        foreach ($skip as $why => $n) {
            $this->line(sprintf('  %-22s %4d', $why, $n));
        }
        if ($staff) {
            $this->warn('These numbers received the old bot\'s STAFF alerts — add them to the muted list so the AI never answers them:');
            $this->line('  ' . implode(', ', $staff));
        }

        uasort($picked, fn ($a, $b) => $b['score'] <=> $a['score']);
        $this->newLine();
        $this->info('Examples (best first):');
        foreach (array_slice($picked, 0, max(0, (int) $this->option('sample')), true) as $phone => $c) {
            $this->line('  ' . ($c['hidden'] ? "[number hidden by WhatsApp, id {$phone}] " : "+{$phone} ") . ($c['name'] !== '' ? "({$c['name']}) " : '') . "— {$c['segment']}, {$c['service']}, score {$c['score']}, last wrote " . date('j M Y', $c['last_in']) . ($c['unanswered'] ? ', UNANSWERED' : ''));
            $this->line('     said: ' . mb_substr($c['summary'], 0, 200));
            $this->line('     first message: ' . Winback::opener($c['segment'], $c['service'], $c['unanswered'], $c['name'], '<salesperson>', (string) $tenant->name));
        }

        if (! $apply) {
            $this->newLine();
            $this->comment('Nothing was written. Run again with --apply to create the leads and tell each salesperson.');

            return self::SUCCESS;
        }

        // Owner first (their existing salesperson, or the next in turn), then the follow-up days per owner.
        $owner = [];
        foreach ($picked as $phone => $c) {
            $owner[$phone] = $owners->ownerForEnquiry($tenant, (string) $phone, $c['name'] !== '' ? $c['name'] : null);
        }
        $plan = [];
        foreach ($picked as $phone => $c) {
            $plan[(string) $phone] = ['owner' => (int) ($owner[$phone]?->id ?? 0), 'score' => $c['score']];
        }
        $days = Winback::schedule($plan, $perDay);
        $start = now()->startOfDay()->setTime(9, 0);
        $perOwner = [];
        $made = 0;
        foreach ($picked as $phone => $c) {
            $phone = (string) $phone;
            $u = $owner[$phone];
            $label = Winback::SEGMENTS[$c['segment']] ?? $c['segment'];
            $opener = Winback::opener($c['segment'], $c['service'], $c['unanswered'], $c['name'], $u?->name ?: 'your salesperson', (string) $tenant->name);
            Lead::create([
                'tenant_id'        => $tenant->id,
                'customer_phone'   => $phone,
                'customer_name'    => $c['name'] !== '' ? mb_substr($c['name'], 0, 120) : null,
                'intent'           => 'lead',
                'interest'         => ['starlink' => 'Starlink', 'fiber' => 'Fiber', 'both' => 'Starlink + Fiber'][$c['service']] ?? 'Internet',
                'dedupe_key'       => 'winback:' . $phone,
                'lead_score'       => $c['score'],
                'message'          => $c['summary'],
                'notes'            => "WIN-BACK · {$label} · last wrote " . date('j M Y', $c['last_in']) . ($c['unanswered'] ? ' · WE NEVER ANSWERED' : '')
                                    . ($c['hidden'] ? "\nWhatsApp hides this customer's phone number: message them from the ShopBot inbox (they cannot be called) and ask for a number to call." : '')
                                    . "\nSuggested first message (edit it, then send it yourself from the inbox):\n{$opener}",
                'source'           => 'winback',
                'tag'              => 'winback',
                'status'           => $u ? 'assigned' : 'new',
                'owner_user_id'    => $u?->id,
                'assigned_to'      => $u ? (preg_replace('/\D/', '', (string) $u->phone) ?: null) : null,
                'next_followup_at' => $start->copy()->addDays($days[$phone] ?? 0),
            ]);
            $made++;
            $k = $u ? (string) $u->id : '';
            $perOwner[$k]['user'] = $u;
            $perOwner[$k]['seg'][$c['segment']] = ($perOwner[$k]['seg'][$c['segment']] ?? 0) + 1;
            $perOwner[$k]['n'] = ($perOwner[$k]['n'] ?? 0) + 1;
        }

        foreach ($perOwner as $k => $o) {
            $segTxt = implode(', ', array_map(fn ($s, $n) => "{$n} {$s}", array_keys($o['seg']), $o['seg']));
            $text = "\u{1F4CB} *Win-back list* — {$o['n']} past customers to contact ({$segTxt}).\n"
                . "They are in your Leads (source: winback), best first, at most {$perDay} due each day. Each one has what the customer said and a suggested first message.\n"
                . 'Message each customer personally from the ShopBot inbox, one at a time — and offer them a quotation.';
            $to = $o['user'] && preg_replace('/\D/', '', (string) $o['user']->phone) !== '' ? [preg_replace('/\D/', '', (string) $o['user']->phone)] : $tenant->ownerAlertNumbers();
            foreach ($to as $n) {
                NotifyOwner::dispatch($tenant->id, $k === '' ? str_replace('your Leads', 'Leads (no salesperson yet: please assign)', $text) : $text, (string) $n);
            }
            $this->line('  ' . ($o['user']?->name ?? 'unassigned') . ": {$o['n']} leads");
        }
        $this->info("Created {$made} win-back leads.");

        return self::SUCCESS;
    }

    private static function digitsList(array $list): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($p) => preg_replace('/\D/', '', (string) $p) ?? '', $list))));
    }
}
