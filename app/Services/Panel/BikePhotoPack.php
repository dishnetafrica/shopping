<?php

namespace App\Services\Panel;

use App\Models\Tenant;
use Illuminate\Support\Facades\Storage;

/**
 * v4.16 — "upload once" bike photo pack. One zip, named by model id
 * (hero.jpg, boxer100.jpg, …) → each photo is stored on the public disk and the matching
 * model_image_* setting is pointed at it. Photos live in /storage (persistent volume), so
 * they survive every rebuild and never depend on GitHub or the Docker build.
 *
 * Naming contract (case-insensitive, jpg/jpeg/png/webp):
 *   MAIN photos:  hero · boxer100 · boxer125hd · boxer150 · boxer125x · ct125hd · boxer150x · tvshlx125
 *   GALLERIES:    gal-{model}-{n}  e.g. gal-boxer125hd-1.jpg … gal-boxer125hd-7.jpg  (v4.17 —
 *                 up to 8 per model → model_gallery_{id} JSON setting → the model page gallery)
 * Everything else in the zip is reported as skipped, never an error.
 */
final class BikePhotoPack
{
    /** Model ids ↔ Settings keys (model_image_{id}) — mirrors SettingsSchema (v4.15). */
    public const MODEL_IDS = ['hero', 'boxer100', 'boxer125hd', 'boxer150', 'boxer125x', 'ct125hd', 'boxer150x', 'tvshlx125'];

    private const MAX_ONE = 8 * 1024 * 1024;   // per-photo cap (bytes)

    /**
     * @return array{ok:bool, applied:int, results:array<int,array{name:string,id?:string,ok:bool,url?:string,note:string}>, message:string}
     */
    public function ingest(Tenant $tenant, string $zipPath, string $baseUrl): array
    {
        if (! class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'applied' => 0, 'results' => [], 'message' => 'Zip support is not available on this server.'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'applied' => 0, 'results' => [], 'message' => 'Could not open the zip file.'];
        }

        $results = [];
        $applied = 0;
        $seen = [];
        $galleries = [];   // v4.17: model id => [n => url]
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $raw = (string) ($stat['name'] ?? '');
            if ($raw === '' || str_ends_with($raw, '/')) {
                continue;   // directory entry
            }
            $base = strtolower(basename(str_replace('\\', '/', $raw)));
            if ($base === '' || $base[0] === '.' || str_contains($raw, '__MACOSX')) {
                continue;   // mac cruft / hidden files — silently ignore
            }
            // v4.17 — gallery entries: gal-{model}-{n}.jpg (also "gal_boxer100 2.png" etc.)
            $galId = null;
            $galN = 0;
            if (preg_match('/^gal[ _-]*([a-z0-9 _-]*?)[ _-]*(\d{1,2})\.(jpe?g|png|webp)$/', $base, $gm)) {
                $galId = preg_replace('/[^a-z0-9]+/', '', $gm[1]);
                $galN = (int) $gm[2];
            }
            if ($galId !== null && ! in_array($galId, self::MODEL_IDS, true)) {
                $results[] = ['name' => $base, 'ok' => false,
                    'note' => 'skipped — gallery files are gal-{bike}-{number}, bikes: ' . implode(', ', self::MODEL_IDS)];
                continue;
            }
            if ($galId === null) {
                if (! preg_match('/^([a-z0-9 _.-]+)\.(jpe?g|png|webp)$/', $base, $m)) {
                    $results[] = ['name' => $base, 'ok' => false, 'note' => 'skipped — not a photo file'];
                    continue;
                }
                $m[1] = preg_replace('/[^a-z0-9]+/', '', $m[1]);   // "boxer 100" / "boxer_100.jpg" still match
            } else {
                $m = [$base, $galId, strtolower(pathinfo($base, PATHINFO_EXTENSION))];
            }
            [$_, $id, $ext] = $m;
            if (! in_array($id, self::MODEL_IDS, true)) {
                $results[] = ['name' => $base, 'ok' => false,
                    'note' => 'skipped — name a photo after its bike: ' . implode(', ', self::MODEL_IDS)];
                continue;
            }
            $dupKey = $galId !== null ? 'g:' . $id . ':' . $galN : $id;
            if (isset($seen[$dupKey])) {
                $results[] = ['name' => $base, 'id' => $id, 'ok' => false, 'note' => 'skipped — duplicate of an earlier file'];
                continue;
            }
            if ((int) ($stat['size'] ?? 0) > self::MAX_ONE) {
                $results[] = ['name' => $base, 'id' => $id, 'ok' => false, 'note' => 'skipped — larger than 8 MB'];
                continue;
            }
            $bytes = $zip->getFromIndex($i);
            if ($bytes === false || $bytes === '') {
                $results[] = ['name' => $base, 'id' => $id, 'ok' => false, 'note' => 'skipped — unreadable entry'];
                continue;
            }
            $info = @getimagesizefromstring($bytes);
            if (! $info || $info[0] < 50 || $info[1] < 50) {
                $results[] = ['name' => $base, 'id' => $id, 'ok' => false, 'note' => 'skipped — not a valid image (min 50×50)'];
                continue;
            }

