<?php

namespace App\Services\Purchasing;

use App\Models\OrderCost;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Money\Fx;

/**
 * Records a payment to a supplier — the ONE place v3 supplier payments go through. Mirrors the
 * legacy SupplierController::pay exactly: a `supplier_payment` OrderCost row with the FX-resolved
 * USD equivalent, the acting user as holder, and the tenant's cost-approval status. Reuses the
 * frozen Fx + OrderCost::toUsd; no money math is re-implemented here.
 *
 * @throws \InvalidArgumentException on a non-positive amount
 */
final class SupplierPaymentService
{
    public function record(Tenant $t, Supplier $s, User $user, array $d): OrderCost
    {
        $cur = strtoupper((string) ($d['currency'] ?? 'UGX'));
        $amount = round((float) ($d['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('bad_amount');
        }

        $rate = (float) ($d['rate_to_usd'] ?? 0);
        if ($rate <= 0) {
            $rate = Fx::forTenant($t)->ratePerUsd($cur);
        }
        if ($rate <= 0) {
            $rate = 1;
        }

        return OrderCost::create([
            'tenant_id'     => $t->id,
            'order_id'      => null,
            'order_no'      => null,
            'supplier_id'   => $s->id,
            'supplier_name' => $s->name,
            'stage'         => 'supplier_payment',
            'currency'      => $cur,
            'native_amount' => $amount,
            'rate_to_usd'   => $rate,
            'usd_amount'    => OrderCost::toUsd($amount, $rate),
            'holder'        => (string) ($user->name ?? ''),
            'holder_user_id' => $user->id,
            'pay_status'    => 'cash',
            'paid_native'   => $amount,
            'note'          => 'Paid ' . $s->name,
            'status'        => ((bool) $t->setting('cost_approval', true)) ? 'pending' : 'approved',
            'created_by'    => (string) ($user->name ?? ''),
        ]);
    }
}
