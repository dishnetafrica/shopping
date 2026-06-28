# Addendum — independent events, completion split, seller response time, accidental-click guard

Refinements to the data-discipline package after review. The change in principle: **seller delivery
and customer confirmation are independent events** — never overwrite one with the other.

## What changed
1. **No status overwrite.** When a customer confirms an order the seller hasn't marked Delivered,
   the system records ONLY `customer_confirmed_at` (+ `delivered_by = customer`). It does **not**
   set `status = Delivered` or fabricate `delivered_at`. If the seller later marks it delivered,
   both timestamps exist and the timeline shows who closed it first.
2. **Derived completion** on the `Order` model:
   - `isComplete()` — seller delivered **OR** customer confirmed.
   - `completedAt()` — whichever happened first.
   - `completedBy()` — `seller` | `customer` | null.
3. **New metrics** (daily BUSINESS block of `OrderObservatoryReport`):
   - **Completed by: seller X% · customer Y%** — the intuitive process-quality KPI. If "customer"
     climbs, the shop's operations need attention.
   - **Seller catch-up (min)** — average time the seller took to mark Delivered *after* the customer
     had already confirmed. A consistently high number means staff are leaning on customers to keep
     the system updated.
   - (kept) seller discipline: stale-nudges · self-closed · stuck-now.
4. **Accidental-click guard.** "I Received My Order" now asks "Are you sure you've received your
   complete order?" with **Yes** / **Cancel**; only the explicit Yes (a `c=1` tap) records.

## Why it matters
> 10:05  customer confirmed
> 11:12  seller marked delivered

With independent events that gap is preserved and measurable (seller catch-up = 67 min). Overwriting
status would have erased it and hidden the process problem.

## Tests
`DeliveryConfirmationTest` (two-tap confirm), `SelfUpdateConfirmationTest` (records only the customer
event, status preserved, are-you-sure guard, completedBy), `OrderStalenessWatchdogTest` (unchanged).
`completedBy()` verified across all orderings in-sandbox.
