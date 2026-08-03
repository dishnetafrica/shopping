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

    /**
     * v4.23 — hand the pricing work to the people who actually walk the market.
     *
     * A 68-line RFQ is not one person's afternoon. This splits the tender's unpriced lines into buying
     * categories (sports / stationery / toiletries …), deals those categories round-robin to the team
     * named in Settings → Tenders → "Who sources prices", and gives each person ONE task carrying their
     * lines as a tick-list. They get the usual WhatsApp nudge with a no-login link, so nobody needs a
     * panel login to work from the market.
     *
     * One task per person, never one per item: 68 separate nudges would be noise, and a buyer wants a
     * shopping list, not an inbox.
     *
     * @return array{tasks:int, people:array<int,string>, unmatched:array<int,string>, items:int, message:string}
     */
    public function assignSourcing(Tenant $tenant, Tender $tender): array
    {
        $out = ['tasks' => 0, 'people' => [], 'unmatched' => [], 'items' => 0, 'message' => ''];
        try {
            if (in_array($tender->status, ['failed', 'ignored'], true)) {
                $out['message'] = 'This tender is closed — no pricing work assigned.';

                return $out;
            }
            $rows = \App\Models\TenderItem::where('tenant_id', $tenant->id)
                ->where('tender_id', $tender->id)
                ->whereIn('status', ['to_price', 'quoted'])
                ->orderBy('line')->limit(300)->get();
            $out['items'] = $rows->count();
            if ($rows->isEmpty()) {
                $out['message'] = 'Nothing to price on this tender — every line already has a cost.';

                return $out;
            }

            [$people, $unmatched] = $this->pricingTeam($tenant);
            $out['unmatched'] = $unmatched;
            if (! $people) {
                $out['message'] = $unmatched
                    ? 'Could not find ' . implode(' or ', $unmatched) . ' in your team. Check the spelling in Settings → Tenders → "Who sources prices", or add them under Settings → Team.'
                    : 'No one is listed in Settings → Tenders → "Who sources prices", so there is nobody to assign to.';

                return $out;
            }

            // group the lines by buying category, then deal whole categories to people in turn
            $svc = app(TenderItems::class);
            $buckets = [];
            foreach ($rows as $r) {
                $buckets[$svc->bucket((string) $r->item)][] = $r;
            }
            ksort($buckets);
            $share = [];
            $i = 0;
            foreach ($buckets as $name => $lines) {
                $who = $i % count($people);
                $share[$who]['buckets'][] = $name;
                foreach ($lines as $l) {
                    $share[$who]['lines'][] = $l;
                }
                $i++;
            }

            $market = trim((string) $tenant->setting('tender_sourcing_market', '')) ?: 'the market';
            $short = $this->clip((string) ($tender->organization ?: $tender->tender_no ?: $tender->title ?: ('Tender #' . $tender->id)), 40);
            $due = $this->sourcingDue($tender);
            $prio = $this->sourcingPriority($tender);
            $engine = app(\App\Services\Ops\TaskEngine::class);

            foreach ($share as $idx => $part) {
                $person = $people[$idx];
                $lines = $part['lines'] ?? [];
                if (! $lines) {
                    continue;
                }
                $title = '💵 ' . $market . ' prices — ' . count($lines) . ' item(s) · ' . $short;
                $res = $engine->assignAdHoc($tenant, $person, $this->clip($title, 170), $due, $prio);
                if (! is_array($res) || ! ($res['ok'] ?? false)) {
                    continue;
                }
                $out['tasks']++;
                $out['people'][] = (string) ($person->name ?: ('user ' . $person->id));
                $this->attachChecklist((int) ($res['task_id'] ?? 0), $lines, $part['buckets'] ?? []);
            }

            if ($out['tasks'] > 0) {
                try {
                    \App\Support\BotTrace::log($tenant->id, 'tender_sourcing', 'system', (string) $tender->id,
                        $out['tasks'] . ' pricing task(s) → ' . implode(', ', $out['people']) . ' · ' . $out['items'] . ' line(s)');
                } catch (\Throwable $e) {
                }
                $out['message'] = $out['items'] . ' item(s) split between ' . implode(' and ', $out['people'])
                    . ' — each has the list on their phone.'
                    . ($unmatched ? ' (Could not find: ' . implode(', ', $unmatched) . '.)' : '');
            }

            return $out;
        } catch (\Throwable $e) {
            \Log::warning('TenderTasks assignSourcing failed: ' . $e->getMessage());
            $out['message'] = 'Could not assign the pricing work.';

            return $out;
        }
    }

    /**
     * Resolve the names typed in Settings → Tenders → "Who sources prices" to real team members.
     *
     * Matches on name (either way round, so "Jayesh" finds "Jayesh Patel" and "Jayesh bhai" finds
     * "Jayesh") or on the last 9 digits of a phone number. Names that match nobody are returned so the
     * panel can say so out loud instead of silently assigning the work to the owner.
     *
     * @return array{0:array<int,object>, 1:array<int,string>}
     */
    private function pricingTeam(Tenant $tenant): array
    {
        $raw = trim((string) $tenant->setting('tender_pricing_team', ''));
        if ($raw === '') {
            return [[], []];
        }
        $wanted = array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $raw) ?: [])));
        if (! $wanted) {
            return [[], []];
        }
        $users = \App\Models\User::where('tenant_id', $tenant->id)->orderBy('id')->limit(200)->get();

        $people = [];
        $unmatched = [];
        $seen = [];
        foreach ($wanted as $w) {
            $needle = $this->nameKey($w);
            $digits = preg_replace('/\D+/', '', $w);
            $hit = null;
            foreach ($users as $u) {
                $uname = $this->nameKey((string) ($u->name ?? ''));
                $uphone = preg_replace('/\D+/', '', (string) ($u->phone ?? ''));
                $byName = $uname !== '' && $needle !== ''
                    && (str_contains($uname, $needle) || str_contains($needle, $uname));
                $byPhone = strlen((string) $digits) >= 9 && $uphone !== ''
                    && substr($uphone, -9) === substr((string) $digits, -9);
                if ($byName || $byPhone) {
                    $hit = $u;
                    break;
                }
            }
            if (! $hit) {
                $unmatched[] = $w;
                continue;
            }
            if (isset($seen[$hit->id])) {
                continue;
            }
            $seen[$hit->id] = true;
            $people[] = $hit;
        }

        return [$people, $unmatched];
    }

    /** Comparison key for a person's name: lowercase, honorifics and punctuation removed. */
    private function nameKey(string $s): string
    {
        $s = mb_strtolower(trim($s));
        // "bhai", "bhai ji", "mr", "sir" are how people are addressed here, not part of the record
        $s = preg_replace('/\b(bhai|bhaiya|ji|mr|mrs|ms|miss|sir|madam|uncle)\b/u', ' ', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9 ]+/u', ' ', $s) ?? $s;

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    /** Prices are wanted well before submission: deadline − 3 days, else next business day. */
    private function sourcingDue(Tender $tender): \Illuminate\Support\Carbon
    {
        $due = $this->nextBusinessDay();
        if ($tender->deadline) {
            $target = $tender->deadline->copy()->subDays(3)->setTime(17, 0);
            if ($target->isFuture()) {
                return $target->gt($due) ? $due : $target;
            }
            // already inside the last three days — get the prices today
            return now()->hour >= 14 ? now()->addHours(4) : now()->setTime(17, 0);
        }

        return $due;
    }

    private function sourcingPriority(Tender $tender): int
    {
        if (! $tender->deadline) {
            return 2;
        }
        $days = now()->startOfDay()->diffInDays($tender->deadline, false);

        return ($days >= 0 && $days <= 7) ? 3 : 2;
    }

    /**
     * Put the person's lines on the task as a tick-list they can work through in the market.
     * Silently skipped when the deployment's tasks table predates the checklist column.
     */
    private function attachChecklist(int $taskId, array $lines, array $buckets): void
    {
        if ($taskId <= 0) {
            return;
        }
        try {
            $table = (new \App\Models\OrderTask())->getTable();
            if (! \Illuminate\Support\Facades\Schema::hasColumn($table, 'checklist')) {
                return;
            }
            $task = \App\Models\OrderTask::find($taskId);
            if (! $task) {
                return;
            }
            $list = [];
            if ($buckets) {
                $list[] = ['label' => '📂 ' . implode(' · ', $buckets), 'done' => false];
            }
            foreach (array_slice($lines, 0, 60) as $l) {
                $label = trim((string) $l->item)
                    . ($l->qty ? ' — ' . $l->qty : '')
                    . ($l->brand ? ' [' . $l->brand . ']' : '');
                $list[] = ['label' => $this->clip($label, 110), 'done' => false];
            }
            if (count($lines) > 60) {
                $list[] = ['label' => '… and ' . (count($lines) - 60) . ' more on the Tenders → Items to price screen', 'done' => false];
            }
            $task->checklist = $list;
            $task->save();
        } catch (\Throwable $e) {
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
