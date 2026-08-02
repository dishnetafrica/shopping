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
 *   hero · boxer100 · boxer125hd · boxer150 · boxer125x · ct125hd · boxer150x · tvshlx125
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
            if (! preg_match('/^([a-z0-9 _.-]+)\.(jpe?g|png|webp)$/', $base, $m)) {
                $results[] = ['name' => $base, 'ok' => false, 'note' => 'skipped — not a photo file'];
                continue;
            }
            $m[1] = preg_replace('/[^a-z0-9]+/', '', $m[1]);   // "boxer 100" / "boxer_100.jpg" still match
            [$_, $id, $ext] = $m;
            if (! in_array($id, self::MODEL_IDS, true)) {
                $results[] = ['name' => $base, 'ok' => false,
                    'note' => 'skipped — name a photo after its bike: ' . implode(', ', self::MODEL_IDS)];
                continue;
            }
            if (isset($seen[$id])) {
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
            $path = 'branding/' . $tenant->id . '/model_image_' . $id . '-' . substr(md5($bytes), 0, 10) . '.' . $ext;
            try {
                Storage::disk('public')->put($path, $bytes);
            } catch (\Throwable $e) {
                \Log::warning('BikePhotoPack store failed: ' . $e->getMessage());
                $results[] = ['name' => $base, 'id' => $id, 'ok' => false, 'note' => 'could not store the file'];
                continue;
            }
            $url = rtrim($baseUrl, '/') . '/storage/' . ltrim($path, '/');
            try {
                $tenant->putSetting('model_image_' . $id, $url);
            } catch (\Throwable $e) {
                \Log::warning('BikePhotoPack setting failed: ' . $e->getMessage());
                $results[] = ['name' => $base, 'id' => $id, 'ok' => false, 'note' => 'stored, but could not save the setting'];
                continue;
            }
            $seen[$id] = true;
            $applied++;
            $results[] = ['name' => $base, 'id' => $id, 'ok' => true, 'url' => $url,
                'note' => $id === 'hero' ? 'homepage hero + catalogue cover' : 'homepage card, picker, model page + catalogue'];
        }
        $zip->close();

        $msg = $applied > 0
            ? $applied . ' photo' . ($applied === 1 ? '' : 's') . ' applied — refresh your website to see them.'
            : 'No usable photos found — name the files hero.jpg, boxer100.jpg, boxer125hd.jpg, … and re-zip.';

        return ['ok' => $applied > 0, 'applied' => $applied, 'results' => $results, 'message' => $msg];
    }
}
