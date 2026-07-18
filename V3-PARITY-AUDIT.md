# V3-PARITY-AUDIT.md — can every redirect target DO what its classic page did?

Ran before you press `v3:retire 17`. Method: every classic page in the redirect map,
its papi WRITE endpoints extracted, compared against the target workspace's actions.
Four findings; all resolved in this zip.

| # | Finding | Severity | Resolution |
|---|---|---|---|
| 1 | `/panel/expenses` → Finance, but Finance had **zero write actions** — retiring V2 would have removed expense recording | HIGH | ✅ Finance now has **"Record expense"** (same engine: POST /papi/expenses — category combo from your live category list, order_no attaches to order margin, blank = overhead) + a Recent-expenses table with status badges and month total |
| 2 | `/panel/approvals` → Purchasing, but Purchasing had **no approve/reject** — with `cost_approval` ON (your default), staff-recorded costs sit `pending` forever and Jayesh gets blocked | HIGH | ✅ Purchasing now shows an **"Awaiting approval"** chip + per-cost **Approve / Reject** quick actions (proven `/papi/order-costs/{id}/decide`; visible only to roles holding `menu:approvals` — purchaser correctly sees nothing, pinned by test) |
| 3 | `/panel/consignment` → Warehouse, but consignment is a **10-endpoint rich tool** Warehouse doesn't cover | MED | ✅ Removed from the map — **stays classic** (honest tail) until a real ConsignmentCapability is built |
| 4 | `/panel/scheduled` → Campaigns was a **semantic mistake**: that screen is scheduled **orders**, not marketing | MED | ✅ Removed from the map — stays classic |

Verified fine as-mapped: cash tabs → Cashbook (record/convert/transfer + record-payment
all present), payments-manager → Payments/Collections, sourcing → Procurement Hub (your
stated v3 flow), setup/staff/system → Settings.

## Files in this zip
| File | Change |
|---|---|
| app/Capability/Process/FinanceCapability.php | expense recording + recent-expenses table (ttl 120→45 so new expenses appear promptly) |
| app/Capability/Process/PurchaseProcessCapability.php | pending-approval chip + Approve/Reject actions |
| app/Http/Middleware/ClassicPanelRedirects.php | map: 35 → 33 entries (consignment, scheduled out) |
| app/Console/Commands/V3RetireCommand.php | honest tail listing updated |
| tests/Feature/Capability/ParityFixesTest.php | 4 tests: expense action+rows, approve/reject wiring, purchaser sees no approvals, tail pages render with redirects ON |

Deploy with (or before) the Phase C/D zips; gate:
`php artisan test --filter='Comms|Architect|ChatsWorkspace|CommunicationWorkspace|LandingV3|ClassicRedirects|ParityFixes'`

With this, `v3:retire 17` removes no function anyone uses. R4 + D6 guards re-simulated green.