            $ext = $ext === 'jpeg' ? 'jpg' : $ext;
            $slot = $galId !== null ? 'model_gallery_' . $id . '-' . $galN : 'model_image_' . $id;
            $path = 'branding/' . $tenant->id . '/' . $slot . '-' . substr(md5($bytes), 0, 10) . '.' . $ext;
            try {
                Storage::disk('public')->put($path, $bytes);
            } catch (\Throwable $e) {
                \Log::warning('BikePhotoPack store failed: ' . $e->getMessage());
                $results[] = ['name' => $base, 'id' => $id, 'ok' => false, 'note' => 'could not store the file'];
                continue;
            }
            $url = rtrim($baseUrl, '/') . '/storage/' . ltrim($path, '/');

            if ($galId !== null) {
                // gallery slots are applied together after the loop (one JSON setting per model)
                $galleries[$id][$galN] = $url;
                $seen[$dupKey] = true;
                $applied++;
                $results[] = ['name' => $base, 'id' => $id, 'ok' => true, 'url' => $url,
                    'note' => 'gallery photo ' . $galN . ' — model page'];
                continue;
            }

            try {
                $tenant->putSetting('model_image_' . $id, $url);
            } catch (\Throwable $e) {
                \Log::warning('BikePhotoPack setting failed: ' . $e->getMessage());
                $results[] = ['name' => $base, 'id' => $id, 'ok' => false, 'note' => 'stored, but could not save the setting'];
                continue;
            }
            $seen[$dupKey] = true;
            $applied++;
            $results[] = ['name' => $base, 'id' => $id, 'ok' => true, 'url' => $url,
                'note' => $id === 'hero' ? 'homepage hero + catalogue cover' : 'homepage card, picker, model page + catalogue'];
        }
        $zip->close();

        // v4.17 — write each model's gallery as ONE ordered JSON setting (max 8 photos)
        foreach ($galleries as $id => $slots) {
            ksort($slots);
            try {
                $tenant->putSetting('model_gallery_' . $id, json_encode(array_slice(array_values($slots), 0, 8)));
            } catch (\Throwable $e) {
                \Log::warning('BikePhotoPack gallery setting failed: ' . $e->getMessage());
            }
        }

        $msg = $applied > 0
            ? $applied . ' photo' . ($applied === 1 ? '' : 's') . ' applied — refresh your website to see them.'
            : 'No usable photos found — name the files hero.jpg, boxer100.jpg, gal-boxer100-1.jpg, … and re-zip.';

        return ['ok' => $applied > 0, 'applied' => $applied, 'results' => $results, 'message' => $msg];
    }
}
