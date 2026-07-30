<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Trust\TrustNotifier;
use App\Services\Trust\TrustRuntime;
use App\Services\WhatsApp\WhatsAppManager;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Inventory Trust — mint the day's working links (Phase B1).
 *
 *   php artisan trust:links --tenant=17 --receiving --supplier="Mukwano"   → receiving page URL
 *   php artisan trust:links --tenant=17 --count                            → today's blind-count URL
 *                                                                            (items picked by the engine)
 *   add --send to WhatsApp the link(s) to the tenant setting `trust_staff_numbers`
 *
 * Schedule the count link daily after trust:daily so the pick list is fresh (see the console
 * append file). The receiving link is minted per delivery — or keep one open link per location
 * by re-running with --receiving each morning.
 */
final class TrustLinksCommand extends Command
{
    protected $signature = 'trust:links
        {--tenant= : tenant id (required)}
        {--receiving : mint a receiving session link}
        {--supplier= : supplier name to pre-fill on the receiving page}
        {--location=kampala : stock location for receiving}
        {--count : mint today\'s blind-count link (engine-picked items)}
        {--picks=5 : count list size}
        {--send : WhatsApp the link(s) to trust_staff_numbers}';

    protected $description = 'Mint receiving / blind-count nonce links (and optionally WhatsApp them to staff).';

    public function handle(WhatsAppManager $wa, TenantContext $ctx): int
    {
        $id = (int) $this->option('tenant');
        if (! $id) {
            $this->warn('Pass --tenant=<id>. Your businesses:');
            foreach (Tenant::orderBy('id')->get() as $tt) {
                $this->line('  --tenant=' . $tt->id . '   ' . $tt->name);
            }
            return self::FAILURE;
        }
        $tenant = Tenant::findOrFail($id);
        $ctx->set($id);

        $base = rtrim((string) config('app.url'), '/');
        $links = [];

        if ($this->option('receiving')) {
            $nonce = Str::lower(Str::random(28));
            DB::table('trust_receiving_sessions')->insert([
                'tenant_id' => $id, 'nonce' => $nonce,
                'supplier_name' => (string) ($this->option('supplier') ?? ''),
                'location' => (string) $this->option('location'),
                'created_by' => 'trust:links', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $links[] = ['📦 Receiving' . ($this->option('supplier') ? ' — ' . $this->option('supplier') : ''),
                        $base . '/receive/' . $nonce];
        }

        if ($this->option('count')) {
            $picks = TrustRuntime::forTenant($tenant)->decayAndPick(max(1, (int) $this->option('picks')));
            if (! $picks) {
                $this->warn('Count: nothing to pick yet (no on-hand with confidence data). Run trust:daily first.');
            } else {
                $nonce = Str::lower(Str::random(28));
                DB::table('trust_count_sessions')->insert([
                    'tenant_id' => $id, 'nonce' => $nonce,
                    'picks' => json_encode(array_map(fn ($p) => [
                        'product_id' => $p['product_id'], 'location' => $p['location'], 'name' => $p['name'],
                    ], $picks)),
                    'created_by' => 'trust:links', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $links[] = ['🔢 Blind count (' . count($picks) . ' items)', $base . '/count/' . $nonce];
            }
        }

        if (! $links) {
            $this->warn('Nothing minted — pass --receiving and/or --count.');
            return self::FAILURE;
        }
        foreach ($links as [$label, $url]) {
            $this->info($label . ':  ' . $url);
        }

        if ($this->option('send')) {
            $numbers = array_filter(array_map('trim',
                preg_split('/[,\s]+/', (string) $tenant->setting('trust_staff_numbers', ''))));
            $instance = $tenant->whatsapp_instance ?? null;
            if ($instance && $numbers) {
                $gw = $wa->forTenant($tenant);
                $text = implode("\n", array_map(fn ($l) => $l[0] . "\n" . $l[1], $links));
                foreach ($numbers as $to) {
                    try {
                        $gw->sendText($instance, $to, $text);
                    } catch (\Throwable $e) {
                        Log::warning('trust:links send failed', ['to' => $to, 'err' => $e->getMessage()]);
                    }
                }
                $this->info('Sent to ' . count($numbers) . ' staff number(s).');
            } else {
                $this->warn('No WhatsApp instance or trust_staff_numbers configured — printed only.');
            }
        }
        return self::SUCCESS;
    }
}
