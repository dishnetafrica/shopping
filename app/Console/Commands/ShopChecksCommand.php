<?php declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * v5.07.1 - the nine launch checks, as a command.
 *
 * These shipped first as ops/runtime-checks.php, to be require()d from tinker. That failed on the
 * server - the ops/ folder is not part of what gets deployed - while `stock:count`, sitting in
 * app/Console/Commands, worked first time. So this lives where the deploy actually reaches.
 *
 * READ ONLY. It writes nothing, sends nothing and changes no setting.
 *
 *   php artisan shop:checks --tenant=17
 */
final class ShopChecksCommand extends Command
{
    protected $signature = 'shop:checks {--tenant=17 : tenant id}';

    protected $description = 'The nine launch-readiness checks that source code alone cannot answer.';

    public function handle(): int
    {
        $T = (int) $this->option('tenant');


$line = fn ($s = '') => $this->line((string) $s);
$hd = function ($n, $q) use ($line) { $line(''); $line(str_repeat('=', 78)); $line($n . '  ' . $q); $line(str_repeat('=', 78)); };
$yn = fn ($b) => $b ? 'YES' : 'no';

$t = \App\Models\Tenant::find($T);
if (! $t) { $this->error('No tenant ' . $T); return self::FAILURE; }
$line('Family Shoppers runtime checks - tenant ' . $T . ' - ' . now()->format('Y-m-d H:i:s'));

/* 1 ------------------------------------------------------------------------------------ */
$hd('CHECK 1', 'How many shops share this platform?  (sets the severity of the P0-5 fix)');
$tenants = \App\Models\Tenant::withoutGlobalScopes()->count();
$line('   tenants on this installation : ' . $tenants);
$line($tenants > 1
    ? '   -> more than one. The cross-tenant product read WAS exposing other shops. Fixed in v5.05.'
    : '   -> only this one, so nothing was exposed to a third party. Fix still correct to keep.');

/* 2 ------------------------------------------------------------------------------------ */
$hd('CHECK 2', 'Does the shop actually have its two branches?  (P1-7)');
$sb = $t->setting('branches', []);
$bt = \App\Models\Branch::where('tenant_id', $T)->get(['name', 'lat', 'lng']);
$line('   settings["branches"]  : ' . (is_array($sb) && $sb ? count($sb) . ' entries' : 'EMPTY (as predicted)'));
$line('   branches TABLE        : ' . $bt->count() . ' rows');
foreach ($bt as $b) {
    $ok = ($b->lat !== null && $b->lng !== null && ! ((float) $b->lat == 0.0 && (float) $b->lng == 0.0));
    $line('      - ' . str_pad((string) $b->name, 22) . ' lat=' . ($b->lat ?? 'NULL') . ' lng=' . ($b->lng ?? 'NULL')
        . ($ok ? '' : '   <-- NO COORDINATES: this branch cannot be offered'));
}
$line($bt->count() >= 2
    ? '   -> v5.05 reads this table, so the city picker now offers ' . $bt->count() . ' branches.'
    : '   -> ADD THE SECOND BRANCH (Panel > Branches) with real lat/lng, or the picker stays single-city.');

/* 3 ------------------------------------------------------------------------------------ */
$hd('CHECK 3', 'Is a delivery policy configured?  (P1-6)');
$d = $t->setting('delivery', []);
$line('   settings["delivery"]  : ' . json_encode($d));
$hasRules = is_array($d) && ! empty($d['free_rules']);
$line($hasRules
    ? '   -> configured. The cart states YOUR rule.'
    : '   -> not configured. v5.05 removed the invented [Juba 50 / Kampala 20] default, so the cart'
      . "\n" . '      now says "delivery confirmed on WhatsApp". Set a real rule in Settings to state one.');
$line('   settings["delivery_note"] (what the BOT says): ' . json_encode($t->setting('delivery_note', '')));

/* 4 ------------------------------------------------------------------------------------ */
$hd('CHECK 4', 'Cookie policy - was the panel CSRF hole exploitable?  (P1-5)');
$ss = config('session.same_site');
$line('   session.same_site : ' . var_export($ss, true));
$line(in_array((string) $ss, ['strict'], true)
    ? '   -> strict: the browser already blocked it. The v5.05 middleware is belt and braces.'
    : '   -> lax/null: it WAS exploitable by a one-click link. RequireSameOrigin now blocks it.');

/* 5 ------------------------------------------------------------------------------------ */
$hd('CHECK 5', 'Is anything actually sending the WhatsApp notifications?');
$line('   QUEUE_CONNECTION : ' . config('queue.default'));
if (config('queue.default') === 'database') {
    $line('   jobs table exists: ' . $yn(\Illuminate\Support\Facades\Schema::hasTable('jobs')));
    if (\Illuminate\Support\Facades\Schema::hasTable('jobs')) {
        $line('   jobs waiting     : ' . \Illuminate\Support\Facades\DB::table('jobs')->count() . '   (a large or growing number = no worker)');
    }
    $line('   failed_jobs table: ' . $yn(\Illuminate\Support\Facades\Schema::hasTable('failed_jobs')));
}
$sends = \Illuminate\Support\Facades\DB::table('order_notification_sends')->where('tenant_id', $T);
$line('   notification rows      : ' . (clone $sends)->count());
$line('   ...never sent (sent_at NULL) : ' . (clone $sends)->whereNull('sent_at')->count()
    . '   <-- v5.05 keeps these; before, a failure deleted its own row');
$last = (clone $sends)->whereNotNull('sent_at')->max('sent_at');
$line('   last successful send   : ' . ($last ?: 'NEVER'));
$line('   ALSO RUN IN THE SHELL  : supervisorctl status queue');

/* 6 ------------------------------------------------------------------------------------ */
$hd('CHECK 6', 'How far has products.stock drifted from the documents?  (P0-2)');
$bought = \Illuminate\Support\Facades\DB::table('order_costs')->where('tenant_id', $T)->where('stage', 'purchase')
    ->whereNull('voided_at')->whereNotNull('product_id')->where('qty', '>', 0)
    ->groupBy('product_id')->selectRaw('product_id, sum(qty) q')->pluck('q', 'product_id');
$n = 0; $hundred = 0; $zero = 0; $tot = 0; $worst = [];
foreach (\App\Models\Product::where('tenant_id', $T)->where('active', true)->get(['id', 'name', 'stock']) as $p) {
    $tot++;
    $col = (float) $p->stock;
    $doc = (float) ($bought[$p->id] ?? 0);
    if ((int) $p->stock === 100) $hundred++;
    if ($col <= 0) $zero++;
    if (abs($col - $doc) > 0.001) { $n++; $worst[] = [abs($col - $doc), $p->name, $col, $doc]; }
}
usort($worst, fn ($a, $b) => $b[0] <=> $a[0]);
$line('   active products                       : ' . $tot);
$line('   where products.stock != units purchased: ' . $n);
$line('   sitting at exactly 100 (the seed)      : ' . $hundred);
$line('   at or below zero (would have been hidden from customers): ' . $zero);
$line('   biggest disagreements:');
foreach (array_slice($worst, 0, 8) as $w) {
    $line('      ' . str_pad(mb_substr((string) $w[1], 0, 46), 48) . ' column=' . rtrim(rtrim(number_format($w[2], 2), '0'), '.')
        . '  purchased=' . rtrim(rtrim(number_format($w[3], 2), '0'), '.'));
}
$line('   storefront_show_stock setting : ' . var_export($t->setting('storefront_show_stock', false), true)
    . '   (v5.05 default false = availability not stated)');

/* 7 ------------------------------------------------------------------------------------ */
$hd('CHECK 7', 'Products whose two price columns disagree  (P1-8)');
$split = \App\Models\Product::where('tenant_id', $T)->where('active', true)
    ->whereNotNull('base_price')->whereColumn('base_price', '!=', 'price')->count();
$poa = \App\Models\Product::where('tenant_id', $T)->where('active', true)
    ->where(function ($q) { $q->whereNull('price')->orWhere('price', '<=', 0); })
    ->where('base_price', '>', 0)->count();
$line('   base_price != price            : ' . $split);
$line('   price empty but base_price set : ' . $poa . '   <-- these showed "Price on request" in the grid'
    . "\n" . '                                      and a real price on the product page. v5.05 makes both'
    . "\n" . '                                      follow the catalogue rule (price ?? base_price).');

/* 8 ------------------------------------------------------------------------------------ */
$hd('CHECK 8', 'Is web chat switched on?  (decides whether the /chat findings are live)');
$line('   web_chat      : ' . var_export($t->setting('web_chat', false), true));
$line('   web_chat_mode : ' . var_export($t->setting('web_chat_mode', $t->setting('bot_mode', 'auto')), true));
$line('   web_chat_contact : ' . var_export($t->setting('web_chat_contact', null), true));

/* 9 ------------------------------------------------------------------------------------ */
$hd('CHECK 9', 'Does MOQ exist as DATA, or only inside product names?');
$q = fn () => \App\Models\Product::where('tenant_id', $T)->where('active', true);
$namesMin = $q()->whereRaw("name ~* '(min|moq)'")->count();
$noMoq    = $q()->whereRaw("name ~* '(min|moq)'")->whereNull('moq')->count();
$namesCtn = $q()->whereRaw("name ~* 'carton of'")->count();
$noPack   = $q()->whereRaw("name ~* 'carton of'")->whereNull('pack_size')->count();
$line('   names stating a minimum          : ' . $namesMin . '   of which moq column is NULL: ' . $noMoq);
$line('   names stating a carton pack      : ' . $namesCtn . '   of which pack_size is NULL : ' . $noPack);
$line($noMoq > 0
    ? '   -> RUN: php artisan moq:backfill --tenant=17        (preview), then --write'
    : '   -> the moq column is populated; server-side MOQ enforcement has something to enforce.');

$line('');
$line(str_repeat('=', 78));
$line('Done. Nothing was written.');

        return self::SUCCESS;
    }
}
