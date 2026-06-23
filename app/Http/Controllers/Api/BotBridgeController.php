<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\MessageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Bridge between the shared n8n smart-bot and CloudBSS. n8n never touches Evolution; it posts
 * its decisions here and CloudBSS sends + logs them. Every call is authenticated by the tenant's
 * shared secret (X-CloudBSS-Secret) and only works while the tenant is actually on bot_mode=n8n,
 * so a leaked URL can't drive arbitrary sends.
 */
class BotBridgeController extends Controller
{
    /** Resolve + authorise a tenant from the request. Returns null on any failure. */
    private function authTenant(Request $r, int $tenantId): ?Tenant
    {
        if ($tenantId <= 0) return null;
        $tenant = Tenant::find($tenantId);
        if (! $tenant) return null;

        $given    = (string) $r->header('X-CloudBSS-Secret', '');
        $expected = (string) $tenant->setting('n8n_secret', '');
        if ($expected === '' || ! hash_equals($expected, $given)) return null;
        if ((string) $tenant->setting('bot_mode', '') !== 'n8n') return null;

        return $tenant;
    }

    private function digits(?string $p): string
    {
        return preg_replace('/[^0-9]/', '', (string) $p);
    }

    /** n8n → CloudBSS: send one reply to the customer. */
    public function reply(Request $r)
    {
        $tenant = $this->authTenant($r, (int) $r->input('tenant_id'));
        if (! $tenant) return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);

        $phone = $this->digits((string) $r->input('phone'));
        $text  = trim((string) $r->input('text'));
        if ($phone === '' || $text === '') {
            return response()->json(['ok' => false, 'error' => 'phone and text required'], 422);
        }

        try {
            $gw  = app(WhatsAppManager::class)->forTenant($tenant);
            $res = $gw->sendText($tenant->whatsapp_instance, $phone, $text);
            MessageLog::record($tenant->id, $phone, $tenant->whatsapp_instance, 'out', 'bot', $text, $res['messageId'] ?? null, null, ['via' => 'n8n']);
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'send_failed'], 502);
        }
    }

    /** n8n → CloudBSS: send a staff alert to one or more numbers. */
    public function alert(Request $r)
    {
        $tenant = $this->authTenant($r, (int) $r->input('tenant_id'));
        if (! $tenant) return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);

        $to   = $r->input('to', []);
        $to   = is_array($to) ? $to : [$to];
        $text = trim((string) $r->input('text'));
        if ($text === '') return response()->json(['ok' => false, 'error' => 'text required'], 422);

        $recipients = array_values(array_unique(array_filter(array_map(fn ($p) => $this->digits((string) $p), $to))));
        if (! $recipients) return response()->json(['ok' => false, 'error' => 'no recipients'], 422);

        // Fire-once: if n8n passes a dedupe_key, only the first call within the TTL actually sends.
        // Cache::add is atomic, so concurrent duplicates collapse to one alert.
        $dedupeKey = trim((string) $r->input('dedupe_key', ''));
        if ($dedupeKey !== '') {
            $ttl = max(30, (int) $r->input('dedupe_ttl', 3600));
            if (! Cache::add("bot_alert_once:{$tenant->id}:{$dedupeKey}", 1, $ttl)) {
                return response()->json(['ok' => true, 'sent' => 0, 'deduped' => true]);
            }
        }

        $sent = 0;
        try {
            $gw = app(WhatsAppManager::class)->forTenant($tenant);
            foreach ($recipients as $num) {
                $res = $gw->sendText($tenant->whatsapp_instance, $num, $text);
                MessageLog::record($tenant->id, $num, $tenant->whatsapp_instance, 'out', 'system', $text, $res['messageId'] ?? null, null, ['via' => 'n8n', 'kind' => 'alert']);
                $sent++;
            }
        } catch (\Throwable $e) {
            // partial success is fine — report what went out
        }
        return response()->json(['ok' => true, 'sent' => $sent]);
    }

    /** n8n → CloudBSS: pull the tenant's catalogue to cache locally for instant price answers. */
    public function catalog(Request $r, int $tenant)
    {
        $t = $this->authTenant($r, $tenant);
        if (! $t) return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);

        $products = Cache::remember("bot_catalog:{$t->id}", 60, function () use ($t) {
            return \App\Models\Product::withoutGlobalScopes()
                ->where('tenant_id', $t->id)
                ->where('active', true)
                ->orderBy('name')
                ->get()
                ->map(fn ($p) => [
                    'id'        => $p->id,
                    'name'      => (string) $p->name,
                    'price'     => (float) $p->price,
                    'unit'      => (string) ($p->unit_label ?? ''),
                    'pack_size' => $p->pack_size ? (int) $p->pack_size : null,
                    'moq'       => $p->moq ? (int) $p->moq : null,
                    'stock'     => $p->stock,
                    'category'  => (string) ($p->category ?? ''),
                ])->all();
        });

        return response()->json(['ok' => true, 'tenant_id' => $t->id, 'count' => count($products), 'products' => $products]);
    }
}
