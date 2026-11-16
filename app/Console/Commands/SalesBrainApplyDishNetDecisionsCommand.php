<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\SalesKnowledgeEntry;
use App\Models\SalesKnowledgeVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\ProductWriteService;
use App\Services\Sales\Brain\DishNetBotImport;
use App\Services\Sales\Brain\DishNetDecisions;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Sales\SalesOwnership;
use Illuminate\Console\Command;

/**
 * Go live with DishNet management's decisions of 16 Nov 2026 (DishNetDecisions): for each imported draft,
 * write the decided wording, record the decided answer against every open question, then approve and
 * publish it — all through SalesBrain, as the named owner/manager, so the history shows who approved.
 * Then switch on the imported products (ProductWriteService::activate).
 *
 * A draft whose text or questions are not exactly as expected is left as a draft and reported; nothing is
 * guessed. "Questions customers ask" stays a draft (management writes those answers). --dry-run changes
 * nothing. Re-running skips what is already live.
 *
 *   php artisan sales-brain:apply-dishnet-decisions 31 --user=<owner user id> --dry-run
 *   php artisan sales-brain:apply-dishnet-decisions 31 --user=<owner user id>
 */
class SalesBrainApplyDishNetDecisionsCommand extends Command
{
    protected $signature = 'sales-brain:apply-dishnet-decisions {tenant} {--user= : the owner/manager approving (user id)} {--dry-run}';

    protected $description = 'Apply DishNet management decisions (16 Nov 2026): edit, answer, approve and publish the imported drafts; switch on the imported products';

    public function handle(SalesBrain $brain, ProductWriteService $products): int
    {
        $tenant = Tenant::find((int) $this->argument('tenant'));
        $user = $tenant ? User::where('tenant_id', $tenant->id)->find((int) $this->option('user')) : null;
        if (! $tenant || ! SalesOwnership::enabled($tenant)) {
            $this->error('No such Internet-provider business.');

            return self::FAILURE;
        }
        if (! $user || ! SalesOwnership::canManage($user)) {
            $this->error('--user must be an owner/admin or manager of this business (it is recorded as the approver).');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        $this->info(($dry ? '[DRY RUN — nothing is changed] ' : '') . "Approving as {$user->name} for {$tenant->name}");
        $problems = 0;

        foreach (DishNetDecisions::ENTRIES as $entry) {
            [$topic, $key] = explode('/', $entry);
            $e = SalesKnowledgeEntry::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('topic', $topic)->where('key', $key)->first();
            $v = $e ? SalesKnowledgeVersion::withoutGlobalScopes()->where('entry_id', $e->id)->whereIn('status', ['draft', 'approved'])->orderByDesc('version')->first() : null;
            if (! $v) {
                $live = $e && SalesKnowledgeVersion::withoutGlobalScopes()->where('entry_id', $e->id)->where('status', 'published')->exists();
                $this->line("  {$entry}: " . ($live ? 'already live — skipped' : 'NOT FOUND — run the imports first'));
                $problems += $live ? 0 : 1;
                continue;
            }

            // 1. The decided wording.
            $body = null;
            $data = null;
            if ($topic === 'terms') {
                $r = DishNetDecisions::applyTerms($key, (array) $v->data);
                $data = $r['data'] ?? null;
            } else {
                $r = DishNetDecisions::bodyChanges($entry) ? DishNetDecisions::applyBody($entry, (string) $v->body) : ['ok' => true, 'body' => null];
                $body = $r['body'] ?? null;
            }
            // 2. A decided answer for every open question.
            $answers = [];
            foreach (array_values((array) $v->open_questions) as $q) {
                $answers[] = DishNetDecisions::answerFor((string) $q);
            }
            if (! $r['ok'] || in_array(null, $answers, true)) {
                $this->warn("  {$entry}: LEFT AS DRAFT — " . ($r['ok'] ? 'an open question has no decision' : $r['error']));
                $problems++;
                continue;
            }
            $changed = $topic === 'terms' ? $data !== $v->data : $body !== null;
            $this->line("  {$entry}: v{$v->version}" . ($changed ? ' wording updated,' : '') . ' ' . count($answers) . ' question(s) answered → approve + publish');
            if ($dry) {
                continue;
            }
            if ($v->status === 'draft') {
                if ($changed) {
                    $ok = $topic === 'terms'
                        ? $brain->editDraft($tenant, $user, $v->id, null, $data, 'Management decisions ' . DishNetDecisions::DECIDED)
                        : $brain->editDraft($tenant, $user, $v->id, $body, null, 'Management decisions ' . DishNetDecisions::DECIDED);
                    if (! $ok['ok']) {
                        $this->warn("    edit refused: {$ok['error']}");
                        $problems++;
                        continue;
                    }
                }
                foreach ($answers as $a) {
                    $brain->resolveQuestion($tenant, $user, $v->id, 0, $a);
                }
                $ap = $brain->approve($tenant, $user, $v->id);
                if (! $ap['ok']) {
                    $this->warn("    approval refused: {$ap['error']}");
                    $problems++;
                    continue;
                }
            }
            $pub = $brain->publish($tenant, $user, $v->id);
            if (! $pub['ok']) {
                $this->warn("    publish refused: {$pub['error']}");
                $problems++;
            }
        }

        $this->info('Products:');
        foreach (DishNetBotImport::products() as $p) {
            $row = Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereRaw('LOWER(name) = ?', [mb_strtolower($p['name'])])->first();
            if (! $row) {
                $this->line("  {$p['name']}: not found");
                continue;
            }
            if ($row->active) {
                $this->line("  {$p['name']}: already on");
                continue;
            }
            $this->line("  {$p['name']}: switch on (USD " . (float) $row->price . ')');
            if (! $dry) {
                $products->activate($tenant, $row);
            }
        }

        $this->info($dry ? 'Dry run finished — run again without --dry-run to apply.'
            : ($problems ? "Finished with {$problems} item(s) left for review (see above)." : 'Done. Published and live. "Questions customers ask" stays a draft until its answers are written.'));
        $this->line('Hardware (Mini, Standard, routers…) still needs its real stock entered before the bot quotes it.');

        return $problems ? self::FAILURE : self::SUCCESS;
    }
}
