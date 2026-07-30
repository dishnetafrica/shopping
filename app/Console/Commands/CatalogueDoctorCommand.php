<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\Catalog\CatalogRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-command diagnosis for the catalogue PDF pipeline — run this whenever the catalogue
 * misbehaves and paste the output. It probes every dependency the renderer uses, does a tiny
 * real render, and prints the renderer's own recent log lines, so the failing stage is named
 * without anyone having to dig through laravel.log.
 *
 *   php artisan catalogue:doctor --tenant=17
 */
final class CatalogueDoctorCommand extends Command
{
    protected $signature = 'catalogue:doctor {--tenant= : tenant id (required)}';

    protected $description = 'Probe the catalogue PDF pipeline (dompdf, GD, storage, image fetch, QR, mini render) and print recent renderer errors.';

    public function handle(): int
    {
        $id = (int) $this->option('tenant');
        if (! $id) {
            $this->warn('Pass --tenant=<id>. Your businesses:');
            foreach (Tenant::orderBy('id')->get() as $tt) {
                $this->line('  --tenant=' . $tt->id . '   ' . $tt->name);
            }
            return self::FAILURE;
        }
        $tenant = Tenant::find($id);
        if (! $tenant) {
            $this->error("Tenant {$id} not found.");
            return self::FAILURE;
        }

        $this->info('── Catalogue doctor · tenant ' . $id . ' (' . $tenant->name . ') · ' . now()->toDateTimeString() . ' ──');

        // 1. PHP + engine
        $this->line('PHP ' . PHP_VERSION . ' · memory_limit=' . ini_get('memory_limit')
            . ' · max_execution_time=' . ini_get('max_execution_time'));
        $this->check('dompdf installed', class_exists(\Dompdf\Dompdf::class));
        $this->check('GD extension', extension_loaded('gd'));
        $this->check('GD JPEG support', function_exists('imagejpeg'),
            function_exists('imagejpeg') ? '' : 'thumbnails fall back to PNG/Imagick — rebuild with the updated Dockerfile (--with-jpeg) for best size');
        $this->check('GD PNG support', function_exists('imagepng'));
        $this->check('GD WebP support', function_exists('imagewebp'));
        $this->check('Imagick extension', class_exists(\Imagick::class), class_exists(\Imagick::class) ? '' : 'optional');
        $this->check('curl extension', function_exists('curl_multi_init'));

        // 2. Storage probe (the thumb cache lives on the public disk)
        try {
            $probe = 'catalog-thumbs/_doctor-probe.txt';
            Storage::disk('public')->put($probe, 'ok');
            $back = Storage::disk('public')->get($probe);
            Storage::disk('public')->delete($probe);
            $this->check("storage 'public' write/read", $back === 'ok');
        } catch (\Throwable $e) {
            $this->fail2("storage 'public' write/read", $e);
        }

        // 3. Product image fetch probe (first 3 image URLs, same curl options as the renderer)
        $urls = Product::withoutGlobalScopes()->where('tenant_id', $id)->where('active', true)
            ->whereNotNull('image_url')->where('image_url', '!=', '')
            ->limit(3)->pluck('image_url')->all();
        if (! $urls) {
            $this->warn('no product image URLs to probe');
        }
        foreach ($urls as $u) {
            $this->probeUrl('product image', (string) $u);
        }

        // 4. QR service probe
        $digits = preg_replace('/\D+/', '', (string) ($tenant->setting('public_phone', '') ?: $tenant->whatsapp_number));
        if ($digits) {
            $this->probeUrl('quickchart QR', 'https://quickchart.io/qr?text=' . urlencode('https://wa.me/' . $digits) . '&size=80');
        } else {
            $this->warn('no WhatsApp number set — QR will be omitted (not an error)');
        }

        // 5. Mini real render: 2 products through the actual renderer
        try {
            $items = Product::withoutGlobalScopes()->where('tenant_id', $id)->where('active', true)
                ->where('price', '>', 0)->orderBy('name')->limit(2)->get()
                ->map(fn ($p) => [
                    'name' => (string) $p->name, 'unit' => (string) ($p->unit_label ?: ''),
                    'moq' => $p->moq ? (int) $p->moq : null, 'price' => (float) $p->price,
                    'sku' => (string) ($p->sku ?: ''), 'image' => (string) ($p->image_url ?: ''),
                ])->all();
            if (! $items) {
                $this->warn('mini render skipped — no active priced products');
            } else {
                $t0 = microtime(true);
                $doc = app(CatalogRenderer::class)->pdf($tenant, [['category' => 'Doctor Probe', 'items' => $items]],
                    (string) ($tenant->setting('currency', 'UGX') ?: 'UGX'), true);
                if ($doc) {
                    $kb = (int) round(strlen(base64_decode($doc['b64'])) / 1024);
                    $this->check('mini render (2 products)', true,
                        $kb . ' KB in ' . round(microtime(true) - $t0, 1) . 's → ' . $doc['fileName']);
                } else {
                    $this->check('mini render (2 products)', false, 'returned null — see renderer log lines below');
                }
            }
        } catch (\Throwable $e) {
            $this->fail2('mini render', $e);
        }

        // 6. The renderer's own recent log lines — the exact failing stage with file:line
        $this->line('');
        $this->info('Recent CatalogRenderer log lines (newest last):');
        try {
            $log = storage_path('logs/laravel.log');
            if (is_file($log)) {
                $tail = $this->tailLines($log, 4000);
                $hits = array_values(array_filter($tail, fn ($l) => str_contains($l, 'CatalogRenderer')));
                $hits = array_slice($hits, -12);
                if ($hits) {
                    foreach ($hits as $l) {
                        $this->line('  ' . trim($l));
                    }
                } else {
                    $this->line('  (none found in the last ' . count($tail) . ' log lines)');
                }
            } else {
                $this->line('  laravel.log not found (LOG_CHANNEL=' . config('logging.default') . ') — logs go to the container stderr.');
                $this->line('  See them with: docker logs <container> 2>&1 | grep -a CatalogRenderer | tail -12');
            }
        } catch (\Throwable $e) {
            $this->line('  could not read log: ' . $e->getMessage());
        }

        $this->line('');
        $this->info('Done. Paste ALL of the above output back into the chat.');

        return self::SUCCESS;
    }

