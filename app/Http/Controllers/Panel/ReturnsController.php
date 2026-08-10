<?php declare(strict_types=1);

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ReturnRecord;
use App\Services\Sales\ReturnPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * v4.88 (ADR-019) — returning 1–2 lines out of a bulk invoice, from the panel.
 *
 * The invoice is NEVER edited. A return produces a numbered credit note for the returned lines
 * only — the same document Amazon and Flipkart issue, and the same one the POS till has issued
 * since v4.0. This controller is the wholesale door to that flow: the till serves a customer
 * standing at the counter, this serves an invoice with a truck coming back.
 *
 * Order of operations is deliberate and mirrors the till: validate against server-side caps →
 * record the return → issue the credit note → put stock back → move the money. Each later step
 * is best-effort and logged, because a return that is recorded but whose PDF hiccuped is
 * recoverable, while a silent half-return is not.
 */
class ReturnsController extends Controller
{
    public function page(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) {
            abort(403);
        }
        $html = file_get_contents(resource_path('panel/returns.html'));

        return response(str_replace(
            ['__TENANT__', '__CURRENCY__'],
            [e((string) $t->name), e((string) ($t->setting('currency', 'UGX') ?: 'UGX'))],
            $html
        ))->header('Content-Type', 'text/html');
    }

    /**
     * The sale behind an invoice or an order, with per-line returnable quantities.
     * Accepts ?invoice= or ?order= (an id), so both the invoice screen and the order screen
     * can open the same picker.
     */
    public function find(Request $request)
    {
        $t = $request->user()?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }

        $invoice = null;
        $order = null;
        if ($request->filled('invoice')) {
            $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $t->id)->find((int) $request->query('invoice'));
            if (! $invoice) {
                return response()->json(['ok' => false, 'error' => 'Invoice not found.'], 404);
            }
            if (strtolower((string) $invoice->status) === 'void') {
                return response()->json(['ok' => false, 'error' => 'This invoice is void — there is nothing to return against it.'], 422);
            }
            $order = $invoice->order_id ? Order::withoutGlobalScopes()->where('tenant_id', $t->id)->find((int) $invoice->order_id) : null;
        } elseif ($request->filled('order')) {
            $order = Order::withoutGlobalScopes()->where('tenant_id', $t->id)->find((int) $request->query('order'));
            if (! $order) {
                return response()->json(['ok' => false, 'error' => 'Order not found.'], 404);
            }
            $invoice = Invoice::withoutGlobalScopes()->where('tenant_id', $t->id)
                ->where('order_id', $order->id)->orderByDesc('id')->first();
        } else {
            return response()->json(['ok' => false, 'error' => 'Open this from an invoice or an order.'], 422);
        }

        if (! $order) {
            return response()->json(['ok' => false, 'error' => 'This invoice has no order behind it, so its lines cannot be returned item by item.'], 422);
        }

        $sold = ReturnPolicy::soldMap(\App\Services\Inventory\OrderStockIssuer::lines($order));
        $returned = ReturnPolicy::returnedMap(
            ReturnRecord::withoutGlobalScopes()->where('tenant_id', $t->id)->where('order_id', $order->id)->get()
        );
        $remaining = ReturnPolicy::remaining($sold, $returned);
        $prices = $this->originalPrices($order);

        $lines = [];
        foreach (\App\Services\Inventory\OrderStockIssuer::lines($order) as $ln) {
            $k = ReturnPolicy::key($ln);
            $lines[] = [
                'product_id' => (int) ($ln['product_id'] ?? 0),
                'name'       => (string) $ln['name'],
                'sold'       => round((float) $ln['qty'], 3),
                'returned'   => round((float) ($returned[$k] ?? 0), 3),
                'returnable' => round((float) ($remaining[$k] ?? 0), 3),
                'price'      => round((float) ($prices[$k] ?? 0), 2),
            ];
        }

        return response()->json([
            'ok'       => true,
            'order'    => ['id' => $order->id, 'no' => (string) $order->order_no, 'date' => optional($order->created_at)->format('d M Y')],
            'invoice'  => $invoice ? ['id' => $invoice->id, 'no' => (string) $invoice->invoice_no, 'balance' => round((float) $invoice->balance, 2)] : null,
            'customer' => ['name' => (string) ($order->customer_name ?? ''), 'phone' => (string) ($order->customer_phone ?? '')],
            'currency' => \App\Services\Money\SalesBaseCurrency::txnCurrency($order, $t),
            'lines'    => $lines,
            'methods'  => ReturnPolicy::METHODS,
        ]);
    }

    /** Unit prices from the order's OWN lines — never today's price list. A customer is refunded what they paid. */
    private function originalPrices(Order $order): array
    {
        $prices = [];
        foreach ((array) $order->items_json as $l) {
            if (is_array($l)) {
                $prices[ReturnPolicy::key($l)] = (float) ($l['price'] ?? 0);
            }
        }
        foreach (OrderItem::where('order_id', $order->id)->get() as $it) {
            $prices[ReturnPolicy::key(['product_id' => (int) $it->product_id, 'name' => (string) $it->name])] = (float) $it->price;
        }

        return $prices;
    }

    public function process(Request $request)
    {
        $u = $request->user();
        $t = $u?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }

        $data = $request->validate([
            'order_id'           => 'required|integer',
            'invoice_id'         => 'nullable|integer',
            'lines'              => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|integer',
            'lines.*.name'       => 'required|string|max:200',
            'lines.*.price'      => 'required|numeric|min:0',
            'lines.*.qty'        => 'required|numeric|min:0',
            'refund_method'      => 'required|string',
            'reason'             => 'nullable|string|max:300',
            'phone'              => 'nullable|string|max:40',
            'manager_pin'        => 'nullable|string|max:20',
        ]);

        $method = (string) $data['refund_method'];
        if (! ReturnPolicy::methodOk($method)) {
            return response()->json(['ok' => false, 'error' => 'Unknown refund method.'], 422);
        }

        $o = Order::withoutGlobalScopes()->where('tenant_id', $t->id)->find((int) $data['order_id']);
        if (! $o) {
            return response()->json(['ok' => false, 'error' => 'Sale not found.'], 404);
        }

        // Same fraud fence as the till: when a manager PIN is configured, every return needs it.
        // Returns move stock and money in the direction that hides theft.
        $pin = trim((string) $t->setting('pos_manager_pin', ''));
        if ($pin !== '' && ! hash_equals($pin, trim((string) ($data['manager_pin'] ?? '')))) {
            return response()->json(['ok' => false, 'need_pin' => true, 'error' => 'Returns need the manager PIN.'], 422);
        }

        $inv = null;
        if (! empty($data['invoice_id'])) {
            $inv = Invoice::withoutGlobalScopes()->where('tenant_id', $t->id)->find((int) $data['invoice_id']);
        }
        $inv = $inv ?: Invoice::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->where('order_id', $o->id)->orderByDesc('id')->first();

        if ($inv && strtolower((string) $inv->status) === 'void') {
            return response()->json(['ok' => false, 'error' => 'That invoice is void — reverse it there, not here.'], 422);
        }
        if (ReturnPolicy::appliesToInvoice($method) && ! $inv) {
            return response()->json(['ok' => false, 'error' => 'There is no invoice on this order to reduce — choose credit on account, cash or mobile money.'], 422);
        }

        // The caps, computed here from stored records. The browser's numbers are only a request.
        $sold = ReturnPolicy::soldMap(\App\Services\Inventory\OrderStockIssuer::lines($o));
        $returned = ReturnPolicy::returnedMap(
            ReturnRecord::withoutGlobalScopes()->where('tenant_id', $t->id)->where('order_id', $o->id)->get()
        );
        $v = ReturnPolicy::check($data['lines'], $sold, $returned);
        if (! $v['ok']) {
            return response()->json(['ok' => false, 'error' => $v['error']], 422);
        }
        $clean = $v['clean'];
        $amount = $v['amount'];

        $phone = preg_replace('/[^0-9]/', '', (string) ($data['phone'] ?? '')) ?: preg_replace('/[^0-9]/', '', (string) ($o->customer_phone ?? ''));
        if (ReturnPolicy::needsPhone($method) && $phone === '') {
            return response()->json(['ok' => false, 'error' => 'Credit on account needs the customer phone number — that is how the credit finds them next time.'], 422);
        }

        try {
            $ret = ReturnRecord::create([
                'tenant_id'      => $t->id,
                'order_id'       => $o->id,
                'order_no'       => (string) $o->order_no,
                'customer_phone' => $phone,
                'customer_name'  => (string) ($o->customer_name ?? ''),
                'items_text'     => collect($clean)->map(fn ($l) => rtrim(rtrim(number_format($l['qty'], 2), '0'), '.') . 'x ' . $l['name'])->implode(', '),
                'items_json'     => $clean,
                'amount'         => $amount,
                // 'credit' keeps feeding the EXISTING store-credit-per-phone map the till uses.
                'resolution'     => $method === 'credit' ? 'credit' : 'refund',
                'refund_method'  => $method,
                'reason'         => trim((string) ($data['reason'] ?? '')) ?: null,
                'user_id'        => $u->id,
                'operator'       => (string) ($u->name ?? ''),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('panel-return record failed: ' . $e->getMessage());

            return response()->json(['ok' => false, 'error' => 'Could not save the return.'], 500);
        }

        // The accounting reverse — the document the customer keeps and the books trust.
        $cnNo = null;
        $cn = null;
        try {
            $cn = app(\App\Services\Invoicing\CreditNoteService::class)->issue($t, [
                'customer'    => (string) ($o->customer_name ?? ''),
                'phone'       => $phone,
                'cur'         => \App\Services\Money\SalesBaseCurrency::txnCurrency($o, $t),
                'total'       => $amount,
                'reason_code' => 'return',
                'notes'       => 'Return on ' . $o->order_no . ($inv ? ' / ' . $inv->invoice_no : '')
                    . ' — ' . ReturnPolicy::methodLabel($method)
                    . (trim((string) ($data['reason'] ?? '')) !== '' ? ' — ' . trim((string) $data['reason']) : ''),
                'invoice_id'  => $inv?->id,
                'invoice_no'  => $inv?->invoice_no,
                'order_id'    => $o->id,
                'return_id'   => $ret->id,
                'lines'       => array_map(fn ($l) => [
                    'name' => $l['name'], 'qty' => $l['qty'], 'unit' => $l['price'],
                    'amount' => round($l['price'] * $l['qty'], 2),
                ], $clean),
            ], 'panel');
            // v4.88 — the allocation decision, stored on the note itself: applied to this invoice,
            // or held. InvoiceService reads exactly this flag when it recomputes the balance.
            $cn->applied = ReturnPolicy::appliesToInvoice($method) && $inv !== null;
            $cn->refund_method = $method;
            $cn->save();
            $cnNo = (string) $cn->credit_note_no;
            $ret->credit_note_no = $cnNo;
            $ret->save();
        } catch (\Throwable $e) {
            \Log::warning('panel-return credit note failed: ' . $e->getMessage());
            // A return without its document needs a human, not a shrug.
            try {
                \App\Jobs\NotifyOwner::dispatch($t->id,
                    '⚠️ A return was recorded on ' . $o->order_no . ' but its credit note failed to issue — please check return #' . $ret->id . '.');
            } catch (\Throwable $e2) { /* alerting is best-effort */ }
        }

        // Stock back on the shelf — the SAME ledger the sale used, gated the same way.
        $restocked = 0;
        try {
            if (\App\Services\Inventory\OrderStockIssuer::active($t)) {
                $sl = \App\Services\Inventory\StockLedger::forTenant($t);
                foreach ($clean as $l) {
                    if ($l['product_id'] > 0) {
                        $sl->receive([
                            'product_id' => $l['product_id'], 'sku' => null,
                            'location'   => \App\Services\Inventory\StockLedger::home($t),
                            'qty'        => $l['qty'],
                            'ref_type'   => 'return', 'ref_id' => $ret->id,
                            'note'       => 'Return ' . ($cnNo ?: ('#' . $ret->id)) . ' on ' . $o->order_no . ' — ' . $l['name'],
                            'created_by' => (string) ($u->name ?? 'panel'),
                        ]);
                        $restocked++;
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('panel-return restock failed: ' . $e->getMessage());
        }

        // The money. Three shapes, one per owner-approved method.
        $newBalance = null;
        if (ReturnPolicy::appliesToInvoice($method) && $inv) {
            // Recompute from source rather than subtracting here — one formula owns the balance.
            try {
                app(\App\Services\Invoicing\InvoiceService::class)->syncPaidFromPayments($inv->fresh());
                $newBalance = round((float) $inv->fresh()->balance, 2);
            } catch (\Throwable $e) {
                \Log::warning('panel-return invoice sync failed: ' . $e->getMessage());
            }
        }
        if (ReturnPolicy::movesCash($method)) {
            try {
                \App\Models\LedgerEntry::create([
                    'type' => 'out', 'category' => 'refund', 'order_id' => $o->id,
                    'amount' => $amount, 'currency' => \App\Services\Money\SalesBaseCurrency::txnCurrency($o, $t),
                    'method' => $method, 'received_by' => (string) ($u->name ?? ''),
                    'note' => 'Return ' . ($cnNo ?: ('#' . $ret->id)) . ' on ' . $o->order_no,
                ]);
            } catch (\Throwable $e) { /* cash-book mirror only; the credit note is authoritative */ }
        }

        return response()->json([
            'ok'              => true,
            'return_id'       => $ret->id,
            'credit_note_no'  => $cnNo,
            'credit_note_pdf' => ($cn && $cn->pdf_path) ? Storage::disk('public')->url($cn->pdf_path) : null,
            'amount'          => $amount,
            'currency'        => \App\Services\Money\SalesBaseCurrency::txnCurrency($o, $t),
            'refund_method'   => $method,
            'method_label'    => ReturnPolicy::methodLabel($method),
            'order_no'        => (string) $o->order_no,
            'invoice_no'      => $inv?->invoice_no,
            'invoice_balance' => $newBalance,
            'restocked_lines' => $restocked,
            'customer'        => (string) ($o->customer_name ?? ''),
            'phone'           => $phone,
        ]);
    }
}
