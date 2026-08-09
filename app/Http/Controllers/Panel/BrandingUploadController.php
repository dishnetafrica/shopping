<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * POST /papi/branding-upload — upload a branding/catalogue image from the Settings screen
 * instead of pasting a URL. Stores the file on the public disk and returns the ABSOLUTE URL;
 * the Settings form drops that URL into the matching text field and the normal
 * settings-hub-save flow persists it (so nothing else in the pipeline changes).
 *
 * Accepted keys mirror the image-type fields in SettingsSchema. Owner-only, like settings save.
 */
class BrandingUploadController extends Controller
{
    private const KEYS = ['logo', 'catalog_cover_image', 'catalog_promo_image', 'catalog_qr_image',
        'model_image_tvshlx125'];   // v4.7.8 — storefront TVS HLX 125 photo (Settings → Storefront)

    /**
     * v4.16 — the hardcoded list above silently REJECTED every image field added after it
     * (the v4.15 bike photos uploaded fine for TVS but failed for every new model — this was
     * why). Allowed keys are now derived from SettingsSchema, so any field the Settings
     * screen shows as an image upload is automatically uploadable, forever.
     */
    private static function allowedKeys(): array
    {
        $keys = self::KEYS;
        try {
            foreach (\App\Support\SettingsSchema::fields() as $key => $f) {
                if (($f['type'] ?? '') === 'image') {
                    $keys[] = $key;
                }
            }
        } catch (\Throwable $e) {
            // schema unavailable → the legacy list still works
        }

        return array_values(array_unique($keys));
    }

    public function upload(Request $request)
    {
        $u = $request->user();
        if (! $u || ! method_exists($u, 'isOwnerLike') || ! $u->isOwnerLike()) {
            return response()->json(['ok' => false, 'error' => 'Owner access required.'], 403);
        }
        $t = $u->tenant;
        if (! $t) {
            return response()->json(['ok' => false, 'error' => 'No business.'], 401);
        }

        $data = $request->validate([
            'key'  => 'required|string|in:' . implode(',', self::allowedKeys()),
            // v4.74 — 10 MB in: phone photos are 4–8 MB and the server now shrinks them itself.
            'file' => 'required|file|image|mimes:png,jpg,jpeg,webp,gif|max:10240',
        ]);

        $file = $data['file'];
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            $ext = 'png';
        }

        // v4.74 — convert/resize per slot (logo keeps transparency, hero fits its frame) so the
        // owner can upload any photo and the page stays fast. On any doubt the original ships.
        $bin = (string) file_get_contents($file->getRealPath());
        $opt = \App\Services\Catalog\ImageOptimizer::optimize($bin, $ext, \App\Services\Catalog\ImagePolicy::kindForKey($data['key']));
        $bin = $opt['bin'];
        $ext = $opt['ext'];

        $path = 'branding/' . $t->id . '/' . $data['key'] . '-' . substr(md5($bin), 0, 10) . '.' . $ext;

        try {
            Storage::disk('public')->put($path, $bin);
        } catch (\Throwable $e) {
            \Log::warning('branding-upload failed: ' . $e->getMessage());

            return response()->json(['ok' => false, 'error' => 'Could not store the file.'], 500);
        }

        // Absolute URL built from the host the OWNER is browsing on (mycloudbss.com / custom
        // domain) — NOT from APP_URL, which on some deploys points at an internal container
        // hostname (e.g. *.easypanel.host) that is wrong for public links.
        $url = $request->getSchemeAndHttpHost() . '/storage/' . ltrim($path, '/');

        return response()->json(['ok' => true, 'url' => $url, 'key' => $data['key']]);
    }
}