    private function check(string $label, bool $ok, string $extra = ''): void
    {
        $this->line(($ok ? '  [ OK ] ' : '  [FAIL] ') . $label . ($extra !== '' ? ' — ' . $extra : ''));
    }

    private function fail2(string $label, \Throwable $e): void
    {
        $this->line('  [FAIL] ' . $label . ' — ' . get_class($e) . ': ' . $e->getMessage()
            . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }

    private function probeUrl(string $label, string $url): void
    {
        $short = strlen($url) > 84 ? substr($url, 0, 81) . '…' : $url;
        if (! preg_match('~^https?://~i', $url)) {
            $this->check($label, false, 'NOT AN ABSOLUTE URL: "' . $short . '" — the fetcher skips these (placeholder shown)');
            return;
        }
        if (! function_exists('curl_init')) {
            $this->check($label, false, 'curl missing');
            return;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'CloudBSS-Catalog/2.0',
        ]);
        $t0 = microtime(true);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $ok = $code >= 200 && $code < 300 && $body !== false && $body !== '';
        $size = $ok ? round(strlen((string) $body) / 1024) . ' KB' : '';
        $dims = '';
        if ($ok) {
            $info = @getimagesizefromstring((string) $body);
            if ($info) {
                $dims = ' ' . $info[0] . 'x' . $info[1] . ' (' . round($info[0] * $info[1] / 1e6, 1) . ' MP)';
            }
        }
        $this->check($label, $ok, 'HTTP ' . $code . ' ' . $size . $dims . ' in ' . $ms . 'ms'
            . ($err ? ' · curl: ' . $err : '') . ' · ' . $short);
    }

    /** @return array<int,string> */
    private function tailLines(string $file, int $n): array
    {
        $size = (int) filesize($file);
        $chunk = min($size, $n * 200);
        $fh = fopen($file, 'r');
        fseek($fh, -$chunk, SEEK_END);
        $data = (string) fread($fh, $chunk);
        fclose($fh);
        $lines = explode("\n", $data);

        return array_slice($lines, -$n);
    }
}
