<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Finance\CashbookExport;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Morning cashbook digest. For each opted-in tenant it:
 *   - sends a concise WhatsApp summary of the position + yesterday's movement to the owner number(s), and
 *   - emails yesterday's cashbook CSV (attachment) to the configured address, and archives it to storage.
 *
 * Opt-in: set the tenant setting `cashbook_digest_to` to an email (blank = off). WhatsApp goes to the
 * existing owner-alert numbers. Reuses CashbookExport (read-only) — no engine changes.
 *
 * Scheduled daily at 06:30 Africa/Kampala (see routes/console.php). Test now with:
 *   php artisan cashbook:digest --tenant=1 --to=you@example.com --force
 */
final class CashbookDigestCommand extends Command
{
    protected $signature = 'cashbook:digest
        {--tenant= : only this tenant id (default: all opted-in)}
        {--date= : Y-m-d to report (default: yesterday, tenant-local)}
        {--to= : override / enable recipient email for this run}
        {--force : ignore the opt-in gate (for testing)}';

    protected $description = 'Email + WhatsApp a daily cashbook digest (yesterday) to the owner.';

    public function handle(WhatsAppManager $wa, CashbookExport $export): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', (int) $this->option('tenant'))->get()
            : Tenant::all();

        $sent = 0;
        foreach ($tenants as $t) {
            $to = trim((string) ($this->option('to') ?: $t->setting('cashbook_digest_to', '')));
            if ($to === '' && ! $this->option('force')) {
                continue; // not opted in
            }

            // Yesterday in the tenant's local time (default East Africa, UTC+3).
            $offset = (int) $t->setting('tz_offset', 3);
            $date = trim((string) $this->option('date'))
                ?: now()->utc()->addHours($offset)->subDay()->toDateString();

            try {
                $data = $export->collect($t, null, $date, $date);
            } catch (\Throwable $e) {
                Log::warning('cashbook:digest collect failed', ['tenant' => $t->id, 'err' => $e->getMessage()]);
                continue;
            }

            // --- WhatsApp: a text summary + the PDF statement + a per-currency cash book for each
            //     currency that moved yesterday, all as documents (no SMTP needed) ---
            $ad = $this->adStats($t, $date, $offset);
            $text = $this->summaryText($t, $data, $date, $ad);
            $numbers = method_exists($t, 'ownerAlertNumbers') ? $t->ownerAlertNumbers() : [];
            $instance = $t->whatsapp_instance ?? null;
            if ($instance && $numbers) {
                $gw = $wa->forTenant($t);
                $canDoc = method_exists($gw, 'sendDocument');
                $docs = [];   // [b64, fileName, caption]

                // combined statement
                try {
                    $pdf = $export->pdf($t, $data);
                    if ($pdf) { $docs[] = [base64_encode($pdf), 'Cashbook-' . $date . '.pdf', '🧾 Cashbook ' . $date . ' — full statement']; }
                } catch (\Throwable $e) { /* skip */ }

                // one running-balance book per currency that had movement yesterday
                foreach (['USD', 'UGX', 'SSP'] as $cc) {
                    try {
                        $cd = $export->collect($t, null, $date, $date, $cc);
                        if (empty($cd['book']['entries'])) { continue; } // no movement in this currency yesterday
                        $cpdf = $export->pdf($t, $cd);
                        if ($cpdf) { $docs[] = [base64_encode($cpdf), $cc . '-Cashbook-' . $date . '.pdf', '📘 ' . $cc . ' cash book ' . $date . ' (running balance)']; }
                    } catch (\Throwable $e) { /* skip this currency */ }
                }

                foreach ($numbers as $n) {
                    try {
                        $gw->sendText($instance, $n, $text);
                        if ($canDoc) {
                            foreach ($docs as $doc) {
                                $gw->sendDocument($instance, $n, $doc[0], $doc[1], $doc[2]);
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning('cashbook:digest WA failed', ['tenant' => $t->id, 'to' => $n, 'err' => $e->getMessage()]);
                    }
                }
            }

            // --- CSV: archive to storage, and email as an attachment ---
            $csv = $export->csv($data);
            $fname = 'Cashbook-' . $t->id . '-' . $date . '.csv';
            try {
                Storage::disk('local')->put('cashbook-digests/' . $t->id . '/' . $fname, $csv);
            } catch (\Throwable $e) {
                Log::warning('cashbook:digest archive failed', ['tenant' => $t->id, 'err' => $e->getMessage()]);
            }

            if ($to !== '') {
                try {
                    // Comms M2: live tenants send via the Email Center (tenant identity, failover,
                    // retry, audit log). Flag off => the legacy Mail::raw path runs untouched.
                    if ((bool) $t->setting('comms_email_live', false)) {
                        $body = $this->emailBody($t, $date);
                        app(\App\Services\Comms\EmailService::class)->queueRaw(
                            $t, $to,
                            ($t->name ?: 'Cashbook') . ' — cashbook ' . $date,
                            nl2br(e($body)), $body,
                            [
                                'attachments'  => [['name' => 'Cashbook-' . $date . '.csv', 'mime' => 'text/csv', 'data' => base64_encode($csv)]],
                                'related_type' => 'cashbook_digest',
                                'meta'         => ['date' => $date],
                            ]
                        );
                    } else {
                        Mail::raw($this->emailBody($t, $date), function ($m) use ($to, $csv, $date, $t) {
                            $m->to($to)
                                ->subject(($t->name ?: 'Cashbook') . ' — cashbook ' . $date)
                                ->attachData($csv, 'Cashbook-' . $date . '.csv', ['mime' => 'text/csv']);
                        });
                    }
                } catch (\Throwable $e) {
                    Log::warning('cashbook:digest email failed', ['tenant' => $t->id, 'to' => $to, 'err' => $e->getMessage()]);
                }
            }

            $sent++;
            $this->info("digest sent: tenant {$t->id} ({$date})" . ($to ? " -> {$to}" : ''));
        }

        $this->info("cashbook:digest done — {$sent} tenant(s).");

        return self::SUCCESS;
    }

    /**
     * Facebook-ad arrivals for the reported (tenant-local) day and the trailing 7 days, read from the
     * bot_events trace. Returns [] if the table is absent — the digest then simply omits the ad line.
     */
    private function adStats(Tenant $t, string $date, int $offset): array
    {
        try {
            $startUtc = Carbon::parse($date . ' 00:00:00')->subHours($offset);
            $endUtc = Carbon::parse($date . ' 23:59:59')->subHours($offset);
            $day = (int) DB::table('bot_events')->where('tenant_id', $t->id)->where('stage', 'ad_referral')
                ->whereBetween('created_at', [$startUtc, $endUtc])->count();
            $week = (int) DB::table('bot_events')->where('tenant_id', $t->id)->where('stage', 'ad_referral')
                ->where('created_at', '>=', $endUtc->copy()->subDays(7))->count();

            return ['day' => $day, 'week' => $week, 'enabled' => (bool) $t->setting('bot_ad_awareness', true)];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Concise WhatsApp text: current cash, yesterday's in/out per currency, and who holds what. */
    private function summaryText(Tenant $t, array $data, string $date, array $ad = []): string
    {
        $money = function (float $v, string $c) {
            $n = number_format($v, $c === 'USD' ? 2 : 0);
            return $c === 'USD' ? ('$' . $n) : ($n . ' ' . $c);
        };

        $lines = [];
        $lines[] = '🧾 *' . ($t->name ?: 'Cashbook') . ' — Cashbook ' . $date . '*';
        $lines[] = 'Combined cash: ' . $money($data['combinedUsd'], 'USD');

        // yesterday's movement per currency (period == the reported day)
        if (! empty($data['period']['movement'])) {
            $mv = [];
            foreach (['USD', 'UGX', 'SSP'] as $c) {
                $m = $data['period']['movement'][$c] ?? null;
                if ($m && (abs($m['in']) > 0.01 || abs($m['out']) > 0.01)) {
                    $mv[] = $c . ': +' . $money($m['in'], $c) . ' / −' . $money($m['out'], $c);
                }
            }
            if ($mv) {
                $lines[] = '';
                $lines[] = "*Yesterday (in / out):*";
                foreach ($mv as $l) { $lines[] = $l; }
            }
        }

        if (! empty($data['holders'])) {
            $lines[] = '';
            $lines[] = '*Held by:*';
            foreach ($data['holders'] as $name => $b) {
                $parts = [];
                foreach (['USD', 'UGX', 'SSP'] as $c) {
                    if (abs($b[$c]) > 0.01) { $parts[] = $money($b[$c], $c); }
                }
                if ($parts) { $lines[] = '• ' . $name . ': ' . implode(', ', $parts) . ' (~' . $money($b['usd'], 'USD') . ')'; }
            }
        }

        // Facebook-ad arrivals (only when there's been traffic in the trailing week)
        if (! empty($ad) && (int) ($ad['week'] ?? 0) > 0) {
            $lines[] = '';
            $lines[] = '*📣 Facebook ads:* ' . (int) ($ad['day'] ?? 0) . ' yesterday · ' . (int) ($ad['week'] ?? 0) . ' in 7 days';
            if (empty($ad['enabled'])) {
                $lines[] = '⚠️ Ad understanding is OFF — turn it on: app → Facebook Ads.';
            }
        }

        $lines[] = '';
        $lines[] = '_Full CSV/PDF: open the app → Cashbook → ⋮ Export._';

        return implode("\n", $lines);
    }

    private function emailBody(Tenant $t, string $date): string
    {
        return "Attached is the cashbook for {$date} (" . ($t->name ?: 'your business') . ").\n\n"
            . "It includes the movement for the day, the current cash position by currency and by person, "
            . "and every entry. Open it in Excel.\n\n— CloudBSS";
    }
}
