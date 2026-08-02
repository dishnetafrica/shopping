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
            'key'  => 'required|string|in:' . implode(',', self::KEYS),
            'file' => 'required|file|image|mimes:png,jpg,jpeg,webp,gif|max:6144',
        ]);

        $file = $data['file'];
        $ext = strtolower($file->getClientOriginalExtension() ?: 'png');
        if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
            $ext = 'png';
        }
        $path = 'branding/' . $t->id . '/' . $data['key'] . '-' . substr(md5_file($file->getRealPath()) ?: uniqid(), 0, 10) . '.' . $ext;

        try {
            Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));
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
