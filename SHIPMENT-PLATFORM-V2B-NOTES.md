# Shipment Platform v2B — External custody pages (tokenized, no login)

Transporter and destination agent now participate via per-shipment token links. No accounts, no
passwords. Every action writes a custody event with **actor + timestamp + box count + photo**.

## Pages (public)

- `GET  /t/{token}`         — transporter: **Confirm receipt** (when `sent_to_transporter`), **Bus departed** (when `transport_confirmed`).
- `POST /t/{token}/action`  — submits the transporter action (box_count + photo, JSON).
- `GET  /a/{token}`         — destination agent: **Confirm arrival** (when `in_transit`).
- `POST /a/{token}/action`  — submits the agent action.

The page shows the shipment, expected box count, the **read-only custody chain** (with photo
thumbnails), and the one action valid for this role+status — box-count input + camera photo. Outside
that window it shows a friendly read-only status.

## Files

**New** — `app/Http/Controllers/Panel/ShipmentTrackController.php`
- Resolves the shipment **bypassing the tenant scope** (`Shipment::withoutGlobalScopes()->where('token',…)`), then sets `TenantContext` from `shipment.tenant_id` — there is no logged-in user. (Mirrors `RiderTrackController`.)
- **Double-gated**: the route's role must be allowed the action (`ROLE_ACTIONS`) AND `ShipmentStateMachine::canApply()` must permit it from the current status. A transporter can't trigger `arrive`; an agent can't `transport_confirm`.
- Box count + photo are **required**; photo stored to the public disk under `shipments/{tenant}/…` (same base64 path as product uploads).
- Renders a self-contained mobile page (inline CSS/JS), like the rider page.

**Edited**
- `app/Services/Logistics/ShipmentStateMachine.php` — `depart` now `counts => true` so **Bus departed** is a real custody checkpoint (count optional; null still skipped by reconciliation). QA updated.
- `app/Http/Controllers/Panel/ShipmentController.php` — `show` now returns `token` so the panel can build the share links.
- `routes/web.php` — 4 public token routes, placed next to `/r/{token}` (before the storefront slug; two-segment paths anyway).
- `bootstrap/app.php` — CSRF `except` extended with `t/*`, `a/*` (the action POSTs, like `r/*`).
- `resources/panel/seller.html` — shipment detail now shows a **Share links** card (transporter + agent URLs, copy buttons) built from `origin + /t|/a + token`.
- `qa/shipment_state_machine.php` — `depart` assertion flipped to count-capable. 26/26 green.

## End-to-end you can now run

1. Panel: create shipment → **Share links** card → copy the 🚚 transporter link.
2. Open `/t/{token}` on a phone → **Confirm receipt**: enter boxes + photo → status → `transport_confirmed`, custody event saved with photo.
3. Same page now offers **Bus departed** (boxes + photo) → `in_transit`.
4. Open `/a/{token}` → **Confirm arrival** (boxes + photo) → `arrived`. Enter a smaller count → discrepancy auto-flagged on that leg, visible in the panel.

## Deploy

```bash
# GitHub → EasyPanel pull → restart, then:
php artisan optimize:clear     # new routes + CSRF config
php artisan storage:link       # only if not already linked (photos served from /storage)
```

No new migrations (Phase 1 owns the schema; `shipment_events.photo_url` already exists).

## Notes / caveats

- **Security model**: the token is the key (same as the rider link). The route path scopes the role, and actions are state-gated, but anyone with a link can act — fine for v2B. If you later want stricter isolation, split into `transport_token` / `agent_token` (one column each) — small change.
- **Photo size**: phone photos posted as base64 can be ~5–7 MB. Ensure PHP `post_max_size` / `upload_max_filesize` are comfortably above that on the box, or we add client-side downscaling next.
- Still **no notifications** and **no customer tracking** — those remain Phase 3 / 4 by design.
