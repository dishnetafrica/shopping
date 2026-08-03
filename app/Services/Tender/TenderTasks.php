<?php

namespace App\Services\Tender;

use App\Models\Tenant;
use App\Models\Tender;

/**
 * v4.20 TenderBot drop 2 — the Procurement Task Engine ("TenderBot becomes an operations
 * manager instead of an analyst").
 *
 * After a successful analysis, the report's action_items become REAL tasks in the existing
 * ops task system (TaskEngine::assignAdHoc): the assignee gets the standard WhatsApp nudge
 * with a no-login task link, and the platform's twice-daily task reminders + escalation
 * cover them automatically. Nothing new to learn — tender work lands in the same inbox as
 * every other task.
 *
 * Priorities map HIGH/CRITICAL→3, LOW→1, else 2. Due dates respect the tender deadline:
 * high-priority work is due TODAY, everything is due at latest the day before submission.
 */
final class TenderTasks
{
    private const MAX_TASKS = 8;

    /** @return int number of tasks created */
    public function forTender(Tenant $tenant, Tender $tender): int
    {
        try {
            if (! (bool) $tenant->setting('tender_tasks', true)) {
                return 0;
            }
            if (in_array($tender->status, ['failed', 'ignored'], true)) {
                return 0;
            }
            if (strcasecmp((string) $tender->division, 'Ignore') === 0
                || strcasecmp((string) $tender->recommendation, 'IGNORE') === 0) {
                return 0;
            }
            $rep = (array) ($tender->report ?? []);
            $items = array_slice(array_values(array_filter((array) ($rep['action_items'] ?? []))), 0, self::MAX_TASKS);
            if (! $items) {
                return 0;
            }
            $assignee = $this->ownerUser($tenant);
            if (! $assignee) {
                return 0;
            }
            $engine = app(\App\Services\Ops\TaskEngine::class);
            $short = $this->clip((string) ($tender->tender_no ?: $tender->organization ?: $tender->title ?: ('Tender #' . $tender->id)), 46);

            $deadline = $tender->deadline ? $tender->deadline->copy() : null;
            $made = 0;
            foreach ($items as $it) {
                $title = trim((string) (is_array($it) ? ($it['task'] ?? '') : $it));
                if ($title === '') {
                    continue;
                }
                $prio = strtoupper((string) (is_array($it) ? ($it['priority'] ?? '') : ''));
                $priN = (str_contains($prio, 'HIGH') || str_contains($prio, 'CRIT') || str_contains($prio, 'URG')) ? 3
                    : (str_contains($prio, 'LOW') ? 1 : 2);

                // Due date: high priority → today 17:00 (or +4h when it's already late in the
                // day); others → next business day 17:00; never later than deadline − 1 day.
                $due = $priN === 3
                    ? (now()->hour >= 14 ? now()->addHours(4) : now()->setTime(17, 0))
                    : $this->nextBusinessDay();
                if ($deadline) {
                    $lastSafe = $deadline->copy()->subDay()->setTime(17, 0);
                    if ($lastSafe->isFuture() && $due->gt($lastSafe)) {
                        $due = $lastSafe;
                    }
                    $daysLeft = now()->startOfDay()->diffInDays($deadline, false);
                    if ($daysLeft >= 0 && $daysLeft <= 2) {
                        $priN = 3;   // ≤48h to deadline → everything is high priority (spec rule)
                    }
                }

                $res = $engine->assignAdHoc($tenant, $assignee, '🏆 ' . $short . ': ' . $this->clip($title, 120), $due, $priN);
                if (is_array($res) && ($res['ok'] ?? false)) {
                    $made++;
                }
            }
            if ($made > 0) {
                try {
                    \App\Support\BotTrace::log($tenant->id, 'tender_tasks', 'system', (string) $tender->id, $made . ' tasks created');
                } catch (\Throwable $e) {
                }
            }

            return $made;
        } catch (\Throwable $e) {
            \Log::warning('TenderTasks failed: ' . $e->getMessage());

            return 0;
        }
    }

    /** The supplier RFQ message for this tender's quotation-needed products (no AI cost). */
    public function rfqMessage(Tenant $tenant, Tender $tender): string
    {
        $rep = (array) ($tender->report ?? []);
        $items = (array) ($rep['products_required'] ?? []);
        $lines = [];
        foreach (array_slice($items, 0, 30) as $it) {
            if (! is_array($it)) {
                continue;
            }
            if (array_key_exists('needs_quote', $it) && $it['needs_quote'] === false) {
                continue;
            }
            $name = trim((string) ($it['item'] ?? ''));
            if ($name === '') {
                continue;
            }
            $qty = trim((string) ($it['qty'] ?? ''));
            $spec = trim((string) ($it['spec'] ?? ''));
            $brand = trim((string) ($it['brand'] ?? ''));
            $lines[] = '• ' . $name
                . ($qty !== '' ? ' — Qty: ' . $qty : '')
                . ($spec !== '' ? ' (' . $this->clip($spec, 90) . ')' : '')
                . ($brand !== '' ? ' [' . $brand . ' or equivalent]' : '');
        }
        if (! $lines) {
            return '';
        }
        $needBy = '';
        if ($tender->deadline) {
            $needBy = $tender->deadline->copy()->subDays(2);
            $needBy = $needBy->isFuture() ? $needBy->format('D d M') : $tender->deadline->format('D d M');
        }
        $loc = trim((string) (($rep['delivery_location'] ?? '')));

        return "REQUEST FOR QUOTATION — " . ($tender->organization ?: 'tender') . ($tender->tender_no ? ' (' . $tender->tender_no . ')' : '') . "\n"
            . "From: " . $tenant->name . "\n\n"
            . "Please quote your best price for:\n" . implode("\n", $lines) . "\n\n"
            . "Include: unit price, availability/lead time, warranty" . ($loc !== '' ? ", delivery to {$loc}" : '') . ".\n"
            . ($needBy !== '' ? "We need your quotation by {$needBy} to meet the tender deadline.\n" : '')
            . "Thank you.";
    }

    private function ownerUser(Tenant $tenant): ?object
    {
        try {
            $users = \App\Models\User::where('tenant_id', $tenant->id)->orderBy('id')->limit(25)->get();
            foreach ($users as $u) {
                if (method_exists($u, 'isOwnerLike') && $u->isOwnerLike()) {
                    return $u;
                }
            }

            return $users->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function nextBusinessDay(): \Illuminate\Support\Carbon
    {
        $d = now()->addDay()->setTime(17, 0);
        if ($d->isSaturday()) {
            $d->addDays(2);
        } elseif ($d->isSunday()) {
            $d->addDay();
        }

        return $d;
    }

    private function clip(string $s, int $max): string
    {
        $s = trim($s);

        return mb_strlen($s, 'UTF-8') > $max ? rtrim(mb_substr($s, 0, $max - 1, 'UTF-8')) . '…' : $s;
    }
}
