# 5 — UX Constitution

**Status:** DRAFT for approval · The last document to freeze — because once objects, processes,
events, and projections are settled, the UX is *how they feel* on a phone in the field.

**The single success test** for every UX decision:

> **Can a salesperson complete an entire day of work with one thumb, on an unreliable connection,
> without thinking about "modules", "sync", or "which screen"?**

---

## The UX principles

**U1 — Activity‑first.** The user sees *work* ("Visit ABC", "Collect $420", "Send receipt"), never
database tables. Documents are produced *by* completing an activity — never a place you navigate to.

**U2 — Customer‑centered.** Journeys begin at a customer; a customer is one timeline (PJ2), not a set
of lists to cross‑reference.

**U3 — Workflow‑first, not screen‑first.** The UI follows the process (layer 2). Quote→Cash is one
continuous flow, not six pages. The next step is always one obvious tap.

**U4 — Mobile‑first, one codebase.** A single PWA is the product on Android, iPhone, and desktop.
Desktop is the *wide layout* of the same app — never a second interface. Phone → tablet (2 col) →
desktop (3 col) is pure responsive layout.

**U5 — Offline‑first & invisible.** Losing signal is normal. Work queues locally and syncs itself.
The user **never presses "Sync" and never loses work**; the only indicator is a tiny per‑record badge
(`✓ ⟳ 📴 ⚠`). Reference: PJ8.

**U6 — Role‑aware workspaces.** Bottom nav is workspaces, not modules — **Today · Customers ·
Operations · Finance · Me** — each showing only what the person's role may see, adapting to people who
wear several hats. (Already shipped in the mobile shell.)

**U7 — Context‑aware create.** The `+` offers what makes sense *where you are* — Quote/Invoice/Payment
on a customer; Purchase/Stock in Operations; Expense/Payment in Finance. (Already shipped.)

**U8 — Action‑oriented language.** "Waiting for Payment", not "Invoices"; "Items to Receive", not
"Purchase Orders"; "Collect $420", not "Task · status · due". Expose work, not schema. (Already shipped.)

**U9 — AI assists, never blocks.** AI narrates, prioritizes, and drafts — as a layer *over* the
working app. Every screen functions with AI off. AI never shows a number it computed itself.

**U10 — Thin native wrapper.** The Android app holds no business logic — only device bridges: push,
camera, GPS, biometric, files, print, share, background sync. New features ship via the PWA with no
store update; the wrapper changes only for a genuinely new device capability.

---

## Interaction rules (so "one thumb" is real)
- Primary actions in the **bottom third** (thumb reach); nothing critical top‑left.
- **Large touch targets** (≥44px); one primary action per screen.
- **Bottom sheets**, not modals, for create/confirm.
- **Optimistic UI**: an action shows done immediately; sync reconciles in the background (U5).
- **Two taps max** to any core create from Today (via the context `+`).
- **Readable on a bright day, one‑handed, on a mid‑range Android** — the real field device.

## What this constitution is NOT
- Not a visual style guide (colors/spacing live in the design tokens already in the shell).
- Not a screen inventory — screens are projections (doc 4); this governs how they *behave*.

---

## Freeze order recap
1. Business Object Constitution → 2. Process Catalog → 3. Event Catalog → 4. Projection Catalog →
5. UX Constitution (this).

Approve 1–5, confirm the three open decisions (README), and UI resumes — every screen a visualization
of the model, starting with the **Customer Timeline** (PJ2).
