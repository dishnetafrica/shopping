<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-command end-to-end check that onboarding -> learning -> report actually works.
 *
 *   php artisan onboarding:verify              # simulate a fresh shop, assert, then clean up
 *   php artisan onboarding:verify --keep       # keep the throwaway tenant for inspection
 *   php artisan onboarding:verify --tenant=2   # run the pipeline on a REAL tenant's data
 *
 * Simulation mode seeds a throwaway tenant with a known catalogue + WhatsApp history (real
 * products, sales-pattern exchanges, FAQs and some noise), runs the exact production pipeline
 * (30-day Discovery scan -> Go-Live readiness -> team consensus), and asserts the system learned
 * correctly and built a report — then deletes everything it created.
 */
class OnboardingVerifyCommand extends Command
{
    protected $signature = 'onboarding:verify {--tenant= : Run against a real tenant instead of a simulated one} {--keep : Do not delete the simulated tenant afterwards}';
    protected $description = 'Verify the onboarding -> discovery -> learning -> report pipeline end to end';

    private int $pass = 0;
    private int $fail = 0;

    public function handle(): int
    {
        $real = $this->option('tenant');
        return $real ? $this->runReal((int) $real) : $this->runSimulated();
    }

    /* ------------------------------------------------------------------ simulated */

    private function runSimulated(): int
    {
        $this->line('Simulating a fresh onboarding…');
        $tenant = Tenant::create([
            'name'   => 'ONBOARDING-VERIFY',
            'slug'   => 'onboarding-verify-' . substr(md5((string) microtime(true)), 0, 6),
            'status' => 'active',
            'plan'   => 'trial',
        ]);
        app(TenantContext::class)->set($tenant->id);

        try {
            $this->seed($tenant);

            $scanner   = app(\App\Services\Bot\Discovery\DiscoveryScanner::class);
            $readiness = app(\App\Services\Bot\Readiness\ReadinessService::class);
            $company   = app(\App\Services\Bot\Company\CompanyDiscoveryService::class);

            // ---- exact production onboarding pipeline ----
            $discovery = $scanner->scan($tenant, 5000, 30);          // Phase-1 30-day scan
            $report    = $readiness->evaluate($tenant);              // Go-Live readiness
            try { $company->discover($tenant, true); } catch (\Throwable $e) {}

            $sec       = is_array($discovery->report) ? ($discovery->report['sections'] ?? []) : [];
            $prodNames = array_map(fn ($p) => $p['name'] ?? '', $sec['top_products'] ?? []);
            $sales     = $sec['sales_patterns']['by_type'] ?? [];
            $cat       = ['Jalebi', 'Fafda', 'Kaju Katri', 'Kathiyawadi Thali', 'Khaman'];

            $this->newLine();
            $this->line('Learned — top products: <info>' . implode(', ', $prodNames) . '</info>');
            $this->line('Learned — sales patterns: <info>' . implode(', ', array_keys($sales)) . '</info>');
            $this->line('Readiness: <info>' . ($report ? $report->overall_score . '% → ' . $report->recommended_mode : 'none') . '</info>');
            $this->newLine();

            // ---- discovery / learning ----
            $this->assert('Discovery scan created', $discovery && $discovery->exists);
            $this->assert('Scan is pending (not auto-live)', ($discovery->status ?? '') === 'pending');
            $this->assert('Used 30-day onboarding window', ($discovery->report['window_days'] ?? null) === 30);
            $this->assert('Messages were analysed', (int) $discovery->sample_messages > 0);
            $this->assert('≥3 catalogue products learned', count($prodNames) >= 3);
            $this->assert('All top products are real catalogue items', count(array_diff($prodNames, $cat)) === 0);
            $this->assert('No generic words leaked into products', count(array_intersect($prodNames, ['Https', 'More', 'Info', 'Good', 'What', 'Com'])) === 0);
            $this->assert('Languages detected', ! empty($sec['languages']));
            $this->assert('FAQs detected', ! empty($sec['faqs']));
            $this->assert('Delivery areas valid (no junk)', $this->areasOk($sec['delivery']['areas'] ?? []));

            // ---- sales behaviour ----
            $this->assert('Sales patterns learned (≥3 types)', count($sales) >= 3);
            $this->assert('Upsell or cross-sell learned', isset($sales['upsell']) || isset($sales['cross_sell']));
            $this->assert('Questions checklist built', ! empty($sec['sales_patterns']['questions']));

            // ---- report generation ----
            $this->assert('Go-Live readiness report created', $report !== null);
            $this->assert('Readiness score in 0–100', $report && $report->overall_score >= 0 && $report->overall_score <= 100);
            $this->assert('Recommended mode set', $report && $report->recommended_mode !== '');

            // ---- persistence ----
            $this->assert('Business DNA persisted', \App\Models\BusinessDiscovery::where('tenant_id', $tenant->id)->exists());
            if (class_exists(\App\Models\CompanyDna::class)) {
                $this->assert('Team snapshot persisted', \App\Models\CompanyDna::where('tenant_id', $tenant->id)->exists());
            }
        } catch (\Throwable $e) {
            $this->fail++;
            $this->error('Pipeline threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        } finally {
            if ($this->option('keep')) {
                $this->warn("Kept simulated tenant #{$tenant->id} (--keep). Remove it manually when done.");
            } else {
                $this->teardown($tenant);
                $this->line('Cleaned up the simulated tenant.');
            }
        }

        return $this->summary();
    }

    /* ------------------------------------------------------------------ real tenant */

    private function runReal(int $tenantId): int
    {
        $tenant = Tenant::find($tenantId);
        if (! $tenant) { $this->error("Tenant {$tenantId} not found."); return self::FAILURE; }
        app(TenantContext::class)->set($tenant->id);
        $this->warn("Running the live pipeline on tenant #{$tenant->id} ({$tenant->name}). This creates a real PENDING discovery + readiness report (nothing goes live).");

        try {
            $discovery = app(\App\Services\Bot\Discovery\DiscoveryScanner::class)->scan($tenant, 5000, 30);
            $report    = app(\App\Services\Bot\Readiness\ReadinessService::class)->evaluate($tenant);
            try { app(\App\Services\Bot\Company\CompanyDiscoveryService::class)->discover($tenant, true); } catch (\Throwable $e) {}

            $sec       = is_array($discovery->report) ? ($discovery->report['sections'] ?? []) : [];
            $prodNames = array_map(fn ($p) => $p['name'] ?? '', $sec['top_products'] ?? []);

            $this->newLine();
            $this->line('Messages analysed: <info>' . $discovery->sample_messages . '</info>');
            $this->line('Top products: <info>' . (implode(', ', $prodNames) ?: '(none — empty catalogue?)') . '</info>');
            $this->line('Sales patterns: <info>' . implode(', ', array_keys($sec['sales_patterns']['by_type'] ?? [])) . '</info>');
            $this->line('Readiness: <info>' . ($report ? $report->overall_score . '% → ' . $report->recommended_mode : 'none') . '</info>');
            $this->newLine();

            $this->assert('Discovery scan created', $discovery && $discovery->exists);
            $this->assert('Messages were analysed', (int) $discovery->sample_messages > 0);
            $this->assert('No generic words in products', count(array_intersect($prodNames, ['Https', 'More', 'Info', 'Good', 'What', 'Com', 'Juba', 'Message'])) === 0);
            $this->assert('Delivery areas valid (no junk)', $this->areasOk($sec['delivery']['areas'] ?? []));
            $this->assert('Go-Live readiness report created', $report !== null);
            $this->assert('Readiness score in 0–100', $report && $report->overall_score >= 0 && $report->overall_score <= 100);
            if (empty($prodNames)) {
                $this->warn('No products learned — tenant likely has an empty catalogue. That is correct behaviour (no garbage), not a failure.');
            }
        } catch (\Throwable $e) {
            $this->fail++;
            $this->error('Pipeline threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }

        return $this->summary();
    }

    /* ------------------------------------------------------------------ helpers */

    private function seed(Tenant $tenant): void
    {
        foreach (['Jalebi' => 'Sweets', 'Fafda' => 'Farsan', 'Kaju Katri' => 'Sweets', 'Kathiyawadi Thali' => 'Thali', 'Khaman' => 'Farsan'] as $name => $cat) {
            \App\Models\Product::create(['tenant_id' => $tenant->id, 'name' => $name, 'category' => $cat,
                'keywords' => strtolower(explode(' ', $name)[0]), 'price' => 5000, 'active' => true]);
        }

        $cust = [
            'I want jalebi', 'fresh fafda today?', 'kaju katri price', 'thali available', 'khaman hot please',
            'jalebi 1kg', 'do you have fafda', 'kaju katri 500g', 'https check our status', 'more info on link', 'good good',
        ];
        $pairs = [
            ['i need sweets for a party', 'For how many people?'],
            ['i want sweets for guests', 'For how many people?'],
            ['i want jalebi', 'Kaju katri is better value, take the combo'],
            ['i want a jalebi box', 'kaju katri is better value for gifting'],
            ['price of fafda', 'we have a smaller pack, cheaper'],
            ['kaju katri is too expensive', 'we have a cheaper pack option'],
            ['do you deliver', 'we deliver to Kololo, our rider brings it'],
            ['can you deliver to ntinda', 'we deliver to Ntinda, rider brings it same day'],
            ['how to order', 'share your location and pay via momo'],
            ['i want to buy now', 'share your location, payment via mobile money'],
            ['bulk order for wedding', 'let me check and call you back'],
            ['i need 50 boxes', 'our manager will call you to confirm'],
            ['what are your prices', 'Prices start at UGX 5,000 per box'],
            ['are you open today', 'Yes, open 9am to 8pm'],
            ['where are you located', 'We are in Juba town centre'],
            ['payment methods?', 'Cash or mobile money'],
        ];

        $i = 0;
        $put = function (string $dir, string $body, string $sender = '') use ($tenant, &$i) {
            $i++;
            $at = now()->subDays(15 - intdiv($i, 4))->setTime(9 + ($i % 11), ($i * 7) % 60);
            $m = new \App\Models\Message();
            $m->forceFill([
                'tenant_id'      => $tenant->id,
                'customer_phone' => '+211900000001',
                'direction'      => $dir,
                'sender'         => $sender,
                'body'           => $body,
                'status'         => 'received',
                'created_at'     => $at,
                'updated_at'     => $at,
            ])->save();
        };

        foreach ($cust as $c) $put('in', $c);
        foreach ($pairs as [$c, $o]) { $put('in', $c); $put('out', $o, 'Owner'); }

        foreach (['Jalebi 500g', 'Fafda', 'Kaju Katri'] as $item) {
            $o = new \App\Models\Order();
            $o->forceFill([
                'tenant_id'  => $tenant->id,
                'order_no'   => 'VERIFY-' . strtoupper(substr(md5($item . microtime()), 0, 5)),
                'items_json' => [['name' => $item, 'qty' => 1]],
                'items_text' => $item,
                'total'      => 5000,
                'status'     => 'delivered',
                'created_at' => now()->subDays(5),
                'updated_at' => now()->subDays(5),
            ])->save();
        }
    }

    private function teardown(Tenant $tenant): void
    {
        $id = $tenant->id;
        foreach ([
            \App\Models\Message::class, \App\Models\Order::class, \App\Models\Product::class,
            \App\Models\BusinessDiscovery::class, \App\Models\GoLiveReport::class,
        ] as $model) {
            try { $model::where('tenant_id', $id)->forceDelete(); }
            catch (\Throwable $e) { try { $model::where('tenant_id', $id)->delete(); } catch (\Throwable $e2) {} }
        }
        foreach (['CompanyDna', 'CompanyMemory', 'EmployeeMemory', 'ActivityFeedItem'] as $opt) {
            $cls = "App\\Models\\{$opt}";
            if (class_exists($cls)) { try { $cls::where('tenant_id', $id)->delete(); } catch (\Throwable $e) {} }
        }
        foreach (['activity_review_queue', 'go_live_reports', 'business_discovery'] as $tbl) {
            try { if (DB::getSchemaBuilder()->hasTable($tbl)) DB::table($tbl)->where('tenant_id', $id)->delete(); } catch (\Throwable $e) {}
        }
        try { $tenant->forceDelete(); } catch (\Throwable $e) { try { $tenant->delete(); } catch (\Throwable $e2) {} }
    }

    private function areasOk(array $areas): bool
    {
        $bad = ['time', 'dinner', 'lunch', 'morning', 'evening', 'night', 'today', 'tomorrow', 'now', 'minutes', 'hours'];
        foreach ($areas as $a) {
            foreach (explode(' ', strtolower(trim((string) $a))) as $w) {
                if (in_array($w, $bad, true)) return false;
            }
        }
        return true;
    }

    private function assert(string $label, bool $ok): void
    {
        $ok ? $this->pass++ : $this->fail++;
        $this->line(($ok ? '  <info>✓</info> ' : '  <error>✗</error> ') . $label);
    }

    private function summary(): int
    {
        $this->newLine();
        $total = $this->pass + $this->fail;
        if ($this->fail === 0) {
            $this->info("ONBOARDING VERIFIED — {$this->pass}/{$total} checks passed. Learning + report generation work.");
            return self::SUCCESS;
        }
        $this->error("ONBOARDING CHECK FAILED — {$this->pass}/{$total} passed, {$this->fail} failed. See ✗ above.");
        return self::FAILURE;
    }
}
