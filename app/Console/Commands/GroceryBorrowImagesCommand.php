<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Ek grocery store na photos biji grocery store ma RE-USE karo (same products mate).
 * Target store na je products ma image NATHI, eni image source store(s) mathi laav — match
 * barcode -> sku -> name (e j priority). Image_url just REFERENCE thay chhe (public URL), etle
 * koi file copy nathi, instant + zero storage. Default ma fakt KHALI image wale products bharaay
 * (existing image kadi overwrite nathi thato, --force vagar).
 *
 *   php artisan grocery:borrow-images --target=2 --source=1          # store 1 na photos -> store 2
 *   php artisan grocery:borrow-images --target=2 --source=1 --dry    # preview, write nahi
 *   php artisan grocery:borrow-images --target=2                     # source = badha bija tenants (pool)
 *   php artisan grocery:borrow-images --target=2 --source=1 --gallery# gallery images pan
 *   php artisan grocery:borrow-images --target=2 --source=1 --strict # fakt barcode/sku (name match band)
 *   php artisan grocery:borrow-images --target=2 --source=1 --force  # already-set image pan overwrite
 */
class GroceryBorrowImagesCommand extends Command
{
    protected $signature = 'grocery:borrow-images {--target= : je store ma image bharva chhe (tenant id)} {--source= : kya store(s) mathi (id ya id,id; khali=badha bija)} {--gallery : gallery_1..3 pan copy karo} {--strict : fakt barcode/sku match (name nahi)} {--force : existing image pan overwrite} {--dry : preview only, write nahi}';
    protected $description = 'Grocery store na product photos biji store ma re-use karo (barcode/sku/name match).';

    private function norm(string $s): string
    {
        $s = strtoupper($s);
        $s = preg_replace('/[^A-Z0-9 ]/', ' ', $s);
        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    public function handle(): int
    {
        $targetId = (int) $this->option('target');
        if ($targetId <= 0) { $this->error('--target=<tenant id> joiye chhe'); return self::FAILURE; }

        $target = Tenant::withoutGlobalScopes()->find($targetId);
        if (! $target) { $this->error("Target tenant {$targetId} malyo nahi"); return self::FAILURE; }

        $dry     = (bool) $this->option('dry');
        $force   = (bool) $this->option('force');
        $gallery = (bool) $this->option('gallery');
        $strict  = (bool) $this->option('strict');

        // source tenant ids (khali hoy to badha bija tenants)
        if ($this->option('source')) {
            $sourceIds = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('source')))));
        } else {
            $sourceIds = Tenant::withoutGlobalScopes()->where('id', '!=', $targetId)->pluck('id')->all();
        }
        $sourceIds = array_values(array_filter($sourceIds, fn ($id) => $id !== $targetId));
        if (! $sourceIds) { $this->error('Koi source tenant nathi'); return self::FAILURE; }

        $this->info("Source stores: " . implode(', ', $sourceIds) . "  ->  Target: {$target->name} (#{$targetId})");

        // source image lookups (barcode / sku / name) — pehlu image jite, e rakhi
        $byBar = [];
        $bySku = [];
        $byName = [];
        $srcProducts = Product::withoutGlobalScopes()
            ->whereIn('tenant_id', $sourceIds)
            ->whereNotNull('image_url')->where('image_url', '!=', '')
            ->orderByRaw('CASE WHEN tenant_id = ? THEN 0 ELSE 1 END', [$sourceIds[0]]) // pehla source ne priority
            ->get(['name', 'sku', 'barcode', 'image_url', 'gallery_1', 'gallery_2', 'gallery_3']);

        foreach ($srcProducts as $sp) {
            $rec = ['image_url' => $sp->image_url, 'gallery_1' => $sp->gallery_1, 'gallery_2' => $sp->gallery_2, 'gallery_3' => $sp->gallery_3];
            if (trim((string) $sp->barcode) !== '' && ! isset($byBar[trim((string) $sp->barcode)])) $byBar[trim((string) $sp->barcode)] = $rec;
            if (trim((string) $sp->sku) !== '' && ! isset($bySku[trim((string) $sp->sku)])) $bySku[trim((string) $sp->sku)] = $rec;
            $k = $this->norm((string) $sp->name);
            if ($k !== '' && ! isset($byName[$k])) $byName[$k] = $rec;
        }
        $this->line("Source images available: barcode " . count($byBar) . ", sku " . count($bySku) . ", name " . count($byName));

        // target products
        $q = Product::withoutGlobalScopes()->where('tenant_id', $targetId);
        if (! $force) $q->where(function ($w) { $w->whereNull('image_url')->orWhere('image_url', ''); });
        $targets = $q->get(['id', 'name', 'sku', 'barcode', 'image_url']);

        $filled = 0; $byB = 0; $byS = 0; $byN = 0; $noMatch = 0;
        $samplesNoMatch = [];

        foreach ($targets as $tp) {
            $rec = null; $how = '';
            $bc = trim((string) $tp->barcode);
            $sk = trim((string) $tp->sku);
            if ($bc !== '' && isset($byBar[$bc]))      { $rec = $byBar[$bc]; $how = 'barcode'; }
            elseif ($sk !== '' && isset($bySku[$sk]))  { $rec = $bySku[$sk]; $how = 'sku'; }
            elseif (! $strict && isset($byName[$this->norm((string) $tp->name)])) { $rec = $byName[$this->norm((string) $tp->name)]; $how = 'name'; }

            if (! $rec) { $noMatch++; if (count($samplesNoMatch) < 15) $samplesNoMatch[] = $tp->name; continue; }

            if (! $dry) {
                $tp->image_url = $rec['image_url'];
                if ($gallery) {
                    if (! empty($rec['gallery_1'])) $tp->gallery_1 = $rec['gallery_1'];
                    if (! empty($rec['gallery_2'])) $tp->gallery_2 = $rec['gallery_2'];
                    if (! empty($rec['gallery_3'])) $tp->gallery_3 = $rec['gallery_3'];
                }
                $tp->saveQuietly();
            }
            $filled++;
            if ($how === 'barcode') $byB++; elseif ($how === 'sku') $byS++; else $byN++;
        }

        $this->newLine();
        $this->info(($dry ? '[DRY RUN] ' : '') . "Target imageless/considered: " . count($targets));
        $this->info("Filled: {$filled}   (by barcode {$byB}, sku {$byS}, name {$byN})");
        $this->info("Still no match: {$noMatch}");
        if ($samplesNoMatch) $this->line("  e.g. " . implode(' | ', array_slice($samplesNoMatch, 0, 10)));
        if ($dry) $this->comment("Preview only — kai write nathi karyu. --dry kadho apply karva mate.");

        return self::SUCCESS;
    }
}
