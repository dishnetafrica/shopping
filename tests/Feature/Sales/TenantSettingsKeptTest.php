<?php

namespace Tests\Feature\Sales;

use App\Filament\Admin\Resources\TenantResource\Pages\EditTenant;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real case, DishNet South Sudan 16–17 Nov 2026: saving the business in Admin → Tenants wiped every
 * setting that form does not show (track_stock, bot_muted, timezone). The form's own fields still win;
 * everything else is kept, read fresh from the database.
 */
class TenantSettingsKeptTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_save_keeps_the_settings_its_form_does_not_show(): void
    {
        $t = Tenant::factory()->create(['settings' => [
            'vertical' => 'isp', 'currency' => 'USD', 'timezone' => 'Africa/Juba', 'track_stock' => false,
            'bot_muted' => ['211921443004'], 'alert_routing' => ['sales' => '211927797217'],
        ]]);
        // Another screen writes a setting while the admin page is open.
        $t->putSetting('bot_recover_missed', true);

        $saved = EditTenant::keepUnshownSettings($t->id, [
            'vertical' => 'isp', 'currency' => 'USD',
            'alert_routing' => ['sales' => '211926134489'],      // changed on the form
            'bot_mode' => 'ai',                                     // newly set on the form
        ]);

        $this->assertSame('Africa/Juba', $saved['timezone']);
        $this->assertFalse($saved['track_stock']);
        $this->assertSame(['211921443004'], $saved['bot_muted']);
        $this->assertTrue($saved['bot_recover_missed'], 'a setting written after the page opened is kept too');
        $this->assertSame(['sales' => '211926134489'], $saved['alert_routing'], 'the form still wins for its own fields');
        $this->assertSame('ai', $saved['bot_mode']);
    }
}
