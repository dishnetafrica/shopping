<?php
namespace Database\Seeders;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InitialSeeder extends Seeder
{
    public function run(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->asSuperAdmin();
        if (Tenant::query()->exists()) { $ctx->asSuperAdmin(false); return; } // idempotent

        $tenant = Tenant::create([
            'name' => 'Family Shoppers',
            'slug' => 'familyshoppers',
            'order_prefix' => 'FS',
            'plan' => 'pro',
            'trial_ends_at' => null,
            'paid_until' => now()->addYears(10),
            'billing_note' => 'Flagship account',
            'whatsapp_driver' => 'evolution',
            'whatsapp_instance' => 'savan',
            'whatsapp_number' => '256731002066',
            'settings' => ['currency' => 'UGX', 'usd_ugx' => 3750, 'usd_ssp' => 7000, 'discount_pct' => 0],
        ]);

        User::create([
            'name' => 'Operator',
            'email' => env('ADMIN_EMAIL', 'owner@shopbot.test'),
            'phone' => env('ADMIN_PHONE', '211927797217'),
            'password' => Hash::make(env('ADMIN_PASSWORD', 'change-me-now')),
            'is_super_admin' => true,
        ]);

        User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Family Shoppers Admin',
            'email' => 'staff@familyshoppers.test',
            'phone' => '256700000000',
            'password' => Hash::make('change-me-now'),
            'role' => 'admin',
        ]);

        $ctx->asSuperAdmin(false);
        $ctx->set($tenant->id);
        foreach ([['Sugar 1kg', 5000], ['Rice 5kg', 35000], ['Cooking Oil 1L', 9000]] as [$n, $p]) {
            Product::create(['name' => $n, 'price' => $p, 'base_price' => $p, 'stock' => 100, 'active' => true]);
        }
        $ctx->clear();
    }
}
