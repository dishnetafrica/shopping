<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase D — classic pages 302 to v3, gated OFF by default, ?classic=1 always escapes. */
class ClassicRedirectsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(array $settings = []): User
    {
        $t = Tenant::factory()->create();
        $t->putSetting('order_system_enabled', true);
        foreach ($settings as $k => $v) $t->putSetting($k, $v);
        app(\App\Support\TenantContext::class)->set($t->id);
        $u = User::factory()->create(['tenant_id' => $t->id, 'role' => 'owner']);
        $this->actingAs($u);
        return $u;
    }

    public function test_default_off_classic_pages_render(): void
    {
        $this->owner();
        $this->get('/panel/chats')->assertOk();     // deploying the kit is a no-op
    }

    public function test_flag_on_redirects_to_v3_workspace(): void
    {
        $this->owner(['v3_redirects' => true]);
        $this->get('/panel/chats')->assertRedirect('/v3#/chats');
        $this->get('/panel/trade-cashbook')->assertRedirect('/v3#/cashbook');
        $this->get('/panel/email-center')->assertRedirect('/v3#/communication');
    }

    public function test_classic_escape_and_unmapped_pages_still_render(): void
    {
        $this->owner(['v3_redirects' => true]);
        $this->get('/panel/chats?classic=1')->assertOk();   // the quarter-long escape hatch
        $this->get('/panel/audit')->assertOk();             // honest tail stays classic
    }

    public function test_papi_endpoints_are_never_redirected(): void
    {
        $this->owner(['v3_redirects' => true]);
        $this->get('/papi/nav')->assertOk();                // v3 itself depends on papi
    }
}
