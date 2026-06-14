# ShopBot — Seller Panel Guide (for shop owners)

A plain how-to for running your shop from the panel. Open the panel link your
admin gave you and **Login**. The dashboard auto-refreshes every 20 seconds.

---

## The menu (left side)
**Dashboard** — live counts: total orders, pending, out for delivery, delivered,
cancelled, today's orders, products, customers.

**Orders** — every order. Columns: Order ID, order date, delivery date, customer,
status, amount. Use the date range, **Status** filter, **Show** count, and
**Search** (id / name / phone / item). **Export CSV** downloads the list. The eye
icon opens an order; the bin removes it.
- **Change status** from the order: New → Confirmed → Packed → Out for delivery →
  Delivered. The customer gets a WhatsApp update on each change. **Delivery date is
  stamped automatically when you mark an order Delivered.**

**Chats** — the WhatsApp inbox (a WhatsApp-style view).
- The **bot replies automatically** by default.
- **Take over** a chat to answer a customer yourself — the bot pauses for that chat.
- Switch the bot **off/on** per your settings when you want humans to handle chats.

**Dispatch** — assign a rider and send the customer a **live tracking link**.
Dispatching sets the order to "Out for delivery", texts the customer the link, and
shares the rider's number. (Pro plan.)

**Cashbook** — money in/out for the shop.

**Staff** — your team members and their access.

**Scheduled** — orders/campaigns set for later; they advance automatically.

**Marketing** — build a WhatsApp campaign (message + optional image + products +
call-to-action), pick the audience, and send. Sends are **throttled** (a few
seconds between messages) to protect your number from bans — this is deliberate.

**Diagnostics** — connection/health checks.

**Setup** — shop settings: delivery pricing (base fee, per-km, minimum, free-over
threshold, store location pin), bot mode, owner alert phone, branding.

**Customers** — your customer list and profiles.

**POS** — ring up a walk-in sale at the counter (creates an order).

**Category / Products** — your catalogue. Add/edit products (name, price, stock,
keywords, image). Good keywords help the bot match what customers type.

**Reports** — sales and performance.

**Riders** — your delivery riders.

---

## Daily flow during the pilot
1. **Morning:** open the panel, glance at the dashboard, confirm the bot is on
   (Chats).
2. **As orders arrive:** you get a WhatsApp alert. Open the order, **Confirm**,
   **Pack**, then **Dispatch** (assign rider) — the customer is updated at each step.
3. **On delivery:** mark **Delivered** (delivery date records itself).
4. **Anytime:** jump into **Chats** and **Take over** if a customer needs a human.
5. **Promotions:** use **Marketing** to message customers (mind the throttle).

## Tips
- Better product **keywords** = fewer "which one did you mean?" questions from the bot.
- Set **Smart Defaults** (admin) so that when a customer says just "sugar", the bot
  picks your usual pack instead of asking.
- Keep **stock** updated — out-of-stock items aren't auto-added; the bot asks instead.
- If a chat looks stuck in a loop, the bot **auto-pauses** it and alerts you — just
  take over.
