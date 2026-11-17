<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Tenant;

/**
 * A business's own multi-page website, served by ShopBot from a site bundle in resources/tenant-sites/{bundle}/
 * (setting `site_bundle`). First user: DishNet South Sudan, whose static site (about, contact, tutorials, …)
 * now lives here so it can share the domain with the live plans page and chat.
 *
 * Pages carry NO amounts of their own: every price is a token filled from the switched-on catalogue when the
 * page is served (livePrices). Pages listed under "hold" in site.json are not served at all: they redirect to
 * the live ShopBot page (e.g. coverage → the published coverage list on /shop).
 *
 * Paths: /{shop}/{page} on the platform host and /{page} on the business's custom domain, where {page} is
 * "name.html", "name.css", "tutorials/name.html" or "tutorials/name.css" — the suffix keeps these routes
 * from ever matching a shop slug. A file is served only if it exists inside the bundle (no other paths).
 */
class TenantSiteController extends Controller
{
    public const PAGE = '(tutorials/)?[a-z0-9][a-z0-9\-]*\.(html|css)';

    /** /{page} on the business's own domain. (Laravel passes route parameters by position, hence two entry points.) */
    public function onDomain(string $page)
    {
        return $this->serve(null, $page);
    }

    /** /{shop}/{page} on the platform host. */
    public function forShop(string $shop, string $page)
    {
        return $this->serve($shop, $page);
    }

    private function serve(?string $shop, string $page)
    {
        [$tenant, $base] = app(StorefrontController::class)->resolveShop($shop);

        $dir = self::bundleDir($tenant);
        abort_if($dir === null || ! preg_match('#^' . self::PAGE . '$#', $page), 404);

        $hold = (array) (self::manifest($dir)['hold'] ?? []);
        if (array_key_exists($page, $hold)) {
            return redirect($base . (string) $hold[$page], 302);       // e.g. "/shop#coverage" → the live page
        }

        $file = realpath($dir . '/' . $page);
        abort_if($file === false || ! str_starts_with($file, $dir . DIRECTORY_SEPARATOR) || ! is_file($file), 404);

        return self::respond($tenant, $file, str_ends_with($page, '.css'));
    }

    /**
     * The business's home page from its bundle (site.json "home"), when it has one that is not held. Served at the
     * root of its own domain; on the platform host it is /{shop}/{home} (its relative links need that folder).
     * Null → the caller shows its usual landing page.
     */
    public static function home(Tenant $tenant): ?\Symfony\Component\HttpFoundation\Response
    {
        $home = self::homeFile($tenant);

        return $home === null ? null : self::respond($tenant, self::bundleDir($tenant) . '/' . $home, false);
    }

    /** The home page's file name inside the bundle ("index.html"), or null — a cheap check, nothing rendered. */
    public static function homeFile(Tenant $tenant): ?string
    {
        $dir = self::bundleDir($tenant);
        if ($dir === null) {
            return null;
        }
        $m = self::manifest($dir);
        $home = (string) ($m['home'] ?? '');
        if ($home === '' || array_key_exists($home, (array) ($m['hold'] ?? [])) || ! preg_match('#^' . self::PAGE . '$#', $home)) {
            return null;
        }
        $file = realpath($dir . '/' . $home);

        return ($file && str_starts_with($file, $dir . DIRECTORY_SEPARATOR) && is_file($file)) ? $home : null;
    }

    private static function respond(Tenant $tenant, string $file, bool $css): \Symfony\Component\HttpFoundation\Response
    {
        $body = (string) file_get_contents($file);

        return response($css ? $body : self::livePrices($tenant, $body), 200)
            ->header('Content-Type', $css ? 'text/css; charset=utf-8' : 'text/html; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=300');
    }

    /**
     * Fill the bundle's price tokens from the SWITCHED-ON catalogue — the same approved prices the bot and the
     * plans page use. A page never carries an amount of its own (TenantSiteTest guards that):
     *   {{price:Product name}}  → "$400"; switched off / unknown / unpriced → "ask our sales team"
     *   {{from:service line}}   → lowest monthly plan of that line (starlink, fiber)
     *   {{num:Product name}}    → "400" (Google product data); unknown → ""
     *   {{ask}}                 → "Ask our sales team"
     */
    public static function livePrices(Tenant $tenant, string $html): string
    {
        if (! str_contains($html, '{{')) {
            return $html;
        }
        $cur = strtoupper(trim((string) $tenant->setting('currency', 'USD'))) ?: 'USD';
        $prices = [];
        $monthly = [];
        $hidden = \App\Services\Catalog\Visibility::hiddenCategories($tenant);   // same filter as the plans page
        foreach (Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('active', true)->whereNull('archived_at')->get() as $p) {
            if (\App\Services\Catalog\Visibility::isHidden((string) ($p->category ?? ''), $p->hidden ?? null, $hidden)) {
                continue;
            }
            $v = (float) ($p->price ?? $p->base_price ?? 0);
            if ($v <= 0) {
                continue;
            }
            $prices[mb_strtolower(trim((string) $p->name))] = $v;
            if ($p->billing === 'monthly') {
                $line = mb_strtolower(trim((string) $p->service_line));
                $monthly[$line] = isset($monthly[$line]) ? min($monthly[$line], $v) : $v;
            }
        }
        $num = fn (float $v) => floor($v) == $v ? number_format($v, 0, '.', ',') : number_format($v, 2, '.', ',');
        $money = fn (float $v) => ($cur === 'USD' ? '$' : $cur . ' ') . $num($v);

        return (string) preg_replace_callback('/\{\{(price|from|num|ask)(?::([^}]+))?\}\}/', function ($m) use ($prices, $monthly, $num, $money) {
            $key = mb_strtolower(trim((string) ($m[2] ?? '')));

            return match ($m[1]) {
                'ask'   => 'Ask our sales team',
                'price' => isset($prices[$key]) ? $money($prices[$key]) : 'ask our sales team',
                'from'  => isset($monthly[$key]) ? $money($monthly[$key]) : 'ask our sales team',
                'num'   => isset($prices[$key]) ? str_replace(',', '', $num($prices[$key])) : '',
            };
        }, $html);
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
