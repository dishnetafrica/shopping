<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tender\TenderScout;
use Illuminate\Console\Command;

/**
 * v4.19 TenderBot — daily forum sweep for every tenant that switched tender watching on.
 * Scheduled in routes/console.php; also runnable by hand: php artisan tenders:scan
 */
class TendersScanCommand extends Command
{
    protected $signature = 'tenders:scan {--tenant= : only this tenant id}';

    protected $description = 'Scan the configured tender forum(s) for new opportunities and analyse them';

    public function handle(): int
    {
        $q = Tenant::query();
        if ($this->option('tenant')) {
            $q->where('id', (int) $this->option('tenant'));
        }
        $scout = new TenderScout();
        foreach ($q->get() as $tenant) {
            try {
                if (! (bool) $tenant->setting('tender_enabled', false)) {
                    continue;
                }
                $res = $scout->scan($tenant);
                $this->info(sprintf('[%d] %s — fresh:%d new:%d analysed:%d %s',
                    $tenant->id, $tenant->name, $res['scanned'], $res['new'], count($res['analysed']), $res['note']));
            } catch (\Throwable $e) {
                $this->warn("[{$tenant->id}] scan failed: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
