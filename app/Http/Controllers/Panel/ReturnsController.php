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
 * v4.89 — TWO STEPS, because wholesale goods travel back on a later truck (owner, 10 Aug):
 *
 *   process()  record the return. Nothing moves — no credit note, no stock, no money — but the
 *              cartons are held against the cap from this moment, so the same ones cannot be
 *              promised twice.
 *   confirm()  the goods arrived. Quantities may be SHORT of what was promised (that is the truth
 *              of the delivery and is accepted); more is refused. NOW the credit note is issued,
 *              stock goes back and the money settles.
 *   cancel()   the goods never came. The record stays; the cap is freed.
 *
 * The POS till stays one-step by design: a customer at the counter holding the goods is the one
 * case where recording and receiving are the same moment.
 *
 * Within confirm(), the order is deliberate: validate → mark received → credit note → stock →
 * money. Each later step is best-effort and logged (and a missing credit note raises the owner),
 * because a recorded return whose PDF hiccuped is recoverable while a silent half-return is not.
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

        // v4.89 — returns already recorded and waiting for the goods. Staff need to see these:
        // they are holding cap, and one of them is probably the truck that just pulled in.
        $pending = [];
        foreach (ReturnRecord::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->where('order_id', $order->id)->where('status', ReturnPolicy::PENDING)
            ->orderByDesc('id')->get() as $p) {
            $pending[] = [
                'id' => $p->id,
                'recorded' => optional($p->created_at)->format('d M Y'),
                'by' => (string) ($p->operator ?? ''),
                'method' => (string) ($p->refund_method ?? ''),
                'method_label' => ReturnPolicy::methodLabel((string) ($p->refund_method ?? '')),
                'amount' => round((float) $p->amount, 2),
                'lines' => array_values(array_map(fn ($l) => [
                    'product_id' => (int) ($l['product_id'] ?? 0),
                    'name' => (string) ($l['name'] ?? ''),
                    'price' => round((float) ($l['price'] ?? 0), 2),
                    'qty' => (float) ($l['qty'] ?? 0),
                ], (array) ($p->requested_json ?? []))),
            ];
        }

        return response()->json([
            'ok'       => true,
            'pending'  => $pending,
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

        // v4.89 — STEP ONE: record only. No credit note, no stock, no money until the goods are
        // physically confirmed (owner's call, 10 Aug: "never restock cartons you haven't seen").
        // The row still holds cap from this moment, so the same cartons cannot be promised twice.
        try {
            $ret = ReturnRecord::create([
                'tenant_id'      => $t->id,
                'order_id'       => $o->id,
                'order_no'       => (string) $o->order_no,
                'customer_phone' => $phone,
                'customer_name'  => (string) ($o->customer_name ?? ''),
                'items_text'     => collect($clean)->map(fn ($l) => rtrim(rtrim(number_format($l['qty'], 2), '0'), '.') . 'x ' . $l['name'])->implode(', '),
                'items_json'     => [],                 // nothing has arrived yet
                'requested_json' => $clean,             // what was promised
                'status'         => ReturnPolicy::PENDING,
                'amount'         => $amount,            // expected value; re-derived on arrival
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

        return response()->json([
            'ok'            => true,
            'stage'         => 'recorded',
            'return_id'     => $ret->id,
            'amount'        => $amount,
            'currency'      => \App\Services\Money\SalesBaseCurrency::txnCurrency($o, $t),
            'refund_method' => $method,
            'method_label'  => ReturnPolicy::methodLabel($method),
            'order_no'      => (string) $o->order_no,
            'invoice_no'    => $inv?->invoice_no,
            'customer'      => (string) ($o->customer_name ?? ''),
            'phone'         => $phone,
        ]);
    }

    /**
     * STEP TWO — the goods arrived. Quantities may be SHORT of what was promised (that is normal
     * and is the truth of the delivery); more than promised is refused, because those extra units
     * never passed the sale caps. This is where the credit note, the stock and the money move.
     */
    public function confirm(Request $request)
    {
        $u = $request->user();
        $t = $u?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }

        $data = $request->validate([
            'return_id'          => 'required|integer',
            'lines'              => 'required|array|min:1',
            'lines.*.product_id' => 'nullable|integer',
            'lines.*.name'       => 'required|string|max:200',
            'lines.*.price'      => 'required|numeric|min:0',
            'lines.*.qty'        => 'required|numeric|min:0',
            'manager_pin'        => 'nullable|string|max:20',
        ]);

        $ret = ReturnRecord::withoutGlobalScopes()->where('tenant_id', $t->id)->find((int) $data['return_id']);
        if (! $ret) {
            return response()->json(['ok' => false, 'error' => 'Return not found.'], 404);
        }
        if (! ReturnPolicy::canConfirm((string) $ret->status)) {
            return response()->json(['ok' => false, 'error' => 'This return is already ' . (string) $ret->status . '.'], 422);
        }

        $pin = trim((string) $t->setting('pos_manager_pin', ''));
        if ($pin !== '' && ! hash_equals($pin, trim((string) ($data['manager_pin'] ?? '')))) {
            return response()->json(['ok' => false, 'need_pin' => true, 'error' => 'Returns need the manager PIN.'], 422);
        }

        $o = Order::withoutGlobalScopes()->where('tenant_id', $t->id)->find((int) $ret->order_id);
        if (! $o) {
            return response()->json(['ok' => false, 'error' => 'The sale behind this return no longer exists.'], 404);
        }
        $method = (string) ($ret->refund_method ?: 'credit');

        $v = ReturnPolicy::checkArrival($data['lines'], (array) ($ret->requested_json ?? []));
        if (! $v['ok']) {
            return response()->json(['ok' => false, 'error' => $v['error']], 422);
        }
        $clean = $v['clean'];
        $amount = $v['amount'];

        $inv = Invoice::withoutGlobalScopes()->where('tenant_id', $t->id)
            ->where('order_id', $o->id)->orderByDesc('id')->first();
        if ($inv && strtolower((string) $inv->status) === 'void') {
            $inv = null;                        // a voided invoice cannot absorb a credit
        }

        $ret->items_json = $clean;
        $ret->amount = $amount;
        $ret->items_text = collect($clean)->map(fn ($l) => rtrim(rtrim(number_format($l['qty'], 2), '0'), '.') . 'x ' . $l['name'])->implode(', ');
        $ret->status = ReturnPolicy::COMPLETED;
        $ret->received_at = now();
        $ret->received_by = (string) ($u->name ?? '');
        $ret->save();

        $phone = (string) ($ret->customer_phone ?? '');
        $cnNo = null;
        $cn = null;
        // A REPLACEMENT credits nothing: the goods come back, a good one goes out, no money moves.
        if (ReturnPolicy::issuesCredit($method)) {
            try {
                $cn = app(\App\Services\Invoicing\CreditNoteService::class)->issue($t, [
                    'customer'    => (string) ($o->customer_name ?? ''),
                    'phone'       => $phone,
                    'cur'         => \App\Services\Money\SalesBaseCurrency::txnCurrency($o, $t),
                    'total'       => $amount,
                    'reason_code' => 'return',
                    'notes'       => 'Return received on ' . $o->order_no . ($inv ? ' / ' . $inv->invoice_no : '')
                        . ' — ' . ReturnPolicy::methodLabel($method)
                        . (trim((string) ($ret->reason ?? '')) !== '' ? ' — ' . trim((string) $ret->reason) : ''),
                    'invoice_id'  => $inv?->id,
                    'invoice_no'  => $inv?->invoice_no,
                    'order_id'    => $o->id,
                    'return_id'   => $ret->id,
                    'lines'       => array_map(fn ($l) => [
                        'name' => $l['name'], 'qty' => $l['qty'], 'unit' => $l['price'],
                        'amount' => round($l['price'] * $l['qty'], 2),
                    ], $clean),
                ], 'panel');
                $cn->applied = ReturnPolicy::appliesToInvoice($method) && $inv !== null;
                $cn->refund_method = $method;
                $cn->save();
                $cnNo = (string) $cn->credit_note_no;
                $ret->credit_note_no = $cnNo;
                $ret->save();
            } catch (\Throwable $e) {
                \Log::warning('panel-return credit note failed: ' . $e->getMessage());
                try {
                    \App\Jobs\NotifyOwner::dispatch($t->id,
                        '⚠️ A return was received on ' . $o->order_no . ' but its credit note failed to issue — please check return #' . $ret->id . '.');
                } catch (\Throwable $e2) { /* alerting is best-effort */ }
            }
        }

        $restocked = $this->restock($t, $o, $ret, $clean, $cnNo, (string) ($u->name ?? 'panel'));

        $newBalance = null;
        if (ReturnPolicy::appliesToInvoice($method) && $inv) {
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
            } catch (\Throwable $e) { /* cash-book mirror only */ }
        }
        // A replacement leaves someone owing goods. Say so out loud rather than trusting memory.
        if (ReturnPolicy::owesReplacement($method)) {
            try {
                \App\Jobs\NotifyOwner::dispatch($t->id,
                    '🔄 Replacement owed to ' . ($o->customer_name ?: 'a customer') . ' on ' . $o->order_no
                    . ' — ' . $ret->items_text . ' came back and must be sent again.');
            } catch (\Throwable $e) { /* alerting is best-effort */ }
        }

        return response()->json([
            'ok'              => true,
            'stage'           => 'received',
            'return_id'       => $ret->id,
            'credit_note_no'  => $cnNo,
            'credit_note_pdf' => ($cn && $cn->pdf_path) ? Storage::disk('public')->url($cn->pdf_path) : null,
            'credits'         => ReturnPolicy::issuesCredit($method),
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

    /** The goods never came. Frees the cap again; the record stays for the history. */
    public function cancel(Request $request)
    {
        $u = $request->user();
        $t = $u?->tenant;
        if (! $t) {
            return response()->json(['ok' => false], 401);
        }
        $ret = ReturnRecord::withoutGlobalScopes()->where('tenant_id', $t->id)->find((int) $request->input('return_id'));
        if (! $ret) {
            return response()->json(['ok' => false, 'error' => 'Return not found.'], 404);
        }
        if (! ReturnPolicy::canConfirm((string) $ret->status)) {
            return response()->json(['ok' => false, 'error' => 'Only a return still waiting for goods can be cancelled.'], 422);
        }
        $ret->status = ReturnPolicy::CANCELLED;
        $ret->reason = trim((string) ($ret->reason . ' | cancelled by ' . ($u->name ?? '')));
        $ret->save();

        return response()->json(['ok' => true, 'stage' => 'cancelled', 'return_id' => $ret->id]);
    }

    /** Stock back on the shelf — the SAME ledger the sale used, gated the same way. */
    private function restock($t, Order $o, ReturnRecord $ret, array $clean, ?string $cnNo, string $by): int
    {
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
                            'created_by' => $by,
                        ]);
                        $restocked++;
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('panel-return restock failed: ' . $e->getMessage());
        }

        return $restocked;
    }

}
