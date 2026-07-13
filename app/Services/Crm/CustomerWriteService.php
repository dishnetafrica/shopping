<?php

namespace App\Services\Crm;

use App\Models\CustomerProfile;
use App\Models\Order;
use App\Models\Tenant;

/**
 * Thin CRM write service — the ONE place v3 customer create/update goes through. Mirrors the legacy
 * customerSave exactly: profiles are keyed by (tenant, normalised phone); saving a name also
 * propagates it onto that customer's orders (the panel promises editing the name updates it there).
 * No business logic beyond that field mapping.
 */
final class CustomerWriteService
{
    /** Create or upsert a customer profile by phone. @throws \InvalidArgumentException when phone is blank */
    public function create(Tenant $t, array $d): CustomerProfile
    {
        $phone = preg_replace('/[^0-9]/', '', (string) ($d['phone'] ?? ''));
        if ($phone === '') {
            throw new \InvalidArgumentException('phone_required');
        }
        $c = CustomerProfile::firstOrNew(['tenant_id' => (int) $t->id, 'phone' => $phone]);
        $this->apply($c, $d);
        $c->save();
        $this->syncOrders($t, $phone, (string) $c->name);

        return $c;
    }

    /** Update an existing profile's fields in place (identity/record unchanged). */
    public function update(Tenant $t, CustomerProfile $c, array $d): CustomerProfile
    {
        if (array_key_exists('phone', $d)) {
            $ph = preg_replace('/[^0-9]/', '', (string) $d['phone']);
            if ($ph !== '') {
                $c->phone = $ph;
            }
        }
        $this->apply($c, $d);
        $c->save();
        $this->syncOrders($t, (string) $c->phone, (string) $c->name);

        return $c;
    }

    /** Map only the fields present in $d onto the model. */
    private function apply(CustomerProfile $c, array $d): void
    {
        // "Business name" is the customer's canonical display name (flows onto orders).
        if (array_key_exists('business_name', $d) && trim((string) $d['business_name']) !== '') {
            $c->name = trim((string) $d['business_name']);
        }
        foreach (['name', 'contact_person', 'alt_phone', 'email', 'address', 'city', 'country', 'segment', 'notes', 'assigned_rep', 'follow_up'] as $f) {
            if (array_key_exists($f, $d)) {
                $c->{$f} = trim((string) $d[$f]);
            }
        }
        if (array_key_exists('credit_limit', $d)) {
            $v = trim((string) $d['credit_limit']);
            $c->credit_limit = $v === '' ? null : (float) $v;
        }
    }

    /** Stamp the last time the shop contacted this customer (call/visit). */
    public function logContact(Tenant $t, CustomerProfile $c): CustomerProfile
    {
        if ($c->isFillable('last_contacted_at')) { $c->last_contacted_at = now(); $c->save(); }

        return $c;
    }

    /** Stamp the last WhatsApp touch. */
    public function logWhatsapp(Tenant $t, CustomerProfile $c): CustomerProfile
    {
        if ($c->isFillable('last_whatsapp_at')) { $c->last_whatsapp_at = now(); $c->save(); }

        return $c;
    }

    private function syncOrders(Tenant $t, string $phone, string $name): void
    {
        if ($phone === '' || trim($name) === '') {
            return;
        }
        Order::where('tenant_id', (int) $t->id)->where('customer_phone', $phone)->update(['customer_name' => $name]);
    }
}
