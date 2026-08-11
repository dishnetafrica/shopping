<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * wa:test — can a given shop actually DELIVER a WhatsApp message to a given number?
 *
 * This exists because three different things look identical from the outside:
 *
 *   1. Evolution says the instance is "open".        Necessary. Nowhere near sufficient.
 *   2. Evolution returns HTTP 201 for the send.      Means Evolution TOOK it. Not that WhatsApp sent it.
 *   3. WhatsApp returns a delivery receipt.          The only one that means anything.
 *
 * On 2026-08-11 tenant 17 had (1) and (2) all morning while every single message was refused.
 *
 * THE WARM/COLD DISTINCTION IS THE POINT OF THIS TOOL.
 *
 * Error 463 (NackCallerReachoutTimelocked) fires for two unrelated reasons:
 *
 *   - COLD: the shop is messaging someone who has never messaged it. This is normal
 *     anti-spam behaviour and says nothing about the shop's health.
 *   - WARM: the shop is replying to an existing conversation and is STILL refused. That
 *     is the pathological case — it means the session has lost its privacy tokens.
 *
 * Comparing a warm failure against a cold failure and concluding "both broken" is wrong,
 * and comparing a healthy shop's COLD failure against a sick shop's WARM failure and
 * concluding "same fault" is wronger. So every result below is labelled, and the summary
 * refuses to draw a comparison between tests of different temperature.
 *
 *   php artisan wa:test 211927797217                  # tenant 17 (default)
 *   php artisan wa:test 211927797217 --tenant=7       # one specific shop
 *   php artisan wa:test 211927797217 --all            # every shop with an instance, compared
 *   php artisan wa:test 211927797217 --all --dry      # readiness table only, sends NOTHING
 *
 * Exit code 0 = at least one warm test delivered. 1 = nothing delivered warm.
 */
class WaTestCommand extends Command
{
    protected $signature = 'wa:test
        {number : Destination in international format, digits only (e.g. 211927797217)}
        {--tenant= : Tenant id to send from (default 17)}
        {--all : Send from every tenant that has an instance}
        {--wait=45 : Seconds to wait for a delivery receipt}
        {--dry : Show the readiness table and send nothing}';

    protected $description = 'Send a real WhatsApp test message and wait for the delivery receipt';

    /** Every send below is thrown away if the number is not plausible. */
    public function handle(WhatsAppManager $wa): int
    {
        $to = preg_replace('/\D/', '', (string) $this->argument('number'));
        if (strlen($to) < 8) {
            $this->error('Number looks wrong: need digits in international format, e.g. 211927797217');
            return 2;
        }

        $tenants = $this->resolveTenants();
        if ($tenants->isEmpty()) {
            $this->error('No tenant to test.');
            return 2;
        }

        $evo = $this->evolutionInstances();

        $this->line('');
        $this->line("  Target: {$to}");
        $this->line('');
        $this->line(sprintf('  %-4s %-22s %-16s %-11s %-6s %s', 'id', 'shop', 'instance', 'evolution', 'temp', 'prior contact'));
        $this->line('  ' . str_repeat('-', 86));

        $plan = [];
        foreach ($tenants as $t) {
            $instance = (string) ($t->whatsapp_instance ?? '');
            $status   = $instance === '' ? 'no instance' : ($evo[$instance] ?? 'NOT IN EVOLUTION');
            $prior    = $this->priorContact($t->id, $to);
            $temp     = $prior > 0 ? 'WARM' : 'cold';

            $this->line(sprintf(
                '  %-4d %-22s %-16s %-11s %-6s %s',
                $t->id,
                mb_substr((string) $t->name, 0, 21),
                $instance !== '' ? $instance : '—',
                $status,
                $temp,
                $prior > 0 ? "{$prior} events" : 'never — cold reach-out'
            ));

            if ($instance !== '' && $status !== 'NOT IN EVOLUTION' && $status !== 'no instance') {
                $plan[] = ['tenant' => $t, 'instance' => $instance, 'warm' => $prior > 0];
            }
        }

        $this->line('');

        if (! $plan) {
            $this->warn('  Nothing testable — no tenant has a live instance in Evolution.');
            return 2;
        }

        if ($this->option('dry')) {
            $this->comment('  --dry: nothing was sent.');
            return 0;
        }

        $cold = count(array_filter($plan, fn ($p) => ! $p['warm']));
        if ($cold > 0) {
            $this->warn("  {$cold} of " . count($plan) . " test(s) are COLD reach-outs. A 463 refusal there is");
            $this->warn('  expected anti-spam behaviour and does NOT indicate a broken shop.');
            $this->line('');
        }

        $results = [];
        foreach ($plan as $p) {
            $results[] = $this->runOne($wa, $p['tenant'], $p['instance'], $to, $p['warm']);
        }

        return $this->summarise($results);
    }

    /** --all, --tenant=N, or 17. */
    private function resolveTenants()
    {
        if ($this->option('all')) {
            return Tenant::query()->orderBy('id')->get()
                ->filter(fn ($t) => (string) ($t->whatsapp_instance ?? '') !== '')
                ->values();
        }

        $id = (int) ($this->option('tenant') ?: 17);
        return Tenant::query()->where('id', $id)->get();
    }

