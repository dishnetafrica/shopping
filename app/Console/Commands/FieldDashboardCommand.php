<?php

namespace App\Console\Commands;

use App\Services\Bot\Validation\FieldDashboardHtml;
use App\Services\Bot\Validation\FieldValidationProgram;
use Illuminate\Console\Command;

/**
 * Field Validation dashboard — the Validation Results table, success criteria, and final verdict.
 *   php artisan field:dashboard
 *   php artisan field:dashboard --html=field_validation.html
 */
class FieldDashboardCommand extends Command
{
    protected $signature = 'field:dashboard {--html= : write a standalone HTML dashboard to this path}';
    protected $description = 'Show field-validation results and whether the cohort meets success criteria.';

    public function handle(FieldValidationProgram $program): int
    {
        $d = $program->dashboard();
        $rows = $d['rows']; $sum = $d['summary']; $verdict = $d['verdict'];

        $this->line(sprintf('%-18s %-11s %-9s %8s %8s %8s %9s', 'BUSINESS', 'TYPE', 'STATUS', 'READY', 'ACCUR', 'TIME', 'CORRECT'));
        $this->line(str_repeat('-', 76));
        foreach ($rows as $r) {
            $acc  = $r['owner_approved_accuracy'] ?: $r['actual_accuracy'];
            $time = $r['time_to_go_live_min'] === null ? '—' : ($r['time_to_go_live_min'] . 'm');
            $this->line(sprintf('%-18s %-11s %-9s %7d%% %7d%% %8s %7d%%',
                mb_substr((string) ($r['business_name'] ?: '—'), 0, 18), $r['business_type'], $r['status'],
                $r['readiness_score'], $acc, $time, $r['owner_corrections_pct']));
        }

        $this->newLine();
        if (($sum['businesses'] ?? 0) > 0) {
            $c = $sum['criteria'];
            $mark = fn ($b) => $b ? 'PASS' : 'FAIL';
            $this->info("Cohort: {$sum['businesses']} reviewed businesses");
            $this->line("Avg accuracy    : {$sum['avg_accuracy']}%  (target ≥80)  [{$mark($c['accuracy']['pass'])}]");
            $this->line("Avg time-to-live: {$sum['avg_time_to_go_live']} min (target ≤30)  [{$mark($c['time']['pass'])}]");
            $this->line("Avg corrections : {$sum['avg_corrections']}% (target ≤20)  [{$mark($c['corrections']['pass'])}]");
            $this->newLine();
            $this->line($verdict['statement']);
        } else {
            $this->warn('No reviewed businesses yet. Run field:run with --corrected to complete reviews.');
        }

        if ($path = $this->option('html')) {
            file_put_contents($path, FieldDashboardHtml::render($d));
            $this->info("HTML dashboard written to {$path}");
        }

        return self::SUCCESS;
    }
}
