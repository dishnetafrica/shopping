<?php

namespace App\Services\Invoicing;

use App\Models\CreditNote;
use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Tenant;

/**
 * A customer's statement of account, built from their NON-VOID invoices (the billing documents,
 * each in the currency the customer was actually billed — USD for export, UGX for local) and their
 * NON-VOID credit notes (which reduce what they owe). Grouped by currency so a South Sudan customer
 * sees exactly what they owe in USD. Invoice balances stay live via the payment ledger; credit notes
 * are netted off the outstanding total, so a return / adjustment / over-payment shows immediately.
 */
class StatementService
{
    /** @return array{name:string,phone:string,lines:array,outstanding:array,billed:array,paid:array,credited:array,credit_balance:array,count:int} */
    public function for(Tenant $t, string $phone): array
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        if ($digits === '') {
            return ['name' => '', 'phone' => '', 'lines' => [], 'outstanding' => [], 'billed' => [],
                    'paid' => [], 'credited' => [], 'credit_balance' => [], 'count' => 0];
        }

        // v4.52 — `items` is eager-loaded because every line now carries a description built from
        // the goods. Without this, a customer with forty invoices costs forty extra queries to
        // print one statement.
        $invoices = Invoice::where('tenant_id', $t->id)
            ->with('items')
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits])
            ->where('status', '!=', 'void')
            ->orderBy('id')->get();

        $name = '';
        $lines = [];
        $out = []; $billed = []; $paid = []; $credited = [];
        foreach ($invoices as $inv) {
            if ($name === '' && trim((string) $inv->customer_name) !== '') $name = trim((string) $inv->customer_name);
            $cur = (string) $inv->currency;
            $lines[] = [
                'no'       => $inv->invoice_no,
                'date'     => optional($inv->issued_at)->format('d M Y'),
                'currency' => $cur,
                'total'    => (float) $inv->total,
                'paid'     => (float) $inv->amount_paid,
                'balance'  => (float) $inv->balance,
                'status'   => $inv->status,
                'kind'     => 'invoice',
                'id'       => $inv->id,
                'due'      => optional($inv->due_at)->format('d M Y'),
                // v4.52 — what the charge was for. A customer asked for money against
                // "INV-2026-0068" has to go and find the invoice before they can agree they owe it.
                'detail'   => LineDescription::forPrint($inv),
            ];
            $out[$cur]    = round(($out[$cur] ?? 0) + (float) $inv->balance, 2);
            $billed[$cur] = round(($billed[$cur] ?? 0) + (float) $inv->total, 2);
            $paid[$cur]   = round(($paid[$cur] ?? 0) + (float) $inv->amount_paid, 2);
        }

        // Credit notes reduce what the customer owes (netted per currency + shown as their own lines).
        $creditNotes = CreditNote::where('tenant_id', $t->id)
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits])
            ->where('status', '!=', 'void')
            ->orderBy('id')->get();
        foreach ($creditNotes as $cn) {
            if ($name === '' && trim((string) $cn->customer_name) !== '') $name = trim((string) $cn->customer_name);
            $cur = (string) $cn->currency;
            $amt = round((float) $cn->total, 2);
            $lines[] = [
                'no'       => $cn->credit_note_no,
                'date'     => optional($cn->issued_at)->format('d M Y'),
                'currency' => $cur,
                'total'    => -$amt,          // negative — a credit on the account
                'paid'     => 0.0,
                'balance'  => -$amt,
                'status'   => 'credit',
                'kind'     => 'credit',
                'reason'   => CreditNote::reasonLabel((string) $cn->reason_code),
                'against'  => (string) ($cn->invoice_no ?? ''),
                'id'       => $cn->id,
                'due'      => '',
            ];
            $out[$cur]      = round(($out[$cur] ?? 0) - $amt, 2);
            $credited[$cur] = round(($credited[$cur] ?? 0) + $amt, 2);
        }

        if ($name === '') {
            $cp = CustomerProfile::where('tenant_id', $t->id)
                ->whereRaw("regexp_replace(coalesce(phone,''),'[^0-9]','','g') = ?", [$digits])->first();
            if ($cp) $name = trim((string) $cp->name);
        }

        // A net-negative balance means the customer is IN CREDIT (we owe them). Split it out so
        // "outstanding" only ever lists positive amounts owed to us.
        $creditBalance = [];
        foreach ($out as $cur => $v) {
            if ($v < -0.005) $creditBalance[$cur] = round(-$v, 2);
        }
        $out = array_filter($out, fn ($v) => $v > 0.005);

        return [
            'name'           => $name,
            'phone'          => $digits,
            'lines'          => $lines,
            'outstanding'    => $out,
            'billed'         => $billed,
            'paid'           => $paid,
            'credited'       => $credited,
            'credit_balance' => $creditBalance,
            'count'          => count($lines),
        ];
    }

    /** "USD 380, UGX 50,000" style summary of a currency=>amount map. */
    public static function fmtMap(array $map): string
    {
        $parts = [];
        foreach ($map as $cur => $amt) {
            $dec = in_array($cur, ['USD'], true) ? 2 : 0;
            $parts[] = $cur . ' ' . number_format((float) $amt, $dec);
        }
        return $parts ? implode(', ', $parts) : '0';
    }
}