    /**
     * Instance name => connectionStatus, straight from Evolution.
     * A tenant row pointing at an instance Evolution has never heard of is a real and
     * silent failure mode — tenant 17 had a stale `shopbot_t17_3` channel row exactly
     * like that — so absence is reported rather than treated as offline.
     */
    private function evolutionInstances(): array
    {
        $base = (string) config('whatsapp.drivers.evolution.base_url');
        $key  = (string) config('whatsapp.drivers.evolution.api_key');
        if ($base === '' || $key === '') return [];

        try {
            $r = Http::withHeaders(['apikey' => $key])->timeout(20)->get(rtrim($base, '/') . '/instance/fetchInstances');
            if (! $r->successful()) return [];
            $out = [];
            foreach ((array) $r->json() as $row) {
                $i = $row['instance'] ?? $row;
                $name = $i['instanceName'] ?? ($i['name'] ?? null);
                if ($name) $out[$name] = (string) ($i['connectionStatus'] ?? ($i['status'] ?? '?'));
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Has this shop ever exchanged anything with this number? Decides warm vs cold. */
    private function priorContact(int $tenantId, string $phone): int
    {
        $n = 0;
        try {
            $n += (int) DB::table('bot_events')->where('tenant_id', $tenantId)->where('phone', $phone)->count();
        } catch (\Throwable $e) { /* table optional */ }
        try {
            $n += (int) DB::table('messages')->where('tenant_id', $tenantId)
                ->where(fn ($q) => $q->where('phone', $phone)->orWhere('wa_id', 'like', "%{$phone}%"))
                ->count();
        } catch (\Throwable $e) { /* table or columns may differ */ }
        return $n;
    }

    /** One send, then watch for the receipt that decides it. */
    private function runOne(WhatsAppManager $wa, Tenant $t, string $instance, string $to, bool $warm): array
    {
        $label = "{$t->id} ({$instance})";
        $this->line("  → sending from {$label} …");

        $before = $this->failureCount($t->id, $to);
        $text   = 'ShopBot delivery test ' . now()->format('H:i:s') . ' — please ignore.';

        try {
            $res = $wa->forTenant($t)->sendText($instance, $to, $text);
        } catch (\Throwable $e) {
            $this->line('     <fg=red>send threw:</> ' . mb_substr($e->getMessage(), 0, 160));
            return ['tenant' => $t->id, 'warm' => $warm, 'verdict' => 'ERROR', 'detail' => $e->getMessage()];
        }

        // Evolution answers 201 with a key/status. That is ACCEPTANCE, not delivery — it
        // answered 201 for every one of the 142 messages it refused on 11 Aug.
        $accepted = is_array($res) && ! isset($res['error']) && (isset($res['key']) || isset($res['messages']) || isset($res['id']) || isset($res['status']));
        $this->line('     accepted by gateway: ' . ($accepted ? 'yes' : 'unclear — ' . mb_substr(json_encode($res), 0, 140)));

        $wait = max(5, (int) $this->option('wait'));
        $this->output->write('     waiting for receipt ');

        $waited = 0;
        while ($waited < $wait) {
            sleep(3);
            $waited += 3;
            $this->output->write('.');

            if ($this->failureCount($t->id, $to) > $before) {
                $this->line(' <fg=red>REFUSED</> after ' . $waited . 's');
                return ['tenant' => $t->id, 'warm' => $warm, 'verdict' => 'REFUSED', 'detail' => "after {$waited}s"];
            }
        }

        $this->line(' no refusal in ' . $waited . 's');
        return ['tenant' => $t->id, 'warm' => $warm, 'verdict' => 'NO_REFUSAL', 'detail' => "waited {$waited}s"];
    }

    private function failureCount(int $tenantId, string $phone): int
    {
        try {
            return (int) DB::table('bot_events')
                ->where('tenant_id', $tenantId)->where('stage', 'send_failed')->where('phone', $phone)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * The verdict, stated in terms of what was actually observed.
     *
     * NO_REFUSAL is deliberately not called "delivered". Absence of a rejection receipt is
     * weaker evidence than presence of one: the receipt path itself can be down. The only
     * proof of delivery is the handset, so that is what it asks for.
     */
    private function summarise(array $results): int
    {
        $this->line('');
        $this->line('  RESULT');

        $warm = array_values(array_filter($results, fn ($r) => $r['warm']));
        $cold = array_values(array_filter($results, fn ($r) => ! $r['warm']));

        foreach ($results as $r) {
            $tag = $r['warm'] ? 'WARM' : 'cold';
            $this->line(sprintf('    tenant %-4d %-6s %-11s %s', $r['tenant'], $tag, $r['verdict'], $r['detail']));
        }

        $this->line('');

        if (! $warm) {
            $this->warn('  Every test was a COLD reach-out. Nothing here can tell you whether a shop is');
            $this->warn('  healthy — cold refusals are normal. Re-run against a number that has actually');
            $this->warn('  messaged the shop before.');
            return 1;
        }

        $warmRefused  = array_filter($warm, fn ($r) => $r['verdict'] === 'REFUSED');
        $warmSurvived = array_filter($warm, fn ($r) => $r['verdict'] === 'NO_REFUSAL');

        if ($warmSurvived) {
            $ids = implode(', ', array_column($warmSurvived, 'tenant'));
            $this->info("  No rejection for tenant(s) {$ids} on a WARM contact.");
            $this->line('  That is the first good sign — but it is not proof. Check the handset before');
            $this->line('  believing it: a silent receipt path looks identical to a successful send.');
        }

        if ($warmRefused) {
            $ids = implode(', ', array_column($warmRefused, 'tenant'));
            $this->error("  Tenant(s) {$ids} were REFUSED on a WARM contact.");
            $this->line('  That is the pathological case: replying to an existing conversation and still');
            $this->line('  being refused means the session has lost its privacy tokens. Do NOT delete and');
            $this->line('  recreate the instance — that is what causes this, and it resets the clock.');
        }

        if ($cold) {
            $this->line('');
            $this->comment('  Cold results above are excluded from this verdict by design.');
        }

        return $warmSurvived ? 0 : 1;
    }
}
