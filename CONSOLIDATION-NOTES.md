# Owner Consolidation — everything in the Seller Panel (no /app shell)

All owner workflows now live in the Seller Panel (`resources/panel/seller.html`).
Filament `/app` is kept for super-admins only.

## Navigation map (Seller Panel sidebar)
```
Dashboard
Orders
Chats              (/panel/chats)
Cashbook           (/panel/cashbook)
Staff              (/panel/staff)
Scheduled          (/panel/scheduled)
Marketing          (/panel/marketing)
Diagnostics        (/panel/diagnostics)
Setup              (/panel/setup)
Dispatch
  └ Delivery Board   (live deliveries lifecycle: assign rider, advance status)
  └ Riders
  └ Delivery Zones   (zone CRUD: keywords, fee, per-km, free-over, ETA)
Customers
POS
Category
Products
Reports
Riders
Returns
Settings
  └ Profile            (name, email; login phone shown read-only)
  └ Security           (optional password; OTP is the real login)
  └ Order Notifications (recipient CRUD)
  └ Smart Defaults      (term → product CRUD)
  └ WhatsApp Settings   (/panel/setup)
  └ Subscription        (plan info + upgrade via support)
Help
Account              -> in-panel Profile (was /app/profile)
```
(Decision 1: all existing items kept; only submenus added. Riders appears both
top-level and under Dispatch on purpose — familiarity + grouping.)

## What moved off /app
- Delivery Board, Delivery Zones, Order Notifications, Smart Defaults Filament
  resources are now `shouldRegisterNavigation() = super-admin only`.
- The "Account" sidebar link no longer points to `/app/profile`; it opens the
  in-panel Profile page. Zero `/app/...` redirects remain in the panel.

## Backend (new, tenant-scoped via BelongsToTenant)
papi (GET + ?token=, matching the panel's CSRF-free convention):
- delivery/board, delivery/assign, delivery/status, delivery/suggest-rider, delivery/quote
- delivery/zones, delivery/zone-save, delivery/zone-delete
- profile, profile-save, password-change
- notifications, notif-save, notif-delete
- defaults, default-save, default-delete
New records auto-stamp tenant_id; all reads/edits are tenant-isolated (a tenant
cannot see or modify another tenant's zones/recipients/defaults).

## Bug fixed in passing
Delivery fee fallback read the wrong settings keys (`delivery_base`…), so the
fallback fee would always be 0 on staging. Now reads the real keys
(`base/perKm/min/freeOver` from tenant settings), in both the checkout path
(BotBrain) and the quote endpoint (DeliveryController).

## Verified here
- All PHP lints; brain 63/63, final 25/25, intent 47/47, delivery 31/31.
- seller.html: both inline <script> blocks pass `node --check`; every new `$('id')`
  resolves to a real element; every init/handler function is defined; all new
  page divs exist.

## Cannot verify here (no browser/Laravel) — STAGING CHECKLIST
Deploy, then in the Seller Panel confirm:
1. Dispatch ▸ Delivery Board lists deliveries in 5 columns; "Assign rider" prompts
   and assigns (rider gets WhatsApp); advance buttons move status; customer gets
   the existing "out for delivery"/"delivered" messages.
2. Dispatch ▸ Delivery Zones: add/edit/delete a zone; checkout then shows that
   zone's fee + ETA.
3. Settings ▸ Profile: name/email save. Security: set a password (note OTP is login).
4. Settings ▸ Order Notifications: add a recipient; a new order alerts them.
5. Settings ▸ Smart Defaults: add term→product; bot honours it.
6. Account opens Profile in-panel (no /app). /app no longer shows these resources
   for a normal owner login.
7. Capture real screenshots of each for the record.

## UI note
The board/zone/forms are intentionally simple (inline forms; rider assignment via a
quick prompt). They're functional; UX polish (modals, drag-drop board) can follow
once verified on staging.
