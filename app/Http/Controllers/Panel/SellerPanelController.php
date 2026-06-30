<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Serves the customer's familiar "Family Shopper — Seller Panel" UI verbatim.
 * The HTML/CSS/JS is unchanged; only its backend config was repointed to /papi/*.
 * We require a logged-in staff user (reusing the Filament /app web session) and
 * inject a token so the panel boots straight into the dashboard (no separate OTP).
 */
class SellerPanelController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            // Not a tenant staff member — send to the business login.
            return redirect('/app/login');
        }

        if ($this->needsOnboarding($user)) {
            return redirect('/panel/onboard');
        }

        $path = resource_path('panel/seller.html');
        if (! is_file($path)) {
            abort(500, 'Seller panel asset missing.');
        }

        $html = $this->brandize(file_get_contents($path), $user->tenant);

        // Inject the current user's permissions so the SPA can hide menus it isn't allowed.
        // (Server-side enforcement still gates the sensitive endpoints — this is UX only.)
        $me = [
            'role'       => (string) ($user->role ?: ''),
            'isOwner'    => $user->isOwnerLike(),
            'menus'      => $user->effectiveMenus(),
            'categories' => $user->categoryScope(), // null = all
            'name'       => (string) $user->name,
        ];
        $meJson = json_encode($me, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $html = preg_replace('#</head>#', '<script>window.ME=' . $meJson . ';</script></head>', $html, 1);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Mobile-first Seller Panel (Orders + Chats wired live to /papi/*).
     * Same session/tenant guard as show(); injects tenant branding via window.SHOP.
     */
    /** Real session logout for the custom /panel (the JS button only cleared old localStorage). */
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/app/login');
    }

    public function mobile(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }

        $path = resource_path('panel/mobile.html');
        if (! is_file($path)) {
            abort(500, 'Mobile panel asset missing.');
        }

        $html   = file_get_contents($path);
        $tenant = $user->tenant;
        $name   = trim((string) ($tenant->name ?? 'Shop'));
        $name   = (preg_replace('/[<>"\']/', '', $name) ?: 'Shop');

        $boot = [
            'name'     => $name,
            'initials' => $this->initialsFor($name),
            'phone'    => (string) ($tenant->whatsapp_number ?? ''),
            'plan'     => (method_exists($tenant, 'planLabel') ? (string) $tenant->planLabel() : ''),
        ];
        $json = json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $html = str_replace('<!--SHOP_BOOT-->', '<script>window.SHOP=' . $json . ';</script>', $html);

        // Home-screen app label + browser tab show the tenant's own name.
        $html = str_replace('content="Seller"', 'content="' . $name . '"', $html);
        $html = str_replace('<title>Seller Panel</title>', '<title>' . $name . '</title>', $html);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** Swap the hardcoded "Family Shopper / FS" branding for the tenant's own. */
    protected function brandize(string $html, $tenant): string
    {
        $name = trim((string) ($tenant->name ?? 'Shop'));
        $name = preg_replace('/[<>"\']/', '', $name) ?: 'Shop';
        $initials = $this->initialsFor($name);
        $html = str_replace('Family Shopper', $name, $html);
        $html = str_replace('<div class="lg">FS</div>', '<div class="lg">' . $initials . '</div>', $html);
        $html = str_replace('content="Seller"', 'content="' . $name . '"', $html);
        $html = $this->injectPlan($html, $tenant);
        $html = \App\Support\PanelCurrency::inject($html, $tenant);
        $html = $this->injectHelpWidget($html);
        return $html;
    }

    /** Floating "?" AI help assistant, available on every panel page. Self-contained + namespaced. */
    protected function injectHelpWidget(string $html): string
    {
        if (! str_contains($html, '</body>')) return $html;
        $w = <<<'HTML'
<div id="cbhelp-root"></div>
<style>
#cbhelp-fab{position:fixed;right:16px;bottom:18px;width:52px;height:52px;border-radius:50%;background:#103A8C;color:#fff;border:none;box-shadow:0 6px 18px rgba(0,0,0,.22);font-size:24px;cursor:pointer;z-index:2147483000;display:flex;align-items:center;justify-content:center}
#cbhelp-panel{position:fixed;right:16px;bottom:80px;width:min(380px,calc(100vw - 32px));height:min(560px,calc(100vh - 120px));background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.25);z-index:2147483000;display:none;flex-direction:column;overflow:hidden;font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#16181d}
#cbhelp-panel.open{display:flex}
#cbhelp-hd{background:#103A8C;color:#fff;padding:12px 14px;display:flex;align-items:center;justify-content:space-between}
#cbhelp-hd b{font-size:14.5px}#cbhelp-hd small{opacity:.85;font-size:11.5px;display:block}
#cbhelp-x{background:transparent;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1}
#cbhelp-body{flex:1;overflow-y:auto;padding:12px;background:#f7f8fa}
.cbh-msg{max-width:85%;padding:9px 12px;border-radius:14px;margin:6px 0;white-space:pre-wrap;word-wrap:break-word}
.cbh-a{background:#fff;border:1px solid #e9ebf0;border-bottom-left-radius:4px}
.cbh-u{background:#103A8C;color:#fff;margin-left:auto;border-bottom-right-radius:4px}
.cbh-tip{display:inline-block;background:#fff;border:1px solid #d7dbe2;border-radius:999px;padding:6px 11px;margin:4px 4px 0 0;font-size:12.5px;cursor:pointer;color:#103A8C}
#cbhelp-ft{border-top:1px solid #e5e7eb;padding:10px;display:flex;gap:8px;background:#fff}
#cbhelp-in{flex:1;border:1px solid #e5e7eb;border-radius:10px;padding:10px;font-size:14px;resize:none;max-height:90px}
#cbhelp-send{background:#103A8C;color:#fff;border:none;border-radius:10px;padding:0 14px;font-weight:600;cursor:pointer}
#cbhelp-send:disabled{opacity:.5}
.cbh-dot{display:inline-block;width:6px;height:6px;border-radius:50%;background:#9aa3af;margin:0 1px;animation:cbh 1s infinite}
.cbh-dot:nth-child(2){animation-delay:.2s}.cbh-dot:nth-child(3){animation-delay:.4s}
@keyframes cbh{0%,100%{opacity:.3}50%{opacity:1}}
</style>
<script>
(function(){
  if(window.__cbhelp)return; window.__cbhelp=1;
  var root=document.getElementById('cbhelp-root'); if(!root)return;
  var fab=document.createElement('button'); fab.id='cbhelp-fab'; fab.title='Help'; fab.innerHTML='?'; root.appendChild(fab);
  var p=document.createElement('div'); p.id='cbhelp-panel';
  p.innerHTML='<div id="cbhelp-hd"><div><b>Help assistant</b><small>Ask how to use the system</small></div><button id="cbhelp-x">\u00d7</button></div>'+
    '<div id="cbhelp-body"></div>'+
    '<div id="cbhelp-ft"><textarea id="cbhelp-in" rows="1" placeholder="Ask a question\u2026"></textarea><button id="cbhelp-send">Send</button></div>';
  root.appendChild(p);
  var body=p.querySelector('#cbhelp-body'), input=p.querySelector('#cbhelp-in'), send=p.querySelector('#cbhelp-send');
  var history=[], greeted=false;
  function esc(s){return (s||'').replace(/[&<>]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;'}[c];});}
  function add(role,text){var d=document.createElement('div');d.className='cbh-msg '+(role==='user'?'cbh-u':'cbh-a');d.innerHTML=esc(text);body.appendChild(d);body.scrollTop=body.scrollHeight;return d;}
  function greet(){ if(greeted)return; greeted=true;
    add('assistant',"Hi! I can show you how to use the system \u2014 adding products, importing a CSV, connecting WhatsApp, changing your currency, and more. What do you need?");
    var tips=['How do I add a product?','How do I import products from a CSV?','How do I connect WhatsApp?','How do I change my currency?'];
    var wrap=document.createElement('div'); tips.forEach(function(t){var c=document.createElement('span');c.className='cbh-tip';c.textContent=t;c.onclick=function(){input.value=t;ask();};wrap.appendChild(c);});
    try{ if(window.ME && window.ME.isOwner){ var s=document.createElement('span'); s.className='cbh-tip'; s.style.borderColor='#103A8C'; s.textContent='\u2699 Re-run shop setup'; s.onclick=function(){ location.href='/panel/onboard'; }; wrap.appendChild(s); } }catch(e){}
    body.appendChild(wrap);
  }
  function open(){p.classList.add('open');greet();input.focus();}
  function close(){p.classList.remove('open');}
  fab.onclick=function(){p.classList.contains('open')?close():open();};
  p.querySelector('#cbhelp-x').onclick=close;
  function ask(){
    var q=input.value.trim(); if(!q)return; input.value='';
    add('user',q); history.push({role:'user',content:q});
    send.disabled=true;
    var t=add('assistant',''); t.innerHTML='<span class="cbh-dot"></span><span class="cbh-dot"></span><span class="cbh-dot"></span>';
    fetch('/papi/help-ask',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({message:q,history:history.slice(0,-1)})})
      .then(function(r){return r.json().catch(function(){return{ok:false};});})
      .then(function(j){
        var reply=(j&&j.reply)||"Sorry, I couldn't answer that. Please contact DishNet/CloudBSS support.";
        t.textContent=reply; history.push({role:'assistant',content:reply});
        if(history.length>12)history=history.slice(-12);
      })
      .catch(function(){t.textContent="Network error \u2014 please try again.";})
      .finally(function(){send.disabled=false;body.scrollTop=body.scrollHeight;});
  }
  send.onclick=ask;
  input.addEventListener('keydown',function(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();ask();}});
})();
</script>
HTML;
        return str_replace('</body>', $w . '</body>', $html);
    }

    /**
     * Inject the tenant's current plan as window.PLAN and a small script that
     * hides locked menu items and shows an upgrade banner. Keeps the panel
     * HTML itself untouched; everything is decided server-side from the plan.
     */
    protected function injectPlan(string $html, $tenant): string
    {
        if (! $tenant || ! method_exists($tenant, 'effectivePlan')) return $html;

        $plan = [
            'plan'        => $tenant->effectivePlan(),
            'label'       => $tenant->planLabel(),
            'trial_days'  => $tenant->trialDaysLeft(),
            'order_cap'   => $tenant->orderCap(),
            'orders_used' => $tenant->ordersThisMonth(),
            'over_cap'    => $tenant->overOrderCap(),
            'features'    => [
                'pos'      => $tenant->can('pos'),
                'dispatch' => $tenant->can('dispatch'),
                'reports'  => $tenant->can('reports'),
                'returns'  => $tenant->can('returns'),
                'branding' => $tenant->can('branding'),
            ],
        ];
        $json = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // NOTE: replace 256700000000 with your CloudBSS sales WhatsApp number.
        $script = <<<'HTML'
<style>
#planBanner{position:sticky;top:0;z-index:60;background:#fff7e0;border-bottom:1px solid #f0d98a;color:#6b5300;
  padding:9px 16px;font-size:13.5px;display:flex;gap:10px;align-items:center;justify-content:center;flex-wrap:wrap;text-align:center}
#planBanner.locked{background:#fdeceb;border-color:#f3b6b1;color:#8a2c25}
#planBanner a{color:inherit;font-weight:800;text-decoration:underline}
</style>
<script>
(function(){
  var P=window.PLAN||{},F=P.features||{};
  var map={pos:'pos',dispatch:'dispatch',riders:'dispatch',reports:'reports',returns:'returns'};
  function go(){
    Object.keys(map).forEach(function(pg){
      if(F[map[pg]]===false){
        document.querySelectorAll('a.nav[data-page="'+pg+'"]').forEach(function(a){a.style.display='none';});
      }
    });
    var msg='',cls='';
    if(P.trial_days>0){ msg='✨ Free trial — '+P.trial_days+' day'+(P.trial_days==1?'':'s')+' of full features left.'; }
    else if(P.plan==='free'){ msg='You are on the Free plan. Counter sales, riders, tracking and reports are locked.'; cls='locked'; }
    if(P.over_cap){ msg='You have used all '+P.order_cap+' free orders this month.'; cls='locked'; }
    if(msg){
      var b=document.createElement('div'); b.id='planBanner'; if(cls)b.className=cls;
      b.innerHTML=msg+' <a href="/panel/billing">Upgrade now →</a>';
      document.body.insertBefore(b,document.body.firstChild);
    }
  }
  if(document.readyState!=='loading')go(); else document.addEventListener('DOMContentLoaded',go);
})();
</script>
HTML;

        $inject = '<script>window.PLAN=' . $json . ';</script>' . $script;
        return str_replace('</body>', $inject . '</body>', $html);
    }

    protected function initialsFor(string $name): string
    {
        $i = '';
        foreach (preg_split('/\s+/', trim($name)) as $p) {
            if ($p !== '') $i .= mb_strtoupper(mb_substr($p, 0, 1));
            if (mb_strlen($i) >= 2) break;
        }
        return $i !== '' ? $i : mb_strtoupper(mb_substr($name, 0, 2));
    }

    /** First-login setup wizard: owner declares business, location, currency basis, language. */
    public function onboard(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/onboard.html');
        if (! is_file($path)) {
            abort(500, 'Onboard asset missing.');
        }
        $t    = $user->tenant;
        $html = $this->brandize(file_get_contents($path), $t);

        // Prefill with current values so re-running the wizard edits rather than wipes.
        $langs = $t->setting('languages', []);
        if (is_string($langs)) $langs = array_filter(array_map('trim', explode(',', $langs)));
        $areas = $t->setting('delivery_areas', []);
        if (is_string($areas)) $areas = array_filter(array_map('trim', explode(',', $areas)));
        $cfg = [
            'onboarded'      => (bool) $t->setting('onboarded', false),
            'name'           => (string) ($t->name ?? ''),
            'bot_kind'       => (string) $t->setting('bot_kind', ''),
            'whatsapp'       => (string) ($t->whatsapp_number ?? ''),
            'country'        => (string) $t->setting('country', ''),
            'country_code'   => (string) $t->setting('country_code', ''),
            'city'           => (string) $t->setting('city', ''),
            'timezone'       => (string) $t->setting('timezone', ''),
            'currency'       => (string) $t->setting('currency', ''),
            'currency_basis' => (string) $t->setting('currency_basis', ''),
            'languages'      => array_values((array) $langs),
            'delivery_areas' => array_values((array) $areas),
        ];
        $json = json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $html = preg_replace('#</head>#', '<script>window.ONBOARD=' . $json . ';</script></head>', $html, 1);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** Owners see the setup wizard once, until they finish (or skip) it. Staff never do. */
    protected function needsOnboarding($user): bool
    {
        try {
            if (! $user || ! method_exists($user, 'isOwnerLike') || ! $user->isOwnerLike()) return false;
            $t = $user->tenant;
            return $t && ! $t->setting('onboarded', false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Self-serve onboarding: connect WhatsApp (QR) + generate the bot persona. */
    public function setup(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/setup.html');
        if (! is_file($path)) {
            abort(500, 'Setup asset missing.');
        }
        return response($this->brandize(file_get_contents($path), $request->user()->tenant), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** Today / Needs-attention home screen — the first page built on the shared JS layer. */
    public function today(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/today.html');
        if (! is_file($path)) {
            abort(500, 'Today asset missing.');
        }
        return response($this->brandize(file_get_contents($path), $user->tenant), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** Inventory count screen — record physical counts so stock confidence flips to "exact". */
    public function inventory(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/inventory-count.html');
        if (! is_file($path)) {
            abort(500, 'Inventory asset missing.');
        }
        return response($this->brandize(file_get_contents($path), $user->tenant), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** In-panel seller guide — what each function and the bot does. */
    public function guide(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/guide.html');
        if (! is_file($path)) {
            abort(500, 'Guide asset missing.');
        }
        $html = file_get_contents($path);
        $html = str_replace('<body data-vert="all">', '<body data-vert="' . \App\Support\Vertical::of($user->tenant) . '">', $html);
        return response($this->brandize($html, $user->tenant), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Serve a shared front-end JS module (api.js / utils.js / modal.js). These live under resources/
     * (not public/), so they need a route. Whitelisted filenames only — no brandize, raw JS.
     */
    public function shared(Request $request, string $file)
    {
        $allowed = ['api.js', 'utils.js', 'modal.js'];
        if (! in_array($file, $allowed, true)) {
            abort(404);
        }
        $path = resource_path('panel/shared/' . $file);
        if (! is_file($path)) {
            abort(404);
        }
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /** The live Chats inbox (new screen the old panel never had). */
    public function chats(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/chats.html');
        if (! is_file($path)) {
            abort(500, 'Chats asset missing.');
        }
        return response(file_get_contents($path), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function cashbook(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/cashbook.html');
        if (! is_file($path)) {
            abort(500, 'Cashbook asset missing.');
        }
        return response($this->brandize(file_get_contents($path), $user->tenant), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function staff(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/staff.html');
        if (! is_file($path)) {
            abort(500, 'Staff asset missing.');
        }
        return response($this->brandize(file_get_contents($path), $user->tenant), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function scheduled(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/scheduled.html');
        if (! is_file($path)) {
            abort(500, 'Scheduled asset missing.');
        }
        return response($this->brandize(file_get_contents($path), $user->tenant), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function marketing(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/marketing.html');
        if (! is_file($path)) {
            abort(500, 'Marketing asset missing.');
        }
        return response($this->brandize(file_get_contents($path), $user->tenant), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function tasks(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/tasks.html');
        if (! is_file($path)) {
            abort(500, 'Tasks asset missing.');
        }
        return response($this->brandize(file_get_contents($path), $user->tenant), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function leads(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/leads.html');
        if (! is_file($path)) {
            abort(500, 'Leads asset missing.');
        }
        return response($this->brandize(file_get_contents($path), $user->tenant), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    public function diagnostics(Request $request)
    {
        $user = $request->user();
        if (! $user || ! $user->tenant_id) {
            return redirect('/app/login');
        }
        $path = resource_path('panel/diagnostics.html');
        if (! is_file($path)) {
            abort(500, 'Diagnostics asset missing.');
        }
        return response($this->brandize(file_get_contents($path), $user->tenant), 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }
}
