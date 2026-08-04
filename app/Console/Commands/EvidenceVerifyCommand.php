<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\CustomerPayment;
use App\Models\OrderCost;
use App\Services\Evidence\EvidenceService;
use App\Services\Evidence\MissingEvidence;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Prove the evidence archive is intact — and adopt the receipts that predate it.
 *
 *   php artisan evidence:verify --tenant=17                  every file still hashes correctly?
 *   php artisan evidence:verify --tenant=17 --gaps           what has no document at all
 *   php artisan evidence:verify --tenant=17 --import-legacy  adopt old receipt_path files
 *
 * The verify pass is the point of storing a sha256 at upload. A file that has been swapped,
 * truncated by a failed copy, or lost with a volume no longer matches its recorded hash, and
 * this says so by name — which is the difference between an archive you can stand behind and
 * a folder of images you hope are the right ones.
 */
class EvidenceVerifyCommand extends Command
{
    protected $signature = 'evidence:verify
        {--tenant= : tenant id (required)}
        {--gaps : list transactions with no document instead of verifying files}
        {--import-legacy : create evidence rows for receipts uploaded before v4.33}
        {--limit=40 : how many rows to print}';

    protected $description = 'Verify transaction evidence is intact, list gaps, or adopt pre-v4.33 receipts';

    public function handle(): int
    {
        $tenantId = (int) $this->option('tenant');
        if ($tenantId <= 0) {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }
        if (! EvidenceService::available()) {
            $this->error('The attachments table does not exist — run php artisan migrate.');

            return self::FAILURE;
        }
        app(TenantContext::class)->asSuperAdmin(true);

        if ($this->option('import-legacy')) {
            return $this->importLegacy($tenantId);
        }
        if ($this->option('gaps')) {
            return $this->gaps($tenantId);
        }

        return $this->verify($tenantId);
    }

    private function verify(int $tenantId): int
    {
        $total = Attachment::where('tenant_id', $tenantId)->whereNull('detached_at')->count();
        if ($total === 0) {
            $this->warn('No evidence on file for this tenant yet.');

            return self::SUCCESS;
        }
        $this->line("Checking {$total} documents…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $ok = 0; $bad = [];
        Attachment::where('tenant_id', $tenantId)->whereNull('detached_at')->orderBy('id')
            ->chunkById(200, function ($rows) use (&$ok, &$bad, $bar) {
                foreach ($rows as $a) {
                    $r = EvidenceService::verify($a);
                    if ($r['ok']) {
                        $ok++;
                    } else {
                        $bad[] = [$a->id, mb_substr((string) $a->original_name, 0, 34),
                            $a->subject_type . '#' . $a->subject_id, $r['reason']];
                    }
                    $bar->advance();
                }
            });
        $bar->finish();
        $this->newLine(2);

        if ($bad) {
            $this->table(['id', 'document', 'attached to', 'problem'], array_slice($bad, 0, (int) $this->option('limit')));
            $this->error(count($bad) . ' of ' . $total . ' documents FAILED verification.');
            $this->line('"file missing from storage" usually means the uploads volume was lost or never');
            $this->line('mounted. Restore the files archive from a backup taken with --files.');

            return self::FAILURE;
        }

        $this->info("All {$ok} documents verified — every file still matches the fingerprint recorded when it was uploaded.");

        return self::SUCCESS;
    }

    private function gaps(int $tenantId): int
    {
        $res = MissingEvidence::report($tenantId, ['only' => 'missing', 'limit' => (int) $this->option('limit')]);
        $s = $res['summary'];

        $this->line(sprintf('Checked %d transactions · %d documented · %d with NO document',
            $s['checked'], $s['with_evidence'], $s['missing']));
        $this->line('Value carrying no evidence: $' . number_format($s['missing_usd'], 2));
        $this->newLine();

        if (! $res['rows']) {
            $this->info('Nothing missing.');

            return self::SUCCESS;
        }
        $this->table(['date', 'what', 'who', 'USD', 'old file?'], array_map(fn ($r) => [
            $r['date'], $r['what'], mb_substr((string) $r['party'], 0, 24),
            number_format($r['usd'], 2), $r['legacy_receipt'] ? 'yes' : '',
        ], $res['rows']));
        $this->line('Largest first. Attach them on the Evidence screen (Finance → Evidence).');
        $this->line('If "old file?" says yes, run --import-legacy to adopt those automatically.');

        return self::SUCCESS;
    }

    /**
     * Adopt receipts uploaded before this feature existed.
     *
     * `order_costs.receipt_path` and `customer_payments.receipt_path` already hold real files.
     * Without this they would show as "no evidence" forever, and the gap report — the whole
     * point of the feature — would be wrong from the first day.
     */
    private function importLegacy(int $tenantId): int
    {
        $made = 0; $skipped = 0; $gone = 0;

        $adopt = function ($row, string $slug) use ($tenantId, &$made, &$skipped, &$gone) {
            $path = trim((string) ($row->receipt_path ?? ''));
            if ($path === '') {
                return;
            }
            $already = Attachment::where('tenant_id', $tenantId)
                ->where('subject_type', $slug)->where('subject_id', (int) $row->id)->exists();
            if ($already) {
                $skipped++;

                return;
            }
            if (! Storage::disk('public')->exists($path)) {
                $gone++;

                return;
            }
            $bytes = (string) Storage::disk('public')->get($path);
            $name = basename($path);
            $r = EvidenceService::attach($tenantId, $slug, (int) $row->id, $bytes, $name,
                'receipt', null, 'imported from before v4.33', 'Uploaded before the evidence feature existed');
            if ($r['ok']) {
                $made++;
            } else {
                $skipped++;
            }
        };

        if (Schema::hasTable('order_costs') && Schema::hasColumn('order_costs', 'receipt_path')) {
            OrderCost::where('tenant_id', $tenantId)->whereNotNull('receipt_path')
                ->where('receipt_path', '!=', '')->orderBy('id')
                ->chunkById(200, function ($rows) use ($adopt) {
                    foreach ($rows as $c) {
                        $adopt($c, MissingEvidence::slugFor((string) $c->stage));
                    }
                });
        }
        if (Schema::hasTable('customer_payments') && Schema::hasColumn('customer_payments', 'receipt_path')) {
            CustomerPayment::where('tenant_id', $tenantId)->whereNotNull('receipt_path')
                ->where('receipt_path', '!=', '')->orderBy('id')
                ->chunkById(200, function ($rows) use ($adopt) {
                    foreach ($rows as $p) {
                        $adopt($p, 'payment');
                    }
                });
        }

        $this->info("Adopted {$made} existing receipts as evidence.");
        if ($skipped) {
            $this->line("{$skipped} already had evidence attached — left alone.");
        }
        if ($gone) {
            $this->warn("{$gone} rows point at a receipt file that is no longer in storage.");
            $this->line('Those uploads were lost before this ran — most likely with a volume that was not persisted.');
        }

        return self::SUCCESS;
    }
}
