# Cash Handover — V3 version + cleanup (you uploaded the earlier zip, so read this)

You uploaded my earlier `cash-handover.zip`, which added a handover button on the **old Staff Cash
screen**. This package moves everything to your **V3 Cashbook** (your main app) and cleans up the old
button so there is ONE handover engine. Two tiny steps:

## Step 1 — Upload these files (overwrites the old ones)
Upload everything in this folder to your repository, **keeping the folders**. This:
- upgrades your V3 "Moved cash between people" to run on the new capability, and
- removes the old Staff Cash button + its endpoint (by replacing those 3 files with clean versions).

## Step 2 — Delete ONE leftover file (upload can't remove files)
Open this file on GitHub and delete it (the **trash 🗑 icon**, top-right → **Commit changes**):

    tests/Feature/Kernel/StaffCashHandoverTest.php

Direct link:
https://github.com/dishnetafrica/shopping/blob/main/tests/Feature/Kernel/StaffCashHandoverTest.php

That test checked the old Staff Cash endpoint we just removed, so it must go — otherwise your check
turns red. (Its replacement, `TradeCashbookHandoverTest.php`, is included here and tests the V3 path.)

## How you'll know it worked
On the **Actions** tab: **green ✓ "Money kernel (must pass)"**. If it's red, send me a screenshot.

## Then use it (the real proof)
**V3 Cashbook → Record activity → Moved cash between people.** Record a handover: the giver's cash
goes down, the receiver's up by the same amount, combined total unchanged. Do it twice quickly — it
still records once.

## What's inside
- `app/Kernel/Events/CashHandedOver.php`, `app/Kernel/Capabilities/Cash/HandOverCash.php` — the event
  + capability (unchanged from before).
- `app/Http/Controllers/Panel/TradeCashbookController.php` — the V3 "Moved cash between people"
  endpoint, cut over to the capability (same entries/response; adds event + offline guard + over-hold watch).
- `app/Http/Controllers/Panel/StaffCashController.php`, `routes/web.php`,
  `resources/panel/staff_cash.html` — **clean versions** that remove the old Staff Cash handover button/endpoint.
- `tests/…` — capability + event + V3-endpoint tests.

## Safety
- The V3 cash entries are byte-for-byte what the old code wrote, so Cash Position / Trade Cashbook and
  P&L are unchanged. Over-hold is watched, not blocked. Rollback = revert `TradeCashbookController.php`.
