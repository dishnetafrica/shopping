<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Delete a shop's orphaned product-photo files — images sitting under products/{tenant}/ that NO
 * product references any more. These accumulate from failed/replaced imports (e.g. a photo re-import
 * that relinked products to fresh files, leaving the earlier upload attempts stranded).
 *
 * Dry-run by default: prints what it WOULD delete and how much it would free, and touches nothing
 * until --apply.
 *
 * Safety rules:
 *   · Only ever scans/deletes within products/{tenant}/ on the public disk — no other prefix, no
 *     other tenant's folder.
 *   · The "referenced" set is built from ALL products across ALL tenants (image_url + gallery_1..3),
 *     so a cross-tenant-borrowed image (imagesBorrow references another shop's file by URL) is NEVER
 *     deleted just because THIS tenant's rows don't point at it.
 *   · Only image files are considered; anything referenced, or with a '..' in its path, is left alone.
 */
class PhotosPruneOrphansCommand extends Command
{
    protected $signature = 'photos:prune-orphans {tenant : Tenant id} {--apply : Delete the orphans (default: dry-run)}';

    protected $description = 'Delete product-photo files no product references any more — dry-run by default';

    public function handle(): int
    {
        $t = Tenant::find((int) $this->argument('tenant'));
        if (! $t) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $this->line(sprintf('photos:prune-orphans · tenant %d (%s) · %s', $t->id, $t->name, $apply ? 'APPLY' : 'DRY-RUN'));

        // Referenced set: every image a product points at, ACROSS ALL TENANTS (guards cross-tenant borrows).
        $referenced = [];
        DB::table('products')
            ->select('image_url', 'gallery_1', 'gallery_2', 'gallery_3')
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$referenced) {
                foreach ($rows as $r) {
                    foreach (['image_url', 'gallery_1', 'gallery_2', 'gallery_3'] as $col) {
                        $rel = ImagesOptimizeCommand::diskPath(trim((string) ($r->{$col} ?? '')));
                        if ($rel !== null) {
                            $referenced[$rel] = true;
                        }
                    }
                }
            });

        $disk    = Storage::disk('public');
        $dir     = 'products/' . $t->id;
        $files   = $disk->exists($dir) ? $disk->allFiles($dir) : [];
        $imgExt  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        $orphans = [];
        $freed   = 0;
        foreach ($files as $f) {
            if (isset($referenced[$f])) {
                continue;                                   // still referenced — never touch
            }
            if (! in_array(strtolower(pathinfo($f, PATHINFO_EXTENSION)), $imgExt, true)) {
                continue;                                   // not an image — leave it alone
            }
            $orphans[] = $f;
            $freed += (int) $disk->size($f);
        }

        $this->line(sprintf('  scanned %d files in %s · referenced %d · orphaned %d (%.1f MB)',
            count($files), $dir, count($referenced), count($orphans), $freed / 1048576));

        foreach (array_slice($orphans, 0, 40) as $o) {
            $this->line('   ' . ($apply ? 'delete ' : 'would delete ') . $o);
        }
        if (count($orphans) > 40) {
            $this->line(sprintf('   … and %d more', count($orphans) - 40));
        }

        if (! $apply) {
            $this->line('');
            $this->line(sprintf('Dry-run. Re-run with --apply to delete these %d files and free %.1f MB.',
                count($orphans), $freed / 1048576));

            return self::SUCCESS;
        }

        $deleted = 0;
        foreach ($orphans as $o) {
            if ($disk->delete($o)) {
                $deleted++;
            }
        }
        $this->line('');
        $this->line(sprintf('Deleted %d/%d files · freed %.1f MB.', $deleted, count($orphans), $freed / 1048576));

        return self::SUCCESS;
    }
}
