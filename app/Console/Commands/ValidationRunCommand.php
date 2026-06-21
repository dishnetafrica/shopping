<?php

namespace App\Console\Commands;

use App\Services\Bot\Validation\ValidationFixtures;
use App\Services\Bot\Validation\ValidationService;
use Illuminate\Console\Command;

/**
 * Run platform validation against the built-in business-type fixtures.
 *   php artisan validation:run                 all types, saved
 *   php artisan validation:run --type=pharmacy
 *   php artisan validation:run --no-save       measure without persisting
 */
class ValidationRunCommand extends Command
{
    protected $signature = 'validation:run {--type= : snack|restaurant|pharmacy|hardware|grocery} {--no-save}';
    protected $description = 'Measure onboarding accuracy against ground-truth business fixtures.';

    public function handle(ValidationService $svc): int
    {
        $save  = ! $this->option('no-save');
        $types = $this->option('type') ? [$this->option('type')] : array_keys(ValidationFixtures::all());

        $this->line(sprintf('%-11s %5s %5s %5s %6s %6s %6s %5s', 'TYPE', 'MSGS', 'PROD', 'FAQ', 'PROD%', 'FAQ%', 'READY', 'ACC'));
        $this->line(str_repeat('-', 56));

        foreach ($types as $type) {
            $r = $svc->runFixture($type, $save);
            if (! $r) { $this->error("Unknown type: {$type}"); continue; }
            $this->line(sprintf('%-11s %5d %5d %5d %5d%% %5d%% %5d%% %4d%%',
                $type, $r['messages_scanned'], $r['products_found'], $r['faq_found'],
                $r['products_discovery_pct'], $r['faq_discovery_pct'], $r['readiness_score'], $r['accuracy_score']));
        }

        $this->newLine();
        $this->info($save ? 'Saved to validation_runs. Run validation:report for aggregates.' : 'Not saved (--no-save).');
        return self::SUCCESS;
    }
}
