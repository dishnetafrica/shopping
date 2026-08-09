<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Catalog\ImageOptimizer;
use App\Services\Catalog\ImagePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * v4.74 — one-time (re-runnable) shrink of the images a shop ALREADY has.
 *
 * The upload paths now convert everything on the way in; this walks what came in before
 * today: product photos (image_url + gallery_1..3) and the Settings images (logo, hero,
 * catalogue artwork). Dry-run by default — it prints what it would do and touches nothing
 * until --apply.
 *
 * Safety rules:
 *   · originals are NEVER deleted — the new .webp is written alongside and references move
 *   · only files on OUR public disk are touched; external URLs are reported and skipped
 *   · a conversion that would not shrink the file is skipped (ImageOptimizer's iron rule)
 *   · explicit tenant scoping everywhere (withoutGlobalScopes + tenant_id) — console rule
 */
class ImagesOptimizeCommand extends Command
{
    public const VERSION = 'v4.74';

    protected $signature = 'images:optimize {tenant : Tenant id} {--apply : Write files and update references (default: dry-run)}';

    protected $description = 'Convert a shop\'s existing images to fast WebP — dry-run by default';

    public function handle(): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $this->line(sprintf('images:optimize · %s · tenant %d (%s) · %s', self::VERSION, $t->id, $t->name, $apply ? 'APPLY' : 'DRY-RUN'));
        $this->line('');

        $work = [];   // each: [label, kind, url, updater(closure newUrl)]

        // ── product photos ─────────────────────────────────────────────────────────────────────
        $cols = ['image_url', 'gallery_1', 'gallery_2', 'gallery_3'];
        $rows = DB::table('products')->where('tenant_id', $t->id)
            ->select(array_merge(['id', 'name'], $cols))->orderBy('id')->get();
        foreach ($rows as $r) {
            foreach ($cols as $col) {
                $url = trim((string) ($r->{$col} ?? ''));
                if ($url === '') {
                    continue;
                }
                $work[] = [
                    'label' => sprintf('product #%d %s (%s)', $r->id, mb_substr((string) $r->name, 0, 28), $col),
                    'kind'  => 'product',
                    'url'   => $url,
                    'save'  => function (string $new) use ($t, $r, $col): void {
                        DB::table('products')->where('tenant_id', $t->id)->where('id', $r->id)->update([$col => $new]);
                    },
                ];
            }
        }

        // ── settings images (logo, hero, catalogue artwork — same derivation as the uploader) ──
        $keys = ['logo'];
        try {
            foreach (\App\Support\SettingsSchema::fields() as $key => $f) {
                if (($f['type'] ?? '') === 'image') {
                    $keys[] = $key;
                }
            }
        } catch (\Throwable $e) {
            // schema unavailable → logo alone still runs
        }
        foreach (array_values(array_unique($keys)) as $key) {
            $url = trim((string) $t->setting($key, ''));
            if ($url === '') {
                continue;
            }
            $work[] = [
                'label' => 'setting ' . $key,
                'kind'  => ImagePolicy::kindForKey($key),
                'url'   => $url,
                'save'  => function (string $new) use ($t, $key): void {
                    $t->putSetting($key, $new);
                },
            ];
        }

        // ── walk ───────────────────────────────────────────────────────────────────────────────
        $disk = Storage::disk('public');
        $done = 0; $skipped = 0; $before = 0; $after = 0; $lines = 0;
        foreach ($work as $w) {
            $rel = self::diskPath($w['url']);
            if ($rel === null) {
                $skipped++;
                $this->reportLine($lines, sprintf('  – skip (external url)      %s', $w['label']));
                continue;
            }
            if (! $disk->exists($rel)) {
                $skipped++;
                $this->reportLine($lines, sprintf('  – skip (file missing)      %s', $w['label']));
                continue;
            }
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                $skipped++;
                $this->reportLine($lines, sprintf('  – skip (%s)               %s', $ext ?: 'no ext', $w['label']));
                continue;
            }

            $bin = (string) $disk->get($rel);
            $opt = ImageOptimizer::optimize($bin, $ext, $w['kind']);
            if ($opt['ext'] === $ext && strlen($opt['bin']) === strlen($bin)) {
                $skipped++;
                $this->reportLine($lines, sprintf('  – keep  %-6s %4d KB     %s — %s', $ext, intdiv(strlen($bin), 1024), $w['label'], $opt['note']));
                continue;
            }

            $before += strlen($bin);
            $after  += strlen($opt['bin']);
            $done++;

            $dir  = trim(dirname($rel), '.');
            $base = pathinfo($rel, PATHINFO_FILENAME) . '.webp';
            $new  = ($dir !== '' ? $dir . '/' : '') . $base;
            if ($new === $rel) {
                $new = ($dir !== '' ? $dir . '/' : '') . pathinfo($rel, PATHINFO_FILENAME) . '-o.webp';
            }

            $this->reportLine($lines, sprintf('  ✓ %-4s→ webp %4d → %3d KB  %s', $ext, intdiv(strlen($bin), 1024), intdiv(strlen($opt['bin']), 1024), $w['label']));

            if ($apply) {
                $disk->put($new, $opt['bin']);   // original stays on disk untouched
                $w['save'](str_replace(basename($rel), basename($new), $w['url']));
            }
        }

        $this->line('');
        $this->info(sprintf('%s %d image(s): %d KB → %d KB (%s%% smaller) · %d kept/skipped',
            $apply ? 'Converted' : 'Would convert',
            $done, intdiv($before, 1024), intdiv($after, 1024),
            $before > 0 ? (string) (int) round(100 * ($before - $after) / $before) : '0',
            $skipped));
        if (! $apply && $done > 0) {
            $this->line('Nothing was changed. Run again with --apply to do it.');
        }
        if ($apply && $done > 0) {
            $this->line('Originals were kept on disk; only the references moved to the new .webp files.');
        }

        return self::SUCCESS;
    }

    /** Our own public-disk path from a stored URL/path, or null when it is not ours to touch. */
    public static function diskPath(string $url): ?string
    {
        $p = strpos($url, '/storage/');
        if ($p !== false) {
            $rel = substr($url, $p + strlen('/storage/'));
        } elseif (str_starts_with($url, 'storage/')) {
            $rel = substr($url, strlen('storage/'));
        } elseif (! str_contains($url, '://') && ! str_starts_with($url, '//') && $url !== '' && $url[0] !== '/') {
            $rel = $url;                    // bare relative path on the public disk
        } else {
            return null;                    // external or unrecognised — not ours to touch
        }
        $rel = ltrim($rel, '/');

        return ($rel === '' || str_contains($rel, '..')) ? null : $rel;
    }

    private function reportLine(int &$lines, string $s): void
    {
        $lines++;
        if ($lines <= 60) {
            $this->line($s);
        } elseif ($lines === 61) {
            $this->line('  … (more lines suppressed; totals below cover everything)');
        }
    }
}
