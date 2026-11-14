<?php

namespace App\Console\Commands;

use App\Services\Travel\Passport\TravellerRetention;
use Illuminate\Console\Command;

/**
 * Delete the passport / ID card numbers of travellers on finished trips, after each agency's retention
 * period (TravellerRetention). Scheduled daily. --dry-run counts what would be deleted and changes nothing.
 */
class TravelPurgeDocumentsCommand extends Command
{
    protected $signature = 'travel:purge-documents {--dry-run}';

    protected $description = 'Delete travel document numbers after the retention period';

    public function handle(TravellerRetention $retention): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = $retention->run($dry);
        $this->info(($dry ? '[dry run] would delete ' : 'Deleted ') . "{$r['deleted']} document number(s) on {$r['trips']} trip(s); {$r['checked']} checked.");

        return self::SUCCESS;
    }
}
