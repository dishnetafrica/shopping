<?php

namespace App\Http\Controllers\Panel;

use App\Models\DeliveryZone;
use App\Models\OrderNotificationRecipient;
use App\Models\Product;
use App\Models\ProductDefault;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Owner-facing settings/dispatch endpoints so EVERYTHING the store owner manages
 * (delivery zones, profile, security, order-notification recipients, smart defaults)
 * lives in the Seller Panel — no /app shell. Auth + tenant scope come from the panel
 * route group (web + auth + SetTenantFromUser); models auto-scope via BelongsToTenant.
 */
class PanelOwnerController
{
    // ---------------- Delivery Zones (Dispatch) ----------------

    public function zones()
    {
        $rows = DeliveryZone::orderBy('name')->get()->map(fn (DeliveryZone $z) => [
            'id'         => (int) $z->id,
            'name'       => (string) $z->name,
            'active'     => (bool) $z->active,
            'keywords'   => array_values((array) ($z->match_keywords ?? [])),
            'flat_fee'   => (int) $z->flat_fee,
            'per_km_fee' => $z->per_km_fee !== null ? (int) $z->per_km_fee : null,
            'min_fee'    => (int) $z->min_fee,
            'free_over'  => $z->free_over !== null ? (int) $z->free_over : null,
            'eta'        => (int) $z->eta_minutes,
            'lat'        => $z->center_lat, 'lng' => $z->center_lng, 'radius_m' => $z->radius_m,
        ])->values()->all();
        return response()->json(['ok' => true, 'zones' => $rows]);
    }

    public function zoneSave(Request $r)
    {
        $kw = collect(explode(',', (string) $r->query('keywords', '')))
            ->map(fn ($x) => trim(mb_strtolower($x)))->filter()->values()->all();
        $data = [
            'name'           => trim((string) $r->query('name', '')) ?: 'Zone',
            'active'         => $r->query('active', 'true') !== 'false',
            'match_keywords' => $kw,
            'flat_fee'       => (int) $r->query('flat_fee', 0),
            'per_km_fee'     => $r->filled('per_km_fee') ? (int) $r->query('per_km_fee') : null,
            'min_fee'        => (int) $r->query('min_fee', 0),
            'free_over'      => $r->filled('free_over') ? (int) $r->query('free_over') : null,
            'eta_minutes'    => (int) $r->query('eta', 45),
            'center_lat'     => $r->filled('lat') ? (float) $r->query('lat') : null,
            'center_lng'     => $r->filled('lng') ? (float) $r->query('lng') : null,
            'radius_m'       => $r->filled('radius_m') ? (int) $r->query('radius_m') : null,
        ];
        $id = (int) $r->query('id', 0);
        $zone = $id ? DeliveryZone::find($id) : new DeliveryZone();
        if (! $zone) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        $zone->fill($data); $zone->save();
        return response()->json(['ok' => true, 'id' => $zone->id]);
    }

    public function zoneDelete(Request $r)
    {
        $z = DeliveryZone::find((int) $r->query('id'));
        if ($z) $z->delete();
        return response()->json(['ok' => true]);
    }

    // ---------------- Profile & Security (Account) ----------------

    public function profile(Request $r)
    {
        $u = $r->user();
        return response()->json(['ok' => true, 'profile' => [
            'name'  => (string) ($u->name ?? ''),
            'email' => (string) ($u->email ?? ''),
            'phone' => (string) ($u->phone ?? ''),
            'role'  => (string) ($u->role ?? 'owner'),
            'login_method' => 'otp',   // seller login is WhatsApp OTP
        ]]);
    }

    public function profileSave(Request $r)
    {
        $u = $r->user();
        if ($r->filled('name'))  $u->name  = trim((string) $r->query('name'));
        if ($r->filled('email')) $u->email = trim((string) $r->query('email'));
        $u->save();
        return response()->json(['ok' => true]);
    }

    /** Optional password (login is OTP-only; password is a fallback only). */
    public function passwordChange(Request $r)
    {
        $new = (string) $r->query('new', '');
        if (strlen($new) < 8) return response()->json(['ok' => false, 'error' => 'too_short'], 422);
        $u = $r->user();
        if ($u->password && ! Hash::check((string) $r->query('current', ''), $u->password)) {
            return response()->json(['ok' => false, 'error' => 'wrong_current'], 422);
        }
        $u->password = Hash::make($new); $u->save();
        return response()->json(['ok' => true]);
    }

    // ---------------- Order Notification Recipients (Settings) ----------------

    public function notifications()
    {
        $rows = OrderNotificationRecipient::orderBy('id')->get()->map(fn ($x) => [
            'id' => (int) $x->id, 'name' => (string) $x->name,
            'phone' => (string) $x->phone, 'active' => (bool) $x->active,
        ])->values()->all();
        return response()->json(['ok' => true, 'recipients' => $rows]);
    }

    public function notifSave(Request $r)
    {
        $phone = preg_replace('/\D+/', '', (string) $r->query('phone', ''));
        if ($phone === '') return response()->json(['ok' => false, 'error' => 'phone_required'], 422);
        $id = (int) $r->query('id', 0);
        $rec = $id ? OrderNotificationRecipient::find($id) : new OrderNotificationRecipient();
        if (! $rec) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        $rec->name   = trim((string) $r->query('name', '')) ?: 'Recipient';
        $rec->phone  = $phone;
        $rec->active = $r->query('active', 'true') !== 'false';
        $rec->save();
        return response()->json(['ok' => true, 'id' => $rec->id]);
    }

    public function notifDelete(Request $r)
    {
        $rec = OrderNotificationRecipient::find((int) $r->query('id'));
        if ($rec) $rec->delete();
        return response()->json(['ok' => true]);
    }

    // ---------------- Smart Defaults (Settings) ----------------

    public function defaults()
    {
        $names = Product::pluck('name', 'id');
        $rows = ProductDefault::orderBy('term')->get()->map(fn (ProductDefault $d) => [
            'id'      => (int) $d->id,
            'term'    => (string) $d->term,
            'product_id' => (int) $d->product_id,
            'product' => (string) ($names[$d->product_id] ?? '—'),
            'active'  => (bool) $d->active,
            'source'  => (string) ($d->source ?? 'owner'),
        ])->values()->all();
        return response()->json(['ok' => true, 'defaults' => $rows]);
    }

    public function defaultSave(Request $r)
    {
        $term = trim(mb_strtolower((string) $r->query('term', '')));
        $pid  = (int) $r->query('product_id', 0);
        if ($term === '' || ! $pid) return response()->json(['ok' => false, 'error' => 'missing'], 422);
        $id = (int) $r->query('id', 0);
        $d = $id ? ProductDefault::find($id) : ProductDefault::firstOrNew(['term' => $term]);
        if (! $d) return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        $d->term       = $term;
        $d->product_id = $pid;
        $d->active     = $r->query('active', 'true') !== 'false';
        if (! $d->source) $d->source = 'owner';
        $d->save();
        return response()->json(['ok' => true, 'id' => $d->id]);
    }

    public function defaultDelete(Request $r)
    {
        $d = ProductDefault::find((int) $r->query('id'));
        if ($d) $d->delete();
        return response()->json(['ok' => true]);
    }
}
