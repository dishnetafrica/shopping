<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Bot\Validation\FieldValidationProgram;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Enroll a real business and run it through the field-validation workflow.
 *   php artisan field:run --tenant=2 --type=snack --truth=truth.json
 *   php artisan field:run --tenant=2 --type=snack --truth=truth.json --corrected=corrected.json
 *
 * --truth / --corrected are JSON files with keys: products[], faqs[], delivery_areas[],
 * languages[], offers, readiness. --corrected is the owner-reviewed ground truth.
 */
class FieldRunCommand extends Command
{
    protected $signature = 'field:run {--tenant=} {--type=} {--name=} {--truth=} {--corrected=}';
    protected $description = 'Run a real business through Discovery → Readiness → Validation and record field metrics.';

    public function handle(TenantContext $ctx, FieldValidationProgram $program): int
    {
        $tenant = Tenant::find((int) $this->option('tenant'));
        if (! $tenant) { $this->error('Tenant not found.'); return self::FAILURE; }
        $type = (string) $this->option('type');
        if ($type === '') { $this->error('--type required.'); return self::FAILURE; }

        $ctx->set($tenant->id);

        $truth = $this->json($this->option('truth'));
        if ($truth === null) { $this->error('--truth JSON file required and must be valid.'); return self::FAILURE; }

        $record = $program->enroll($tenant, $type, $this->option('name'));
        $record = $program->scan($record, $truth);
        $this->info("Scanned #{$record->id} — readiness {$record->readiness_score}%, actual accuracy {$record->actual_accuracy}%");
        $this->line("Messages {$record->messages_scanned}  Products {$record->products_found}  FAQs {$record->faq_found}  Delivery {$record->delivery_rules_found}");

        if ($corrected = $this->json($this->option('corrected'))) {
            $record = $program->recordOwnerReview($record, $corrected, true);
            $this->info("Owner review — approved accuracy {$record->owner_approved_accuracy}%, edits {$record->owner_edits_required} ({$record->owner_corrections_pct}%), time-to-go-live {$record->time_to_go_live_min} min");
        } else {
            $this->line('No --corrected supplied; owner review pending.');
        }

        return self::SUCCESS;
    }

    private function json(?string $path): ?array
    {
        if (! $path) return null;
        if (! is_file($path)) { $this->error("File not found: {$path}"); return null; }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }
}
