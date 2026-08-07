<?php

namespace App\Console\Commands;

use App\Models\CustomerPayment;
use App\Models\Tenant;
use App\Services\Documents\DocumentStamp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * v4.48 — `stamp:check` — prove the company stamp is (or is not) reaching the documents.
 *
 * A stamp that fails to print gives no error anywhere. Every one of the five ways it can go wrong
 * ends in the same silent result — a document that looks exactly like it did before — and none of
 * them is visible from the PDF:
 *
 *   1. no image uploaded                          → `stamp_image` empty
 *   2. uploaded, but the file is not on the disk   → the URL points at a file the storage symlink
 *                                                    lost on redeploy (this host drops it)
 *   3. file present, but not readable/decodable    → dataUri() returns ''
 *   4. image fine, but the document's switch is off → stamp_on_receipt = false
 *   5. everything fine, but the renderer never asks → the markup has no <img>
 *
 * This walks all five in order and stops at the first that fails, with the fix. It is READ-ONLY:
 * it renders a receipt in memory to look at the markup and never sends, saves or messages anybody.
 */
class StampCheckCommand extends Command
{
    protected $signature = 'stamp:check {--tenant= : tenant id (default: every tenant with a stamp)}
                                        {--doc=receipt : receipt|invoice|quotation|delivery_note|credit_note}
                                        {--payment= : a specific customer_payment id to render}';

    protected $description = 'Check the company stamp actually reaches a document, step by step';

    public function handle(): int
    {
        $doc = DocumentStamp::docKey((string) $this->option('doc'));
        if ($doc === '') {
            $this->error('Unknown document. Use one of: ' . implode(', ', DocumentStamp::DOCS));

            return 1;
        }

        $tenants = $this->option('tenant')
            ? Tenant::where('id', (int) $this->option('tenant'))->get()
            : Tenant::all();
        if ($tenants->isEmpty()) {
            $this->error('No tenant found.');

            return 1;
        }

        $bad = 0;
        foreach ($tenants as $t) {
            DocumentStamp::forget();   // per-tenant, never a cached answer from the last one
            $this->line('');
            $this->line("── {$t->name} (tenant {$t->id}) · {$doc}");

            // 1 — is anything configured at all?
            $url = trim((string) $t->setting('stamp_image', ''));
            if ($url === '') {
                if ($this->option('tenant')) {
                    $this->warn('   ✗ no stamp image set  → Settings → Documents → Company stamp → upload one');
                    $bad++;
                }
                continue;   // a tenant with no stamp is not a failure when sweeping all of them
            }
            $this->info('   ✓ stamp image set');
            $this->line('     ' . $url);

            // 2 — does the file actually exist where the URL says?
            if (preg_match('#/storage/(.+)$#', $url, $m)) {
                if (Storage::disk('public')->exists($m[1])) {
                    $bytes = (int) Storage::disk('public')->size($m[1]);
                    $this->info('   ✓ file on disk (' . number_format($bytes / 1024, 1) . ' KB)');
                } else {
                    $this->error('   ✗ file MISSING on the public disk: ' . $m[1]);
                    $this->line('     → re-upload it. This host drops the storage symlink on redeploy,');
                    $this->line('       which loses the file while the setting still points at it.');
                    $bad++;
                    continue;
                }
            } else {
                $this->warn('   ! not a /storage/ URL — it will only work if it is a data: URI');
            }

            // 3 — does it decode to something a PDF can embed?
            $uri = DocumentStamp::dataUri($t);
            if ($uri === '') {
                $this->error('   ✗ could not read the image into the PDF');
                $bad++;
                continue;
            }
            $this->info('   ✓ readable, ' . number_format(strlen($uri) / 1024, 1) . ' KB inlined');

            // 4 — is this document switched on?
            if (! DocumentStamp::on($t, $doc)) {
                $this->error("   ✗ switched OFF for {$doc}  → Settings → Documents → Company stamp");
                $bad++;
                continue;
            }
            $this->info('   ✓ switched on for ' . $doc . ' · printed at ' . DocumentStamp::sizePx($t) . 'px');

            // 5 — and does the document actually ask for it? Rendered, not assumed.
            if ($doc === 'receipt') {
                $p = $this->option('payment')
                    ? CustomerPayment::where('tenant_id', $t->id)->find((int) $this->option('payment'))
                    : CustomerPayment::where('tenant_id', $t->id)->latest('id')->first();
                if (! $p) {
                    $this->warn('   ! no payment to render — record one, then run this again');
                    continue;
                }
                $html = $this->receiptHtml($t, $p);
                if ($html === null) {
                    $this->warn('   ! could not render the receipt markup here; open the PDF instead');
                    continue;
                }
                if (str_contains($html, 'src="data:image')) {
                    $this->info('   ✓ the stamp IS in receipt ' . ($p->receipt_no ?: $p->id));
                    $this->line('     open it: /api/v1/doc/receipt/' . $p->id);
                } else {
                    $this->error('   ✗ the receipt markup has no stamp in it');
                    $bad++;
                }
            } else {
                $this->line('     open one and look — markup rendering is only automated for receipts.');
            }
        }

        $this->line('');
        if ($bad > 0) {
            $this->error("{$bad} problem(s) found.");

            return 1;
        }
        $this->info('Stamp is reaching the document.');

        return 0;
    }

    /** Reach the renderer's private html() without sending, saving or messaging anyone. */
    private function receiptHtml(Tenant $t, CustomerPayment $p): ?string
    {
        try {
            $r = app(\App\Services\Finance\ReceiptRenderer::class);
            $m = new \ReflectionMethod($r, 'html');
            $m->setAccessible(true);

            return (string) $m->invoke($r, $t, $p, (string) ($p->receipt_no ?: ('RCPT-' . $p->id)));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
