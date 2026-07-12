<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Roadmap E9.1 — one shared design-tokens stylesheet, served for the panel and linked on every page.
 */
class DesignTokensTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_stylesheet_is_served(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'U']);
        $this->actingAs($u);

        $res = $this->get('/panel/shared/tokens.css');
        $res->assertOk();
        $res->assertHeader('content-type', 'text/css; charset=UTF-8');
        $body = $res->getContent();
        $this->assertStringContainsString('--brand', $body);
        $this->assertStringContainsString('--radius-md', $body);
        $this->assertStringContainsString('.ds-btn', $body);
    }

    public function test_pages_link_the_tokens(): void
    {
        foreach (['seller.html', 'invoices.html', 'payments.html', 'trade_cashbook.html'] as $page) {
            $html = file_get_contents(resource_path('panel/' . $page));
            $this->assertStringContainsString('/panel/shared/tokens.css', $html, "$page should link the shared tokens");
        }
    }

    public function test_ui_helpers_served_and_linked(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner', 'name' => 'U']);
        $this->actingAs($u);

        $res = $this->get('/panel/shared/ui.js');
        $res->assertOk();
        $body = $res->getContent();
        $this->assertStringContainsString('CloudBSSUI', $body);
        $this->assertStringContainsString('confirmDialog', $body);
        $this->assertStringContainsString('errorMessage', $body);

        foreach (['seller.html', 'invoices.html'] as $page) {
            $this->assertStringContainsString('/panel/shared/ui.js', file_get_contents(resource_path('panel/' . $page)));
        }
    }
}
