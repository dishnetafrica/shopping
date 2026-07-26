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
 * Multi-photo product import. Proves the catalogue ZIP importer reads the new Gallery 1/2/3 columns and
 * fills image_url + gallery_1/2/3 (up to 4 photos per product) from files inside the ZIP, that blank
 * gallery cells leave those fields null, and that a CSV with NO gallery columns still imports exactly as
 * before (single photo). No pricing/stock behaviour changes — a priced row still imports active.
 */
class ProductMultiPhotoImportTest extends TestCase
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
        return $t;
    }

    /** Build a base64 ZIP containing $csv plus each name in $images as a tiny file. */
    private function zip(string $csv, array $images): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ptz');
        $za = new \ZipArchive();
        $za->open($tmp, \ZipArchive::OVERWRITE);
        $za->addFromString('products.csv', $csv);
        foreach ($images as $name) {
            $za->addFromString($name, "\xFF\xD8\xFF" . $name); // arbitrary bytes; ZIP import doesn't sniff type
        }
        $za->close();
        $b64 = base64_encode((string) file_get_contents($tmp));
        @unlink($tmp);
        return $b64;
    }

    public function test_zip_import_fills_four_photos(): void
    {
        if (! class_exists('ZipArchive')) { $this->markTestSkipped('zip extension unavailable'); }
        $this->boot();
        Storage::fake('public');

        $csv = "Product Name,Category,Price UGX,Stock,Image,Gallery 1,Gallery 2,Gallery 3\n"
             . "300W Solar Power Station,Solar,850000,10,main.jpeg,g1.jpeg,g2.jpeg,g3.jpeg\n";
        $b64 = $this->zip($csv, ['main.jpeg', 'g1.jpeg', 'g2.jpeg', 'g3.jpeg']);

        $this->postJson('/papi/products-import', ['zip' => $b64, 'apply' => 1])
            ->assertOk()->assertJson(['ok' => true, 'created' => 1]);

        $p = Product::where('name', '300W Solar Power Station')->firstOrFail();
        $this->assertTrue((bool) $p->active);                 // priced -> active (unchanged behaviour)
        $this->assertNotEmpty($p->image_url);
        $this->assertNotEmpty($p->gallery_1);
        $this->assertNotEmpty($p->gallery_2);
        $this->assertNotEmpty($p->gallery_3);
        // Four distinct stored files.
        $this->assertSame(4, collect([$p->image_url, $p->gallery_1, $p->gallery_2, $p->gallery_3])->unique()->count());
    }

    public function test_blank_gallery_cells_stay_null(): void
    {
        if (! class_exists('ZipArchive')) { $this->markTestSkipped('zip extension unavailable'); }
        $this->boot();
        Storage::fake('public');

        $csv = "Product Name,Category,Price UGX,Stock,Image,Gallery 1,Gallery 2,Gallery 3\n"
             . "One Photo Only,Solar,500000,3,solo.jpeg,,,\n";
        $b64 = $this->zip($csv, ['solo.jpeg']);

        $this->postJson('/papi/products-import', ['zip' => $b64, 'apply' => 1])
            ->assertOk()->assertJson(['ok' => true, 'created' => 1]);

        $p = Product::where('name', 'One Photo Only')->firstOrFail();
        $this->assertNotEmpty($p->image_url);
        $this->assertNull($p->gallery_1);
        $this->assertNull($p->gallery_2);
        $this->assertNull($p->gallery_3);
    }

    public function test_legacy_csv_without_gallery_columns_still_imports(): void
    {
        if (! class_exists('ZipArchive')) { $this->markTestSkipped('zip extension unavailable'); }
        $this->boot();
        Storage::fake('public');

        // Old-style CSV: no Gallery columns at all — must behave exactly as before.
        $csv = "Product Name,Category,Price UGX,Stock,Image\n"
             . "Legacy Product,Solar,400000,2,legacy.jpeg\n";
        $b64 = $this->zip($csv, ['legacy.jpeg']);

        $this->postJson('/papi/products-import', ['zip' => $b64, 'apply' => 1])
            ->assertOk()->assertJson(['ok' => true, 'created' => 1]);

        $p = Product::where('name', 'Legacy Product')->firstOrFail();
        $this->assertNotEmpty($p->image_url);
        $this->assertNull($p->gallery_1);
        $this->assertNull($p->gallery_2);
        $this->assertNull($p->gallery_3);
    }
}
