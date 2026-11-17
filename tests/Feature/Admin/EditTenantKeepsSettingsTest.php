<?php

namespace Tests\Feature\Admin;

use App\Filament\Admin\Resources\TenantResource\Pages\EditTenant;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** End to end through the real Filament form: Admin → Tenants → Edit → Save keeps settings the form does not show. */
class EditTenantKeepsSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_the_admin_form_keeps_unshown_settings(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@t.test', 'password' => 'secret', 'is_super_admin' => true]);
        $t = Tenant::factory()->create(['settings' => ['vertical' => 'isp', 'currency' => 'USD', 'timezone' => 'Africa/Juba', 'track_stock' => false, 'bot_muted' => ['211921443004']]]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditTenant::class, ['record' => $t->getRouteKey()])->call('save');

        $s = $t->fresh()->settings;
        $this->assertSame('Africa/Juba', $s['timezone'] ?? null);
        $this->assertFalse($s['track_stock'] ?? null);
        $this->assertSame(['211921443004'], $s['bot_muted'] ?? null);
        $this->assertSame('isp', $s['vertical'] ?? null);
    }
}
