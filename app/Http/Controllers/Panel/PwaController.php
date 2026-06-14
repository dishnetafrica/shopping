<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;

/**
 * Makes the seller panel an installable app (Add to Home Screen, full-screen,
 * own icon). Served from resources/ so it ships with the normal overlay; routes
 * are public because a manifest / service worker / icon must be fetchable
 * without a session.
 */
class PwaController extends Controller
{
    public function manifest(\Illuminate\Http\Request $r)
    {
        $name = 'ShopBot Seller';
        $u = $r->user();
        if ($u && $u->tenant && trim((string) $u->tenant->name) !== '') {
            $name = trim((string) $u->tenant->name);
        }
        $short = mb_strlen($name) <= 12 ? $name : $this->initials($name);

        // ?app=m -> the mobile-first panel opens straight into /panel/m
        $start = $r->query('app') === 'm' ? '/panel/m' : '/panel';

        return response()->json([
            'name'             => $name,
            'short_name'       => $short,
            'description'      => 'Manage orders, products and WhatsApp chats',
            'start_url'        => $start,
            'scope'            => '/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#0B3D22',
            'theme_color'      => '#0B3D22',
            'icons'            => [
                ['src' => '/icons/app-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/icons/app-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ])->header('Content-Type', 'application/manifest+json');
    }

    protected function initials(string $name): string
    {
        $i = '';
        foreach (preg_split('/\s+/', trim($name)) as $p) {
            if ($p !== '') $i .= mb_strtoupper(mb_substr($p, 0, 1));
            if (mb_strlen($i) >= 2) break;
        }
        return $i !== '' ? $i : 'Shop';
    }

    public function sw()
    {
        $js = <<<'JS'
const C='shopbot-shell-v1';
const SHELL=['/icons/app-192.png','/icons/app-512.png'];
self.addEventListener('install',e=>{e.waitUntil(caches.open(C).then(c=>c.addAll(SHELL)).then(()=>self.skipWaiting()));});
self.addEventListener('activate',e=>{e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>k!==C).map(k=>caches.delete(k)))).then(()=>self.clients.claim()));});
self.addEventListener('fetch',e=>{
  if(e.request.method!=='GET')return;                 // never touch writes
  const u=new URL(e.request.url);
  if(u.pathname.startsWith('/papi/')||u.pathname.startsWith('/api/'))return; // always live data
  e.respondWith(fetch(e.request).catch(()=>caches.match(e.request)));        // network-first, cache fallback
});
JS;
        return response($js, 200)
            ->header('Content-Type', 'application/javascript')
            ->header('Cache-Control', 'no-cache')
            ->header('Service-Worker-Allowed', '/');
    }

    public function icon(string $name)
    {
        $allowed = ['app-192.png', 'app-512.png', 'apple-touch-icon.png'];
        if (! in_array($name, $allowed, true)) {
            abort(404);
        }
        $path = resource_path('panel/icons/' . $name);
        if (! is_file($path)) {
            abort(404);
        }
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
