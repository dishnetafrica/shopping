# Billing in V3 (view-first)

This migrates the **Billing** screen to CloudBSS V3 — the plan/subscription view — **without touching
payments**. It's the next legacy-panel gap closed.

## What you get

A new **Billing** item in the V3 sidebar (under **Settings**). It shows:

- Your **current plan** and status — days left on trial, or the renewal date.
- **Orders this month** vs your plan's limit.
- A clear **Free vs Starter vs Pro** comparison — price, order limit, team size, and what each includes,
  with your current plan flagged.
- An **Upgrade / manage payment** button that opens your existing secure billing page, where Mobile
  Money and card payment work exactly as they do today.

**Payments are untouched.** The V3 screen only *shows* plan information (pulled from the same plans
config and plan methods the app already uses); the actual charging still runs on the existing secure
page. Rebuilding the payment flow itself inside V3 would be a separate, carefully-planned step.

---

## ⚠️ Prerequisite

This bundle ships the **cumulative** capability registry (`CoreCapabilityProvider.php`) and V3 shell
(`shell.blade.php`) — they include all the earlier V3 screens (Phase 2 + Module Manager). Make sure
those earlier drops are already uploaded (they are, if you've been uploading in order); this file
supersedes them with the latest, so nothing is lost.

## Files in this zip (5)

**New**

- `app/Capability/Process/BillingCapability.php` — the V3 Billing screen
- `tests/Feature/Kernel/BillingWorkspaceTest.php` — proves it

**Replace**

- `app/Capability/Registry/CoreCapabilityProvider.php` — registers Billing
- `resources/views/shell.blade.php` — Billing in the V3 sidebar (Settings)
- `docs/v3-migration/MIGRATION-TRACKER.md`

No new routes, no payment code — the screen reads plan info and links out for payment.

---

## How to upload (GitHub, branch `main`)

Drag the folders (`app`, `resources`, `tests`, `docs`) into your repo at the same paths. If a new file
doesn't appear, use these links (paste contents, commit):

- `github.com/dishnetafrica/shopping/new/main?filename=app/Capability/Process/BillingCapability.php`
- `github.com/dishnetafrica/shopping/new/main?filename=tests/Feature/Kernel/BillingWorkspaceTest.php`

## How you know it worked

Green ✓ on **"Money kernel (must pass)"**. Then open V3 → **Settings → Billing** to see your plan and
the comparison; the Upgrade button opens the existing payment page.

---

## What's next

The last legacy-panel gap: **Roles & Permissions in V3** (team access control) — high-stakes, so I'll
open with its own plan for review.
