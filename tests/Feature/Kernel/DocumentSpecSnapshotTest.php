<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Catalog\ProductSpecService;
use App\Services\Invoicing\InvoiceRenderer;
use App\Support\Presets\SolarPreset;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S1c-3 — a line's product specs are snapshotted onto invoice/quote lines at creation (via the
 * LineSpecSnapshotObserver) and rendered on the invoice. Invoice lines resolve by SKU, quote lines by
 * name; the snapshot is frozen; unmatched lines are left null. Factory-independent.
 */
class DocumentSpecSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Tenant
    {
        $t = Tenant::create(['name' => 'Test Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        SolarPreset::seedSpecCatalogue($t);
        $p = Product::create(['name' => 'Panel 550', 'price' => 200, 'sku' => 'PNL-550']);
        ProductSpecService::forTenant($t)->setProductSpecs($p->id, ['wattage' => '550', 'battery_chemistry' => 'LiFePO4']);
        return $t;
    }

    private function invoice(Tenant $t): Invoice
    {
        return Invoice::create([
            'tenant_id' => $t->id, 'invoice_no' => 'INV-' . substr(md5(uniqid('', true)), 0, 6),
            'customer_name' => 'Asish', 'currency' => 'USD',
            'subtotal' => 200, 'total' => 200, 'amount_paid' => 0, 'balance' => 200,
            'status' => 'issued', 'issued_at' => now(),
        ]);
    }

    public function test_invoice_line_snapshots_specs_by_sku(): void
    {
        $t = $this->boot();
        $inv = $this->invoice($t);
        $it = InvoiceItem::create([
            'invoice_id' => $inv->id, 'name' => 'Solar Panel', 'sku' => 'PNL-550',
            'qty' => 1, 'unit_price' => 200, 'line_total' => 200,
        ]);

        $this->assertStringContainsString('550 W', (string) $it->specs_summary);
        $this->assertStringContainsString('LiFePO4', (string) $it->fresh()->specs_summary);
    }

    public function test_quote_line_snapshots_specs_by_name(): void
    {
        $t = $this->boot();
        $q = Quotation::create([
            'tenant_id' => $t->id, 'quote_no' => 'Q-' . substr(md5(uniqid('', true)), 0, 6),
            'customer_name' => 'Asish', 'currency' => 'USD', 'total' => 200, 'status' => 'draft', 'source' => 'panel',
        ]);
        $qi = QuotationItem::create([
            'quotation_id' => $q->id, 'name' => 'Panel 550',   // resolves by name
            'qty' => 1, 'unit_price' => 200, 'line_total' => 200,
        ]);

        $this->assertStringContainsString('550 W', (string) $qi->specs_summary);
        $this->assertStringContainsString('LiFePO4', (string) $qi->specs_summary);
    }

    public function test_unmatched_line_gets_no_snapshot(): void
    {
        $t = $this->boot();
        $inv = $this->invoice($t);
        $it = InvoiceItem::create([
            'invoice_id' => $inv->id, 'name' => 'Mystery Widget', 'sku' => 'NOPE-1',
            'qty' => 1, 'unit_price' => 5, 'line_total' => 5,
        ]);

        $this->assertNull($it->fresh()->specs_summary);
    }

    public function test_invoice_render_includes_the_spec_snapshot(): void
    {
        $t = $this->boot();
        $inv = $this->invoice($t);
        InvoiceItem::create([
            'invoice_id' => $inv->id, 'name' => 'Solar Panel', 'sku' => 'PNL-550',
            'qty' => 1, 'unit_price' => 200, 'line_total' => 200,
        ]);

        $data = app(InvoiceRenderer::class)->fromInvoice($t, $inv->fresh());
        $this->assertStringContainsString('550 W', (string) ($data['lines'][0]['specs'] ?? ''));

        $html = app(InvoiceRenderer::class)->html($t, $data);
        $this->assertStringContainsString('550 W', $html);
    }
}
