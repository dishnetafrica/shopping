<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\AdvisorProject;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Project Advisor — proposal PDF export. Proves the endpoint renders real PDF bytes from a stored
 * proposal (dompdf, same stack CI already runs for invoices), 404s when there is no proposal, and is
 * gated to the Sales menu.
 */
class AdvisorProposalPdfTest extends TestCase
{
    use RefreshDatabase;

    private function boot(string $role = 'owner'): Tenant
    {
        $t = Tenant::create(['name' => 'GSL Solar', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $u = User::create(['tenant_id' => $t->id, 'name' => 'U', 'role' => $role,
            'email' => $role[0] . '-' . uniqid('', true) . '@t.test', 'password' => 'secret']);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    private function makeProject(Tenant $t, bool $withProposal): AdvisorProject
    {
        return AdvisorProject::create([
            'tenant_id' => $t->id, 'profile' => 'solar', 'name' => 'Test', 'status' => $withProposal ? 'proposed' : 'sized',
            'answers' => ['customer_type' => 'Residential'],
            'sizing'  => ['result' => ['pv_array_w' => 585.6]],
            'proposal' => $withProposal ? [
                'currency' => 'UGX', 'title' => 'Test — Solar Proposal',
                'sections' => [
                    ['key' => 'executive_summary', 'title' => 'Executive Summary', 'body' => 'A small solar system.'],
                    ['key' => 'equipment_list', 'title' => 'Equipment List', 'items' => [['name' => 'Panel', 'sku' => 'SP', 'qty' => 2, 'unit_price' => 350000, 'line_total' => 700000]], 'subtotal' => 700000],
                    ['key' => 'terms', 'title' => 'Terms and Conditions', 'items' => ['70% deposit.']],
                ],
            ] : null,
        ]);
    }

    public function test_pdf_endpoint_returns_real_pdf(): void
    {
        if (! class_exists(\Dompdf\Dompdf::class)) { $this->markTestSkipped('dompdf unavailable'); }
        $t = $this->boot();
        $p = $this->makeProject($t, true);

        $res = $this->get('/papi/advisor/projects/' . $p->id . '/proposal/pdf');
        $res->assertOk();
        $res->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_404_when_no_proposal(): void
    {
        $t = $this->boot();
        $p = $this->makeProject($t, false);
        $this->get('/papi/advisor/projects/' . $p->id . '/proposal/pdf')->assertStatus(404);
    }

    public function test_gated_to_sales_menu(): void
    {
        $t = $this->boot('product_staff');
        $p = $this->makeProject($t, true);
        $this->get('/papi/advisor/projects/' . $p->id . '/proposal/pdf')->assertStatus(403);
    }
}
