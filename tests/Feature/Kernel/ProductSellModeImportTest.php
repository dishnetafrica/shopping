<?php declare(strict_types=1);

namespace Tests\Feature\Kernel;

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sell-mode import. Proves a "Sell Mode" CSV column sets a product's storefront selling mode
 * ('quote' for made-to-order systems, 'buy' otherwise), and that a row without the column defaults to
 * 'buy' — so grocery imports are unchanged.
 */
class ProductSellModeImportTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Tenant
    {
        $t = Tenant::create(['name' => 'Solar Co', 'slug' => 't-' . substr(md5(uniqid('', true)), 0, 8)]);
        $u = User::create([
            'tenant_id' => $t->id, 'name' => 'Owner', 'role' => 'owner',
            'email' => 'o-' . uniqid('', true) . '@t.test', 'password' => 'secret',
        ]);
        app(TenantContext::class)->set($t->id);
        $this->actingAs($u);
        return $t;
    }

    private function zip(string $csv, array $images): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'smz');
        $za = new \ZipArchive();
        $za->open($tmp, \ZipArchive::OVERWRITE);
        $za->addFromString('products.csv', $csv);
        foreach ($images as $name) {
            $za->addFromString($name, "\xFF\xD8\xFF" . $name);
        }
        $za->close();
        $b64 = base64_encode((string) file_get_contents($tmp));
        @unlink($tmp);
        return $b64;
    }

    public function test_sell_mode_column_sets_quote_and_defaults_to_buy(): void
    {
        if (! class_exists('ZipArchive')) { $this->markTestSkipped('zip extension unavailable'); }
        $this->boot();
        Storage::fake('public');

        $csv = "Product Name,Category,Price USD,Stock,Image,Sell Mode\n"
             . "300W Solar System,Solar,1500,3,sys.jpeg,quote\n"
             . "Solar Cable 5m,Solar,9,50,cab.jpeg,buy\n"
             . "Torch,Solar,4,20,tor.jpeg,\n";
        $b64 = $this->zip($csv, ['sys.jpeg', 'cab.jpeg', 'tor.jpeg']);

        $this->postJson('/papi/products-import', ['zip' => $b64, 'apply' => 1])
            ->assertOk()->assertJson(['ok' => true, 'created' => 3]);

        $this->assertSame('quote', Product::where('name', '300W Solar System')->firstOrFail()->sell_mode);
        $this->assertSame('buy',   Product::where('name', 'Solar Cable 5m')->firstOrFail()->sell_mode);
        $this->assertSame('buy',   Product::where('name', 'Torch')->firstOrFail()->sell_mode);   // blank -> buy
    }
}
