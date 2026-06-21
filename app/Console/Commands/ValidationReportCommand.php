<?php

namespace App\Console\Commands;

use App\Services\Bot\Validation\ValidationService;
use Illuminate\Console\Command;

/**
 * Aggregate accuracy report across stored validation runs, answering the platform questions:
 * how many messages, how fast, % products & FAQs discovered, can it go live.
 *   php artisan validation:report
 *   php artisan validation:report --type=grocery
 */
class ValidationReportCommand extends Command
{
    protected $signature = 'validation:report {--type=}';
    protected $description = 'Aggregate platform-validation accuracy report.';

    public function handle(ValidationService $svc): int
    {
        $rep = $svc->accuracyReport($this->option('type') ?: null);
        if (($rep['runs'] ?? 0) === 0) {
            $this->warn('No validation runs found. Run validation:run first.');
            return self::SUCCESS;
        }

        $this->info("Validation accuracy report — {$rep['runs']} run(s)");
        $this->line("Avg messages scanned : {$rep['avg_messages']}");
        $this->line("Avg scan time        : {$rep['avg_scan_ms']} ms");
        $this->line("Avg products found   : {$rep['avg_products_found']}");
        $this->line("Avg readiness        : {$rep['avg_readiness']}%");
        $this->line("Avg accuracy         : {$rep['avg_accuracy']}%");
        $this->line("Go-live ready (>=70%): {$rep['go_live_ready']}/{$rep['runs']}");
        $this->line("In 70-90 target band : {$rep['in_target_band_70_90']}/{$rep['runs']}");

        if (! empty($rep['by_type'])) {
            $this->newLine();
            $this->line('By type:');
            foreach ($rep['by_type'] as $type => $b) {
                $this->line(sprintf('  %-11s runs %d  readiness %d%%  accuracy %d%%', $type, $b['runs'], $b['avg_readiness'], $b['avg_accuracy']));
            }
        }

        return self::SUCCESS;
    }
}
