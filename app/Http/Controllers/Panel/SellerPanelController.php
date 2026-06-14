<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

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

        $path = resource_path('panel/seller.html');
        if (! is_file($path)) {
            abort(500, 'Seller panel asset missing.');
        }

        $html = $this->brandize(file_get_contents($path), $user->tenant);

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Mobile-first Seller Panel (Orders + Chats wired live to /papi/*).
     * Same session/tenant guard as show(); injects tenant branding via window.SHOP.
     */
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
        return $html;
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
