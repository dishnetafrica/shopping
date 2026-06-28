<?php
namespace App\Console\Commands;

use App\Apps\Orders\Experiment\ReviewService;
use App\Models\ShadowDiff;
use Illuminate\Console\Command;

/**
 * CLI triage for live-vs-shadow disagreements (until the review UI exists). List today's diffs, or
 * act on one: accept (shadow better) / reject (live right) / bug.
 *   php artisan orders:diffs
 *   php artisan orders:diffs --id=128 --action=accept
 */
class OrderDiffsCommand extends Command
{
    protected $signature = 'orders:diffs {--date=} {--id=} {--action=}';
    protected $description = 'List or review live-vs-shadow disagreements';

    public function handle(ReviewService $review): int
    {
        if ($id = $this->option('id')) {
            $action = (string) $this->option('action');
            match ($action) {
                'accept' => $review->accept((int) $id),
                'reject' => $review->reject((int) $id),
                'bug'    => $review->markBug((int) $id),
                default  => $this->error("unknown action '{$action}' (accept|reject|bug)"),
            };
            $this->info("diff {$id}: {$action}");
            return self::SUCCESS;
        }

        $date = $this->option('date') ?: date('Y-m-d');
        $rows = ShadowDiff::whereDate('created_at', $date)->where('status', 'new')->orderByDesc('id')->limit(50)->get();
        $this->info("Shadow disagreements for {$date}: {$rows->count()} (regressions marked ⚠)");
        foreach ($rows as $d) {
            $this->line(sprintf('  #%-5d %s  [%s]  %s', $d->id, $d->regression ? '⚠' : ' ', $d->reasons, mb_substr((string) $d->message, 0, 50)));
        }
        return self::SUCCESS;
    }
}
