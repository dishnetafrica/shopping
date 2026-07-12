<?php

namespace App\Services\Invoicing;

use App\Models\CustomerProfile;
use App\Models\Invoice;
use App\Models\Tenant;

/**
 * A customer's statement of account, built from their NON-VOID invoices (the billing documents,
 * each in the currency the customer was actually billed — USD for export, UGX for local). Grouped
 * by currency so a South Sudan customer sees exactly what they owe in USD. Balances are the live
 * invoice balances (invoice-level payments keep them current — see InvoiceController::pay).
 */
class StatementService
{
    /** @return array{name:string,phone:string,lines:array,outstanding:array,billed:array,paid:array,count:int} */
    public function for(Tenant $t, string $phone): array
    {
        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        if ($digits === '') {
            return ['name' => '', 'phone' => '', 'lines' => [], 'outstanding' => [], 'billed' => [], 'paid' => [], 'count' => 0];
        }

        $invoices = Invoice::where('tenant_id', $t->id)
            ->whereRaw("regexp_replace(coalesce(customer_phone,''),'[^0-9]','','g') = ?", [$digits])
            ->where('status', '!=', 'void')
            ->orderBy('id')->get();

        $name = '';
        $lines = [];
        $out = []; $billed = []; $paid = [];
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
                'id'       => $inv->id,
                'due'      => optional($inv->due_at)->format('d M Y'),
            ];
            $out[$cur]    = round(($out[$cur] ?? 0) + (float) $inv->balance, 2);
            $billed[$cur] = round(($billed[$cur] ?? 0) + (float) $inv->total, 2);
            $paid[$cur]   = round(($paid[$cur] ?? 0) + (float) $inv->amount_paid, 2);
        }

        if ($name === '') {
            $cp = CustomerProfile::where('tenant_id', $t->id)
                ->whereRaw("regexp_replace(coalesce(phone,''),'[^0-9]','','g') = ?", [$digits])->first();
            if ($cp) $name = trim((string) $cp->name);
        }

        // drop zero-balance currencies from "outstanding" but keep them in billed/paid
        $out = array_filter($out, fn ($v) => $v > 0.005);

        return [
            'name'        => $name,
            'phone'       => $digits,
            'lines'       => $lines,
            'outstanding' => $out,
            'billed'      => $billed,
            'paid'        => $paid,
            'count'       => count($lines),
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
