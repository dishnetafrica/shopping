<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Tenant;

/**
 * A business's own multi-page website, served by ShopBot from a site bundle in resources/tenant-sites/{bundle}/
 * (setting `site_bundle`). First user: DishNet South Sudan, whose static site (about, contact, tutorials, …)
 * now lives here so it can share the domain with the live plans page and chat.
 *
 * Pages listed under "hold" in the bundle's site.json are NOT served as-is: they carry prices, SSP amounts,
 * coverage or terms that differ from what management approved in ShopBot. A held page sends the visitor to
 * the live ShopBot page (plans / coverage / chat) instead, until it is rebuilt from live, approved data.
 *
 * Paths: /{shop}/{page} on the platform host and /{page} on the business's custom domain, where {page} is
 * "name.html", "name.css", "tutorials/name.html" or "tutorials/name.css" — the suffix keeps these routes
 * from ever matching a shop slug. A file is served only if it exists inside the bundle (no other paths).
 */
class TenantSiteController extends Controller
{
    public const PAGE = '(tutorials/)?[a-z0-9][a-z0-9\-]*\.(html|css)';

    public function page(?string $shop = null, ?string $page = null)
    {
        // Custom domain → only {page} is bound ($shop null); platform host → /{shop}/{page}.
        $page = (string) $page;
        [$tenant, $base] = app(StorefrontController::class)->resolveShop($shop);

        $dir = self::bundleDir($tenant);
        abort_if($dir === null || ! preg_match('#^' . self::PAGE . '$#', $page), 404);

        $hold = (array) (self::manifest($dir)['hold'] ?? []);
        if (array_key_exists($page, $hold)) {
            return redirect(($base === '' ? '/' : $base) . (string) $hold[$page], 302);
        }

        $file = realpath($dir . '/' . $page);
        abort_if($file === false || ! str_starts_with($file, $dir . DIRECTORY_SEPARATOR) || ! is_file($file), 404);

        return response((string) file_get_contents($file), 200)
            ->header('Content-Type', str_ends_with($page, '.css') ? 'text/css; charset=utf-8' : 'text/html; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300');
    }

    /** The business's site bundle folder, or null when it has none. */
    public static function bundleDir(Tenant $tenant): ?string
    {
        $name = (string) $tenant->setting('site_bundle', '');
        if (! preg_match('/^[a-z0-9][a-z0-9\-]{0,40}$/', $name)) {
            return null;
        }
        $root = realpath(resource_path('tenant-sites'));
        $dir = $root ? realpath($root . '/' . $name) : false;

        return ($dir && str_starts_with($dir, $root . DIRECTORY_SEPARATOR) && is_dir($dir)) ? $dir : null;
    }

    /** @return array{home?:string, hold?:array<string,string>} */
    public static function manifest(string $dir): array
    {
        $j = json_decode((string) @file_get_contents($dir . '/site.json'), true);

        return is_array($j) ? $j : [];
    }
}
