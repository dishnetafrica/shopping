<?php

namespace App\Console\Commands;

use App\BOS\Bos;
use App\Models\CustomerPayment;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BOS v1.0 Slice 1.5 — Demo Company. Seeds ONE dedicated demo tenant with realistic data so every
 * later slice is developed and tested against real-looking volume, not toy examples.
 *
 * SAFE BY DESIGN:
 *   - Only ever touches a tenant with slug 'bos-demo' (found or created). It NEVER writes to your live
 *     tenant. `--purge` deletes the demo tenant and all its rows.
 *   - Skips Sales Orders on purpose (creating them fires OrderObserver notifications). Invoices are
 *     created directly (source='demo'); customers/products/payments have no such side effects.
 *   - Dependency-free (no faker) so it runs on a production build. Emits real BOS events
 *     (CustomerCreated / InvoiceIssued / PaymentReceived) with historical dates, so the Customer
 *     Timeline (Slice 2) and Insights have data to render.
 *
 *   php artisan bos:demo                 # seed (idempotent — refuses if already seeded)
 *   php artisan bos:demo --fresh         # purge then re-seed
 *   php artisan bos:demo --purge         # remove the demo tenant entirely
 *   php artisan bos:demo --products=500 --customers=100 --invoices=300
 */
class BosDemoCommand extends Command
{
    protected $signature = 'bos:demo {--purge} {--fresh} {--products=500} {--customers=100} {--invoices=300}';
    protected $description = 'Seed (or purge) the BOS demo company — a dedicated demo tenant with realistic data';

    private const SLUG = 'bos-demo';

    private array $ADJ  = ['Golden', 'Royal', 'Fresh', 'Prime', 'Sunrise', 'Nile', 'Star', 'Green', 'Pearl', 'Savanna'];
    private array $NOUN = ['Rice', 'Sugar', 'Cooking Oil', 'Maize Flour', 'Beans', 'Salt', 'Soap', 'Tea', 'Wheat Flour', 'Foil', 'Napkins', 'Water', 'Juice', 'Milk Powder'];
    private array $SIZE = ['1kg', '5L', '500g', '24pc', '10kg', '250ml', '2kg', '20L'];
    private array $CO_A = ['Nile', 'Pearl', 'Unity', 'Bright', 'Equator', 'Victoria', 'Nakumatt', 'Juba', 'Kampala', 'Savanna'];
    private array $CO_B = ['Traders', 'Supermarket', 'Wholesalers', 'Stores', 'Enterprises', 'Distributors', 'Mini-Mart', 'Cash & Carry'];
    private array $FIRST = ['John', 'Grace', 'Peter', 'Amina', 'David', 'Sarah', 'Moses', 'Fatima', 'James', 'Rose', 'Deng', 'Nyandeng'];
    private array $LAST  = ['Okello', 'Nakato', 'Deng', 'Wani', 'Kato', 'Achola', 'Lado', 'Ssemwanga', 'Garang', 'Abalo'];

    public function handle(TenantContext $ctx): int
    {
        $tenant = Tenant::where('slug', self::SLUG)->first();

        if ($this->option('purge') || $this->option('fresh')) {
            if ($tenant) { $this->purge($tenant); $this->info('Demo tenant purged.'); }
            else { $this->info('No demo tenant to purge.'); }
            if ($this->option('purge')) return self::SUCCESS;
            $tenant = null; // --fresh continues to re-seed
        }

        if (! $tenant) {
            $tenant = Tenant::create([
                'name' => 'BOS Demo Co', 'slug' => self::SLUG, 'status' => 'active',
                'plan' => 'demo', 'order_prefix' => 'DEMO',
            ]);
            $tenant->putSetting('currency', 'USD');       // USD-base, native — matches ADR-004
            $tenant->putSetting('currency_basis', 'native');
            $this->info("Created demo tenant #{$tenant->id} (BOS Demo Co).");
        }

        $ctx->set($tenant->id);

        if (Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->exists()) {
            $this->warn('Demo tenant already seeded. Use --fresh to reset or --purge to remove.');
            return self::SUCCESS;
        }

        $nP = max(1, (int) $this->option('products'));
        $nC = max(1, (int) $this->option('customers'));
        $nI = max(1, (int) $this->option('invoices'));
        $hasEvents = Schema::hasTable('bos_events');

        DB::transaction(function () use ($tenant, $nP, $nC, $nI, $hasEvents) {
            $products  = $this->seedProducts($tenant->id, $nP);
            $customers = $this->seedCustomers($tenant->id, $nC, $hasEvents);
            $this->seedInvoicesAndPayments($tenant->id, $customers, $products, $nI, $hasEvents);
        });

        $this->info("✅ Demo seeded: {$nP} products, {$nC} customers, {$nI} invoices (+ payments)"
            . ($hasEvents ? ' + BOS events' : ' (bos_events not migrated — no events)') . '.');
        $this->line("   Inspect tenant #{$tenant->id}. Remove any time with:  php artisan bos:demo --purge");
        return self::SUCCESS;
    }

