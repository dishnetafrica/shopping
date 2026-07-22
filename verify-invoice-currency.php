<?php
/**
 * Invoice currency diagnostic — run on the EasyPanel terminal:
 *     php artisan tinker verify-invoice-currency.php
 * Read-only. Shows why an invoice reads UGX and what the fix will do. Nothing is written.
 */

use App\Models\Tenant;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;

$TENANT  = 17;
$INVOICE = 'INV-2026-0018';   // change to test another invoice

$t = Tenant::find($TENANT);
if (! $t) { echo "Tenant {$TENANT} not found\n"; return; }

echo "==================== SETTINGS ====================\n";
echo "tenant                    : {$t->id}  {$t->name}\n";
echo "currency (base)           : " . ($t->setting('currency', '?')) . "\n";
echo "currency_basis            : " . (($t->setting('currency_basis', '')) ?: '(unset)') . "\n";
echo "invoices_usd_normalized   : " . ($t->setting('invoices_usd_normalized') ? 'YES (flag on)' : 'no (flag OFF)') . "\n";
$rate = (float) (App\Services\Money\Fx::forTenant($t)->ratePerUsd('UGX') ?: 3655);
echo "UGX rate (per 1 USD)      : {$rate}\n";

echo "\n============ IS THE CODE FIX DEPLOYED? ============\n";
$svcFile = file_get_contents(base_path('app/Services/Invoicing/InvoiceService.php'));
$apiFile = file_get_contents(base_path('app/Http/Controllers/Panel/PanelApiController.php'));
echo "InvoiceService fix        : " . (str_contains($svcFile, 'public static function toUsdIfNeeded') ? 'DEPLOYED' : 'NOT deployed') . "\n";
echo "normalize-endpoint fix    : " . (str_contains($apiFile, "base === 'USD' && \$native === 'USD'") ? 'DEPLOYED' : 'NOT deployed') . "\n";

// Catalogue USD price map (same source the fix uses).
$byName = [];
foreach (Product::withoutGlobalScopes()->where('tenant_id', $TENANT)->get(['name', 'price', 'base_price']) as $p) {
    $usd = (float) ($p->price ?: $p->base_price ?: 0);
    if ($usd > 0) { $byName[mb_strtolower(trim((string) $p->name))] = $usd; }
}

$inv = Invoice::withoutGlobalScopes()->with('items')->where('tenant_id', $TENANT)->where('invoice_no', $INVOICE)->first();
if (! $inv) { echo "\n{$INVOICE} not found for tenant {$TENANT}\n"; return; }

echo "\n==================== {$INVOICE} ====================\n";
echo "currency tag              : {$inv->currency}\n";
echo "stored total              : {$inv->total}\n";
echo "stored subtotal           : {$inv->subtotal}\n";
echo "amount_paid / balance     : {$inv->amount_paid} / {$inv->balance}\n";
echo "order_id                  : " . ($inv->order_id ?: '(none)') . "\n";
echo "pdf_path (cached PDF)     : " . ($inv->pdf_path ?: '(none — regenerates on view)') . "\n";
echo "lines:\n";
$catBase = 0.0; $matched = 0;
foreach ($inv->items as $it) {
    $usd = $byName[mb_strtolower(trim((string) $it->name))] ?? null;
    if ($usd !== null) { $catBase += $usd * (float) $it->qty; $matched++; }
    echo "  - " . str_pad((string) $it->name, 46) . " qty=" . str_pad((string) $it->qty, 4)
        . " stored_unit=" . str_pad((string) $it->unit_price, 12)
        . " catalogue_USD=" . ($usd === null ? 'NO MATCH' : $usd) . "\n";
}

echo "\n==================== VERDICT ====================\n";
echo "stored total              : {$inv->total}\n";
echo "catalogue USD total       : {$catBase}  (matched {$matched} of " . $inv->items->count() . " lines)\n";
$ratio = $catBase > 0 ? round($inv->total / $catBase, 1) : 0;
echo "ratio stored / catalogue  : {$ratio}x   (~1 = already USD;  ~{$rate} = stored in UGX)\n";
$correct = $rate > 0 ? round((float) $inv->total / $rate, 2) : (float) $inv->total;

if ($catBase > 0 && abs($inv->total - $catBase) <= 0.12 * $catBase) {
    echo "=> This invoice is ALREADY USD. No conversion needed.\n";
} elseif ($catBase > 0 && abs($inv->total - $catBase * $rate) <= 0.12 * $catBase * $rate) {
    echo "=> This invoice is stored in UGX. After you run /papi/normalize-invoices-usd?commit=1\n";
    echo "   it becomes USD {$correct}.\n";
    echo "   If the flag above still says 'no (flag OFF)', the fix URL has NOT been run yet.\n";
} else {
    echo "=> Not conclusive from the catalogue. Checking the linked order currency:\n";
}

if ($inv->order_id) {
    $o = Order::withoutGlobalScopes()->find($inv->order_id);
    if ($o) echo "linked order {$o->order_no}: sell_currency=" . ($o->sell_currency ?: '(null)') . "  total={$o->total}\n";
}
echo "\nDone. (This script changed nothing.)\n";
