# Cash Handover — V3 version (upload this to GitHub)

This is the **corrected** version that fits your V3 app. Instead of adding a new button on the old
Staff Cash screen, it upgrades the handover you **already have** in the V3 Cashbook
("Record activity → Moved cash between people") to run on the new capability.

Nothing you see on screen changes. What changes is what happens underneath when you use it:
it now leaves a permanent record, it's safe if the phone sends it twice, and it quietly watches for
someone handing over more than they hold.

## What to do
Upload these files to your repository, **keeping the folders** (`app/…`, `tests/…`).

> **One-time note:** if you ever uploaded my earlier `cash-handover.zip`, also delete this now-obsolete
> file: `tests/Feature/Kernel/StaffCashHandoverTest.php` (open it on GitHub → trash 🗑 → Commit).
> If you never uploaded that earlier zip, there's nothing to delete — skip this line.

## How you'll know it worked — just a colour
On the **Actions** tab, the latest run:
- **Green ✓ "Money kernel (must pass)"** → verified. The new handover tests run inside that check.
- **Red ✗** → send me a screenshot and I'll pinpoint it.

## Then use it in the app (the real proof)
In **V3 Cashbook → Record activity → Moved cash between people**, record a handover from one person to
another and watch: the giver's cash goes **down**, the receiver's goes **up** by the same amount, and
the **combined cash total stays the same**. Do the same handover twice quickly — it still records once.

## What's inside
- `app/Kernel/Events/CashHandedOver.php` — the handover fact (first *custody* event).
- `app/Kernel/Capabilities/Cash/HandOverCash.php` — the one capability that records it (emits only).
- `app/Http/Controllers/Panel/TradeCashbookController.php` — the V3 "Moved cash between people"
  endpoint, **cut over** to the capability. Same inputs, same entries, same response — now with the
  event, the offline-retry guard, and the over-hold watch. The "convert & hand to" flow that shares
  this endpoint keeps working unchanged.
- `tests/…` — the tests proving conservation, one-event-two-rows, idempotent retry, over-hold observed,
  and owner/manager permission.

## Safety notes
- **Parity cutover.** The cash entries are byte-for-byte what the old code wrote (same stages, category,
  notes), so Cash Position / Trade Cashbook are unchanged. P&L is untouched (an internal move).
- **Watching, not blocking.** Over-hold is logged as evidence; it does not stop anyone yet.
- **Rollback:** revert `TradeCashbookController.php` and the endpoint returns to its previous inline write.
