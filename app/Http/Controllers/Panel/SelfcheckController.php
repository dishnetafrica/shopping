<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET /papi/selfcheck — one JSON report of which CloudBSS add-ons are actually deployed on
 * THIS server, including the bot changes that have no screen. Reads deployed source markers,
 * class existence, DB tables and settings. Read-only; every probe is defensive.
 */
class SelfcheckController extends Controller
{
    private function fileHas(string $relPath, string $needle): ?bool
    {
        try {
            $p = base_path($relPath);
            if (! is_file($p)) return null;
            return str_contains((string) file_get_contents($p), $needle);
        } catch (\Throwable $e) { return null; }
    }

    public function run(Request $request)
    {
        $t = $request->user() ? $request->user()->tenant : null;
        $checks = [];
        $add = function (string $key, string $label, ?bool $ok, string $detail = '') use (&$checks) {
            $checks[] = ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
        };

        // --- Panel / consignment ------------------------------------------------------
        try {
            $t1 = Schema::hasTable('shipment_orders');
            $t2 = Schema::hasTable('consignment_stock');
            $t3 = $t2 && Schema::hasColumn('consignment_stock', 'unit_cost');
            $add('consignment_migrated', 'Consignment tables migrated', $t1 && $t2 && $t3,
                $t1 && $t2 && $t3 ? '' : 'run: php artisan migrate --force');
        } catch (\Throwable $e) { $add('consignment_migrated', 'Consignment tables migrated', null, 'probe error'); }

        $add('consignment_code', 'Consignment code deployed',
            class_exists(\App\Http\Controllers\Panel\ConsignmentController::class) && class_exists(\App\Models\ConsignmentStock::class));
        $add('lastmile_bridge', 'Shipment→delivery bridge (LastMileBridge)',
            class_exists(\App\Services\Logistics\LastMileBridge::class));
        $add('ads_screen', 'Facebook Ads screen',
            class_exists(\App\Http\Controllers\Panel\AdsController::class) && is_file(resource_path('panel/ads.html')));

        // --- "In transit" status ------------------------------------------------------
        try {
            $it = in_array('In transit', \App\Models\Order::STATUSES, true);
            $add('in_transit', '"In transit" order status', $it);
        } catch (\Throwable $e) { $add('in_transit', '"In transit" order status', null, 'probe error'); }

        // --- Cashbook supplier/on-account --------------------------------------------
        $add('cashbook_supplier', 'Cashbook supplier + on-account payment',
            (bool) $this->fileHas('app/Http/Controllers/Panel/TradeCashbookController.php', 'supplier_payment'));

        // --- Sourcing: auto-ask Jayesh + finalise into catalogue ----------------------
        try {
            $s1 = Schema::hasColumn('sourcing_requests', 'reminder_count');
            $s2 = Schema::hasColumn('sourcing_requests', 'product_id');
            $s3 = Schema::hasColumn('sourcing_requests', 'decision_note');
            $add('sourcing_migrated', 'Sourcing tables migrated', $s1 && $s2 && $s3,
                $s1 && $s2 && $s3 ? '' : 'run: php artisan migrate --force');
        } catch (\Throwable $e) { $add('sourcing_migrated', 'Sourcing tables migrated', null, 'probe error'); }
        $sc = $this->fileHas('app/Http/Controllers/Panel/SourcingController.php', 'sendToBuyer');
        $scv = $this->fileHas('app/Http/Controllers/Panel/SourcingController.php', 'function convert');
        $srn = $this->fileHas('app/Http/Controllers/Panel/SourcingController.php', 'function renegotiate');
        $add('sourcing_code', 'Sourcing auto-ask + catalogue + renegotiate code',
            ($sc && $scv && $srn) ? true : (($sc === null) ? null : false));
        $add('sourcing_reply_code', 'Buyer reply-to-price (WhatsApp) code',
            (bool) $this->fileHas('app/Jobs/ProcessIncomingMessage.php', 'sourcing_buyer_phone'));
        $add('sourcing_remind_cmd', 'Sourcing reminder command',
            class_exists(\App\Console\Commands\SourcingRemindCommand::class));
        if ($t) {
            $bp = preg_replace('/[^0-9]/', '', (string) $t->setting('sourcing_buyer_phone', ''));
            $add('sourcing_buyer_set', 'Buyer (Jayesh) WhatsApp set', $bp !== '' ? true : null,
                $bp !== '' ? $bp : 'set it on /panel/sourcing');
        }

        // --- Bot patch (no screen — detect from deployed source) ----------------------
        $adGate = $this->fileHas('app/Services/WhatsApp/EvolutionGateway.php', 'externalAdReply');
        $adJob  = $this->fileHas('app/Jobs/ProcessIncomingMessage.php', 'bot_ad_awareness');
        $add('bot_ad_code', 'Bot Facebook-ad awareness code',
            ($adGate && $adJob) ? true : (($adGate === null || $adJob === null) ? null : false),
            ($adGate && $adJob) ? '' : 'deploy bot patch + rebuild (restarts queue worker)');
        $moqBrain = $this->fileHas('app/Services/Bot/BotBrain.php', 'moq_product');
        $moqFaq   = $this->fileHas('app/Services/Bot/FaqDictionary.php', 'moq_product');
        $add('bot_moq_code', 'Bot product-specific MOQ code',
            ($moqBrain && $moqFaq) ? true : (($moqBrain === null || $moqFaq === null) ? null : false));

        // --- Settings + live signal ---------------------------------------------------
        if ($t) {
            $add('bot_ad_awareness_on', 'Ad understanding switched ON',
                (bool) $t->setting('bot_ad_awareness', true),
                ((bool) $t->setting('bot_ad_awareness', true)) ? '' : 'turn on at /panel/ads');
            $add('bot_mode', 'Bot mode', true, (string) $t->setting('bot_mode', 'auto'));
            try {
                $n = (int) DB::table('bot_events')->where('tenant_id', $t->id)
                    ->where('stage', 'ad_referral')->where('created_at', '>=', now()->subDays(7))->count();
                $add('ad_arrivals_7d', 'Ad arrivals seen (7 days)', $n > 0 ? true : null, (string) $n);
            } catch (\Throwable $e) { $add('ad_arrivals_7d', 'Ad arrivals seen (7 days)', null, 'no bot_events'); }
        }

        $passed = count(array_filter($checks, fn ($c) => $c['ok'] === true));
        return response()->json([
            'ok'      => true,
            'checks'  => $checks,
            'summary' => ['passed' => $passed, 'total' => count($checks)],
        ]);
    }
}
