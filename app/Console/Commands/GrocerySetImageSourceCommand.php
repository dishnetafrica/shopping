<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Operator-only gate: nakki karo ke ek store kya source store(s) mathi images borrow kari shake.
 * Aa setting (settings.image_sources) seller panel mathi set NATHI thai shakti — fakt tame (operator)
 * aa command thi set karo. Pachi e store na staff panel button thi missing images jate bhari shake,
 * fakt aa designated source(s) mathi (third-party tenants isolated rahe).
 *
 *   php artisan grocery:image-source --target=2 --source=1        # store 2 ne store 1 mathi allow
 *   php artisan grocery:image-source --target=2 --source=1,3      # ek karta vadhare sources
 *   php artisan grocery:image-source --target=2 --clear           # allow kadi nakho
 *   php artisan grocery:image-source --target=2                   # current setting batavo
 */
class GrocerySetImageSourceCommand extends Command
{
    protected $signature = 'grocery:image-source {--target= : je store mate (tenant id)} {--source= : allowed source tenant id(s), comma se} {--clear : sources kadi nakho}';
    protected $description = 'Nakki karo ke ek store kya source store(s) mathi product images borrow kari shake.';

    public function handle(): int
    {
        $targetId = (int) $this->option('target');
        if ($targetId <= 0) { $this->error('--target=<tenant id> joiye'); return self::FAILURE; }

        $t = Tenant::withoutGlobalScopes()->find($targetId);
        if (! $t) { $this->error("Target tenant {$targetId} malyo nahi"); return self::FAILURE; }

        $s = $t->settings ?? [];

        if ($this->option('clear')) {
            unset($s['image_sources']);
            $t->settings = $s;
            $t->saveQuietly();
            $this->info("Cleared image sources for {$t->name} (#{$targetId}).");
            return self::SUCCESS;
        }

        if ($this->option('source') === null) {
            $cur = $s['image_sources'] ?? [];
            $cur = is_array($cur) ? $cur : array_filter(array_map('intval', explode(',', (string) $cur)));
            $this->info("{$t->name} (#{$targetId}) image sources: " . ($cur ? implode(', ', $cur) : '(none)'));
            return self::SUCCESS;
        }

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('source'))), fn ($id) => $id > 0 && $id !== $targetId));
        if (! $ids) { $this->error('Koi valid source id nathi'); return self::FAILURE; }

        // validate sources exist
        $exist = Tenant::withoutGlobalScopes()->whereIn('id', $ids)->pluck('id')->all();
        $missing = array_diff($ids, $exist);
        if ($missing) { $this->error('Aa source tenant(s) malya nahi: ' . implode(', ', $missing)); return self::FAILURE; }

        $s['image_sources'] = $ids;
        $t->settings = $s;
        $t->saveQuietly();

        $names = Tenant::withoutGlobalScopes()->whereIn('id', $ids)->pluck('name')->all();
        $this->info("{$t->name} (#{$targetId}) have borrow kari shakshe: " . implode(', ', $names) . " (#" . implode(',#', $ids) . ").");
        $this->comment("Have e store na Products page par '🖼 Fill images' button avshe.");
        return self::SUCCESS;
    }
}