    private function pick(array $a) { return $a[array_rand($a)]; }
    private function num(int $digits): string { return str_pad((string) mt_rand(0, (int) str_repeat('9', $digits)), $digits, '0', STR_PAD_LEFT); }

    /** @return array<int,array{price:float}> */
    private function seedProducts(int $tid, int $n): array
    {
        $cats = ['Grains & Staples', 'Cooking Oil', 'Spices', 'Beverages', 'Household', 'Disposables', 'Snacks', 'Dairy'];
        $units = ['Carton', 'Bag', 'Pack', 'Piece', 'Crate'];
        $out = []; $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $price = round(mt_rand(80, 12000) / 100, 2); // $0.80–$120.00 USD
            $name  = $this->pick($this->ADJ) . ' ' . $this->pick($this->NOUN) . ' ' . $this->pick($this->SIZE);
            $rows[] = [
                'tenant_id' => $tid, 'name' => $name, 'sku' => 'DEMO-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'category' => $this->pick($cats), 'price' => $price, 'base_price' => $price,
                'cost_price' => round($price * 0.7, 2), 'stock' => mt_rand(0, 500),
                'moq' => $this->pick([0, 0, 0, 5, 10]), 'pack_size' => $this->pick([0, 6, 12, 24]),
                'unit_label' => $this->pick($units), 'active' => 1,
                'created_at' => now()->subDays(mt_rand(30, 400)), 'updated_at' => now(),
            ];
            $out[] = ['price' => $price];
            if (count($rows) >= 200) { DB::table('products')->insert($rows); $rows = []; }
        }
        if ($rows) DB::table('products')->insert($rows);
        return $out;
    }

    /** @return array<int,array{id:int,name:string,phone:string}> */
    private function seedCustomers(int $tid, int $n, bool $hasEvents): array
    {
        $cities   = [['Kampala', 'UG'], ['Entebbe', 'UG'], ['Gulu', 'UG'], ['Mbarara', 'UG'], ['Juba', 'SS'], ['Wau', 'SS'], ['Malakal', 'SS']];
        $segments = ['Retailer', 'Wholesaler', 'Restaurant', 'Hotel', 'Kiosk'];
        $reps     = ['Savan', 'Asish', 'Jayesh', 'Amina', 'Peter'];
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            [$city, $country] = $this->pick($cities);
            $phone = ($country === 'UG' ? '2567' : '2119') . str_pad((string) $i, 5, '0', STR_PAD_LEFT) . $this->num(3);
            $created = now()->subDays(mt_rand(1, 380));
            $c = CustomerProfile::create([
                'tenant_id' => $tid, 'phone' => $phone,
                'name' => $this->pick($this->CO_A) . ' ' . $this->pick($this->CO_B),
                'contact_person' => $this->pick($this->FIRST) . ' ' . rtrim($this->pick($this->LAST), ','),
                'segment' => $this->pick($segments), 'city' => $city, 'country' => $country,
                'credit_limit' => $this->pick([0, 500, 1000, 5000]), 'assigned_rep' => $this->pick($reps),
                'stage' => $this->pick(['active', 'active', 'active', 'dormant']),
                'created_at' => $created, 'updated_at' => $created,
            ]);
            if ($hasEvents) {
                Bos::emit('CustomerCreated', ['name' => $c->name, 'city' => $city, 'segment' => $c->segment], [
                    'tenant_id' => $tid, 'subject_type' => 'customer', 'subject_id' => $c->id,
                    'actor' => ['type' => 'system', 'name' => 'demo-seed'], 'occurred_at' => $created,
                    'device' => 'seed', 'connectivity' => 'online',
                ]);
            }
            $out[] = ['id' => $c->id, 'name' => $c->name, 'phone' => $phone];
        }
        return $out;
    }

    private function seedInvoicesAndPayments(int $tid, array $customers, array $products, int $nI, bool $hasEvents): void
    {
        $methods = ['Cash', 'Mobile Money', 'Bank'];
        $year = (int) now()->format('Y');
        for ($i = 1; $i <= $nI; $i++) {
            $cust = $customers[array_rand($customers)];
            $issued = now()->subDays(mt_rand(1, 120));
            $lines = mt_rand(1, 4); $total = 0.0;
            for ($l = 0; $l < $lines; $l++) { $total += $products[array_rand($products)]['price'] * mt_rand(1, 20); }
            $total = round($total, 2);

            // Outcome mix: ~45% paid, ~20% part-paid, ~35% open (many overdue via a past due date).
            $roll = mt_rand(1, 100);
            $paid = $roll <= 45 ? $total : ($roll <= 65 ? round($total * (mt_rand(20, 80) / 100), 2) : 0.0);
            $balance = round($total - $paid, 2);
            $status = $balance <= 0.5 ? 'paid' : ($paid > 0 ? 'part_paid' : 'issued');

            $inv = Invoice::create([
                'tenant_id' => $tid, 'invoice_no' => 'DEMO-' . $year . '-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'order_id' => null, 'customer_name' => $cust['name'], 'customer_phone' => $cust['phone'],
                'currency' => 'USD', 'subtotal' => $total, 'discount' => 0, 'freight' => 0, 'total' => $total,
                'amount_paid' => $paid, 'balance' => $balance, 'title' => 'INVOICE', 'status' => $status,
                'source' => 'demo', 'issued_at' => $issued, 'due_at' => (clone $issued)->addDays(14)->toDateString(),
                'created_at' => $issued, 'updated_at' => $issued,
            ]);
            if ($hasEvents) {
                Bos::emit('InvoiceIssued', ['invoice_no' => $inv->invoice_no, 'total' => $total, 'currency' => 'USD', 'balance' => $balance], [
                    'tenant_id' => $tid, 'subject_type' => 'customer', 'subject_id' => (int) $cust['id'],
                    'actor' => ['type' => 'system', 'name' => 'demo-seed'], 'occurred_at' => $issued,
                    'device' => 'seed', 'connectivity' => 'online',
                ]);
            }

            if ($paid > 0) {
                $paidAt = (clone $issued)->addDays(mt_rand(1, 20));
                $pay = CustomerPayment::create([
                    'tenant_id' => $tid, 'receipt_no' => 'RCP-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                    'customer_name' => $cust['name'], 'customer_phone' => $cust['phone'], 'invoice_no' => $inv->invoice_no,
                    'kind' => 'settlement', 'currency' => 'USD', 'amount' => $paid, 'rate_to_usd' => 1, 'usd_amount' => $paid,
                    'method' => $this->pick($methods), 'applied' => true, 'created_by' => 'demo-seed',
                    'created_at' => $paidAt, 'updated_at' => $paidAt,
                ]);
                if ($hasEvents) {
                    Bos::emit('PaymentReceived', ['receipt_no' => $pay->receipt_no, 'amount' => $paid, 'currency' => 'USD', 'invoice_no' => $inv->invoice_no], [
                        'tenant_id' => $tid, 'subject_type' => 'customer', 'subject_id' => (int) $cust['id'],
                        'actor' => ['type' => 'user', 'name' => 'demo-seed'], 'occurred_at' => $paidAt,
                        'device' => 'seed', 'connectivity' => 'online',
                    ]);
                }
            }
        }
    }

    private function purge(Tenant $tenant): void
    {
        $tid = $tenant->id;
        foreach (['bos_events', 'customer_payments', 'invoices', 'orders', 'customer_profiles', 'products'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('tenant_id', $tid)->delete();
            }
        }
        $tenant->delete();
    }
}
