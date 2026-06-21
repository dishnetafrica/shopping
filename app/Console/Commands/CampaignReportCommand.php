<?php

namespace App\Console\Commands;

use App\Services\Bot\Validation\CampaignReportHtml;
use App\Services\Bot\Validation\CampaignService;
use Illuminate\Console\Command;

/**
 * Platform Validation Campaign reporting.
 *   php artisan campaign:leaderboard
 *   php artisan campaign:report --month=2026-06 --html=monthly.html
 */
class CampaignReportCommand extends Command
{
    protected $signature = 'campaign:report {--month= : YYYY-MM (default current)} {--html= : write HTML report} {--leaderboard : show only the leaderboard}';
    protected $description = 'Business-type leaderboard + monthly validation report from field results.';

    public function handle(CampaignService $svc): int
    {
        if ($this->option('leaderboard')) {
            $this->renderLeaderboard($svc->leaderboard());
            return self::SUCCESS;
        }

        $report = $svc->monthlyReport($this->option('month'));
        $this->info("Monthly Validation Report — {$report['period']} ({$report['businesses']} businesses)");
        $this->newLine();

        $this->renderLeaderboard($report['leaderboard']);

        $q = $report['questions'];
        $this->newLine();
        $this->line('Questions:');
        $this->line('  1. Easiest type        : ' . ($q['easiest_type'] ?? '—') . " (ease {$q['easiest_score']})");
        $this->line('  2. Most corrections    : ' . ($q['most_corrections_type'] ?? '—') . " ({$q['most_corrections_pct']}%)");
        $this->line('  3. Messages needed     : ~' . $q['messages_needed']);
        $this->line('  4. Avg readiness       : ' . $q['avg_readiness'] . '%');
        $this->line('  5. Best predictor      : ' . ($q['best_predictor']['feature'] ?? '—') . ' (r=' . ($q['best_predictor']['correlation'] ?? 0) . ')');

        $this->newLine();
        $this->line($report['verdict']['statement'] ?? '');

        if ($path = $this->option('html')) {
            file_put_contents($path, CampaignReportHtml::render($report));
            $this->info("HTML report written to {$path}");
        }

        return self::SUCCESS;
    }

    private function renderLeaderboard(array $board): void
    {
        $this->line(sprintf('%-4s %-11s %6s %8s %10s %6s %8s %5s', 'RANK', 'TYPE', 'SHOPS', 'ACCUR', 'CORRECT', 'TIME', 'READY', 'EASE'));
        $this->line(str_repeat('-', 64));
        $rank = 1;
        foreach ($board as $b) {
            $this->line(sprintf('#%-3d %-11s %6d %7d%% %9d%% %5dm %7d%% %5d',
                $rank++, $b['business_type'], $b['businesses'], $b['avg_accuracy'],
                $b['avg_corrections'], $b['avg_time'], $b['avg_readiness'], $b['ease_score']));
        }
        if (! $board) $this->warn('No reviewed businesses yet.');
    }
}
