<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\SalesKnowledgeEntry;
use App\Models\SalesKnowledgeVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Sales\Brain\RetireMention;
use App\Services\Sales\Brain\SalesBrain;
use App\Services\Sales\SalesOwnership;
use Illuminate\Console\Command;

/**
 * Retire a discontinued plan or product everywhere the bot and the quotations read it (DishNet, 21 Nov 2026:
 * "our system gives quotations for Starlink Roam but that plan is no more — remove it and update the prompt").
 *
 *   php artisan sales:retire-plan 31 Roam --product="Starlink Roam Plan"            show what would change
 *   php artisan sales:retire-plan 31 Roam --product="Starlink Roam Plan" --by=12 --apply
 *
 * 1. Catalogue: each --product (exact name, any case) is switched off and archived — never quoted, never in
 *    the bot's product list again. Archived, not deleted: past quotations and orders keep their lines.
 *    Any other offered product whose description mentions the word keeps the product, loses the word.
 * 2. Sales knowledge: every PUBLISHED text entry that mentions the word gets a new version without it
 *    (RetireMention), approved and published by --by — an owner or manager — through SalesBrain, the one
 *    writer, so the old version is retired and every step is in the audit log. An entry with a draft
 *    already waiting is left alone and listed: finish or reject that draft first.
 */
class SalesRetirePlanCommand extends Command
{
    protected $signature = 'sales:retire-plan {tenant} {word : the plan word to take out of sales knowledge, e.g. Roam}
        {--product=* : exact catalogue name(s) to switch off and archive}
        {--by= : id of the owner or manager approving and publishing the knowledge changes}
        {--apply : make the changes (without it, only show them)}';

    protected $description = 'Retire a discontinued plan: archive its catalogue items and remove it from published sales knowledge';

    public function handle(SalesBrain $brain): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        $word = trim((string) $this->argument('word'));
        if (! $t || $word === '') {
            $this->error('No such business, or no word given.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $by = null;
        if ($apply) {
            $by = User::where('tenant_id', $t->id)->find((int) $this->option('by'));
            if (! $by || ! SalesOwnership::canManage($by)) {
                $this->error('--by must be the id of an owner or manager of this business: they approve and publish the knowledge changes.');

                return self::FAILURE;
            }
        }
        app(\App\Support\TenantContext::class)->set((int) $t->id);
        $this->line(($apply ? 'Retiring' : 'Would retire') . " \"{$word}\" for {$t->name}:");

        // 1. Catalogue
        foreach ((array) $this->option('product') as $name) {
            $rows = Product::withoutGlobalScopes()->where('tenant_id', $t->id)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim((string) $name))])->get();
            if ($rows->isEmpty()) {
                $this->warn("  catalogue: no product named \"{$name}\"");
            }
            foreach ($rows as $p) {
                $this->line("  catalogue: \"{$p->name}\" (id {$p->id}, {$p->price}) — " . ($p->active || ! $p->archived_at ? ($apply ? 'switched off and archived' : 'would be switched off and archived') : 'already off'));
                if ($apply) {
                    $p->active = false;
                    $p->archived_at ??= now();
                    $p->save();
                }
            }
        }

        // Other products the bot still offers that mention it ("Car Adaptor — For roaming / mobile use"): the
        // description loses the retired word, the product stays.
        $retired = array_map(fn ($n) => mb_strtolower(trim((string) $n)), (array) $this->option('product'));
        foreach (Product::withoutGlobalScopes()->where('tenant_id', $t->id)->where('active', true)->whereNull('archived_at')->get() as $p) {
            if (in_array(mb_strtolower((string) $p->name), $retired, true) || ! RetireMention::mentions((string) $p->description, $word)) {
                continue;
            }
            $desc = RetireMention::scrub((string) $p->description, $word);
            $this->line("  catalogue: \"{$p->name}\" description: \"{$p->description}\" → \"{$desc}\"");
            if ($apply) {
                $p->description = $desc;
                $p->save();
            }
        }

        // 2. Published sales knowledge
        $published = SalesKnowledgeVersion::withoutGlobalScopes()->where('tenant_id', $t->id)->where('status', 'published')->get();
        $found = 0;
        foreach ($published as $ver) {
            $entry = SalesKnowledgeEntry::withoutGlobalScopes()->find($ver->entry_id);
            if (! $entry) {
                continue;
            }
            $body = (string) $ver->body;
            // Title, text and structured data (quotation terms print from their items): Codex on #174.
            if (! RetireMention::entryMentions((string) $entry->title, $body, $ver->data, $word)) {
                continue;
            }
            $found++;
            $title = trim(RetireMention::scrub((string) $entry->title, $word));
            $title = $title !== '' ? rtrim($title, '.') : (string) $entry->title;
            $new = RetireMention::scrub($body, $word);
            $data = RetireMention::scrubData($ver->data, $word);
            // Terms whose text is just their numbered items: let the items write it again (SalesBrain does).
            if ($entry->topic === 'terms' && is_array($ver->data) && trim($body) === \App\Services\Sales\Brain\Knowledge::termsText($ver->data)) {
                $new = is_array($data) && ! empty($data['items']) ? \App\Services\Sales\Brain\Knowledge::termsText($data) : '';
            }
            $empty = trim($new) === '' && (! is_array($data) || $data === [] || (array_key_exists('items', $data) && empty($data['items'])));
            $this->line("  knowledge: {$entry->topic}/{$entry->key} \"{$entry->title}\"" . ($title !== $entry->title ? " → title \"{$title}\"" : ''));
            $old = explode("\n", $body);
            $now = explode("\n", $new);
            foreach (array_diff($old, $now) as $l) {
                $this->line('    - ' . $l);
            }
            foreach (array_diff($now, $old) as $l) {
                $this->line('    + ' . $l);
            }
            if ($data !== $ver->data) {
                $this->line('    (its structured items change too)');
            }
            if ($empty) {
                $this->line('    nothing is left: the entry ' . ($apply ? 'is retired' : 'would be retired') . ', not republished');
            }
            if (! $apply) {
                continue;
            }
            if ($empty) {
                $r = $brain->retire($t, $by, (int) $ver->id, "\"{$word}\" discontinued");
                ($r['ok'] ?? false) ? $this->info('    retired') : $this->warn('    retire refused: ' . ($r['message'] ?? $r['error'] ?? '?'));
                continue;
            }
            $r = $brain->startVersion($t, $by, $entry->topic, $entry->key, $title, $new, $data, "retire:{$word}", [],
                "Removed \"{$word}\": discontinued.");
            if (! ($r['ok'] ?? false)) {
                $this->warn('    not changed: ' . ($r['message'] ?? $r['error'] ?? 'refused'));
                continue;
            }
            foreach (['approve', 'publish'] as $step) {
                $s = $brain->{$step}($t, $by, (int) $r['version_id']);
                if (! ($s['ok'] ?? false)) {
                    $this->warn("    {$step} refused: " . ($s['message'] ?? $s['error'] ?? '?') . ' — the new version waits in Sales Brain');
                    continue 2;
                }
            }
            $this->info("    published version {$r['version']}; the old one is retired");
        }
        if ($found === 0) {
            $this->line("  knowledge: no published entry mentions \"{$word}\"");
        }
        if (! $apply) {
            $this->line('Nothing changed. Run again with --by=<owner or manager id> --apply to make these changes.');
        }

        return self::SUCCESS;
    }
}
