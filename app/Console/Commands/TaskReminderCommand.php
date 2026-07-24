<?php

namespace App\Console\Commands;

use App\Models\OrderTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\JobToken;
use App\Support\MessageLog;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Twice-daily task reminders (scheduled 09:00 & 14:00 Africa/Juba in routes/console.php).
 *
 * For each tenant that has WhatsApp linked AND the `task_reminders` setting ON, this WhatsApps every staff
 * member a SINGLE consolidated digest of their own OPEN tasks — overdue first — so someone with several
 * pending jobs gets one message, not many (the "Daily Jobs Update" shape). Opt-in per tenant; only people
 * who actually have open tasks AND a phone number are messaged (no "you have 0 tasks" spam).
 *
 * It is a stateless nudge: it simply reflects the current open tasks each time it runs, so the 9am and 2pm
 * fires both send a fresh, up-to-date list. `withoutOverlapping` on the schedule prevents a double-send.
 */
class TaskReminderCommand extends Command
{
    protected $signature = 'tasks:remind
        {--tenant= : limit to one tenant id or slug}
        {--dry : print who would be reminded without sending}';

    protected $description = 'WhatsApp each staff member a digest of their open tasks (twice daily).';

    public function handle(TenantContext $ctx, WhatsAppManager $wa): int
    {
        $only = (string) $this->option('tenant');
        $dry  = (bool) $this->option('dry');
        $people = 0; $sent = 0;

        $q = Tenant::query()->whereNotNull('whatsapp_instance')->orderBy('id');
        if ($only !== '') {
            $q->where(fn ($w) => $w->where('id', ctype_digit($only) ? (int) $only : 0)->orWhere('slug', $only));
        }

        foreach ($q->get() as $t) {
            // Opt-in per tenant. --dry still previews (it never sends) so you can see who WOULD be reminded
            // before switching the toggle on.
            if (! $dry && ! (bool) $t->setting('task_reminders', false)) {
                continue;
            }
            $ctx->set($t->id); // scope OrderTask to this tenant

            $byUser = OrderTask::whereIn('status', OrderTask::ACTIVE)
                ->whereNotNull('owner_user_id')
                ->orderBy('due_at')
                ->limit(3000)->get()
                ->groupBy('owner_user_id');

            if ($byUser->isEmpty()) {
                continue;
            }
            $users = User::where('tenant_id', $t->id)->get()->keyBy('id');

            foreach ($byUser as $uid => $list) {
                $u = $users->get($uid);
                $phone = $u ? trim((string) $u->phone) : '';
                if ($phone === '') {
                    continue; // can't WhatsApp someone with no number
                }
                $people++;

                if ($dry) {
                    $this->line(sprintf('[dry] %s -> %s (%s): %d open task(s)', $t->name, (string) $u->name, $phone, $list->count()));
                    continue;
                }

                $link = url('/jobs/' . JobToken::mint((int) $t->id, (int) $uid));
                $msg = $this->message($t, (string) $u->name, $list, $link);
                try {
                    $wa->forTenant($t)->sendText($t->whatsapp_instance, $phone, $msg);
                    MessageLog::record($t->id, $phone, $t->whatsapp_instance, 'out', 'system', $msg);
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('tasks:remind send_failed', ['tenant' => $t->id, 'user' => $uid, 'err' => $e->getMessage()]);
                }
            }
        }

        $this->info("tasks:remind — {$people} assignee(s) with open tasks, {$sent} messaged" . ($dry ? ' (dry run)' : '') . '.');
        return self::SUCCESS;
    }

    /** Build the per-person digest (overdue first). Mirrors the Tasks screen's due format + overdue rule. */
    private function message(Tenant $t, string $name, $list, string $link): string
    {
        $first = trim((string) strtok(trim($name), ' '));
        if ($first === '') {
            $first = 'there';
        }

        $overdue = 0; $open = 0; $lines = [];
        foreach ($list as $task) {
            $isOver = (bool) $task->isOverdue();
            if ($isOver) {
                $overdue++;
            } else {
                $open++;
            }
            $due = $task->due_at ? $task->due_at->format('M j, H:i') : 'no due date';
            $dueLabel = $task->due_at ? ($isOver ? ('overdue · ' . $due) : ('due ' . $due)) : $due;
            $lines[] = ($isOver ? "\u{23F3} " : "\u{1F7E1} ") . '*' . $this->clip((string) $task->title, 70) . '* — ' . $dueLabel;
        }
        $total = $overdue + $open;

        $show = array_slice($lines, 0, 8);
        $more = $total - count($show);
        $body = implode("\n", $show) . ($more > 0 ? "\n…and {$more} more" : '');

        return "Hi {$first},\n\n"
            . "Here are your tasks for today:\n"
            . "\u{23F3} Overdue: {$overdue}\n"
            . "\u{1F7E1} Open: {$open}\n"
            . "\u{1F4CB} Total: {$total}\n\n"
            . $body . "\n\n"
            . "\u{1F517} Open & update your tasks (no login): {$link}\n\n"
            . "Please work through your list, starting with anything overdue. Have a productive day! \u{1F6E0}\n"
            . '— ' . $t->name . ' Operations';
    }

    private function clip(string $s, int $n): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        return mb_strlen($s) > $n ? (mb_substr($s, 0, $n - 1) . '…') : $s;
    }
}
