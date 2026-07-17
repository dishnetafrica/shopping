# V3-DEFAULT-MIGRATION-PLAN.md — one product, one UI

Date: 2026-07-18 · Directive: **V3 is the default for all future development; migrate
every V2 function to V3; then disable V2.**

## 0. First — why you see two screens (and where Settings is)

This is not a bug; it's a half-finished soft migration that was designed on purpose:

- `LandingController::panel()` sends **owner / manager / sales → `/v3`** (the
  Capability shell you screenshotted) and **purchaser / field → `/panel/app`**
  (classic). `?classic=1` re-opens the old panel ("Open classic panel" in the account
  menu).
- **Settings** lives at **`/v3#/settings`** — exactly where you were. Its sub-screens
  (Business profile, Currency & rates, Invoice & tax, Documents, Stock tracking,
  WhatsApp & bot, Notifications, Delivery, Payments, Team) come from
  `SettingsCapability` and are V3's replacement for the classic `/panel/setup`,
  `/panel/system`, `/panel/staff` trio. **Operate in V3.** Only drop to classic for
  the gaps listed below.

**Correction to my earlier verification report**: I missed `/v3` because it is
registered inside `CapabilityServiceProvider`, not `routes/web.php`, and I never opened
`shell.blade.php`. The "engine V3 / experience V2" verdict was wrong for owners — the
right verdict is: **V3 is live and primary for owners; the migration stalled with
~10 workflows and 2 roles left behind.** The nav_v2 work I shipped remains useful only
as a transitional aid for those 2 roles; the centre of gravity is finishing V3.

## 1. V2 → V3 coverage matrix (measured)

V3 today = 29 registered capabilities behind `api/v1/workspace` + CommandBus.

### ✅ Already in V3 — stop using the classic screen
| V2 (classic) | V3 home |
|---|---|
| Dashboard / today | Morning Briefing + Worklist (My Work) |
| customers360 | Customers |
| leads | Leads |
| orders-costs | Sales Orders (+ SalesProcess) |
| Quotation screens | Quotations |
| invoices / invoiced | Invoices |
| payments / payments-manager | Payments + Collections |
| purchases / buy-list | Purchasing (PurchaseProcess + ProcurementDashboard) |
| suppliers / supplier-debt | Suppliers (+ supplier-statement doc API) |
| inventory-count | Warehouse + JubaStock |
| trade-cashbook (+tabs) | Cashbook (+ cashbook-export doc API) |
| pnl / reports | Finance ("Profit & Loss" is in the shell) |
| marketing (campaigns) | Campaigns |
| ads | Ads |
| setup / staff / system | Settings (all sub-screens incl. Team) |
| products/categories (Filament) | Products + Categories |
| demand plans | Demand Plan (list + object) |

### ❌ V2-only — the actual migration backlog (verified absent from the shell)
| # | V2 surface | Users | Proposed V3 capability |
|---|---|---|---|
| G1 | **Chats** (`/panel/chats` — WhatsApp inbox) | everyone, daily | `ChatsCapability` — the single biggest gap; the business is WhatsApp-first and its inbox is not in V3 |
| G2 | **Email Center** (`/panel/email-center` — Comms M1–M4) | owner | `CommunicationCapability` under Settings→Communication (backend/papi unchanged; UI re-homed). **Pilot migration — see §3** |
| G3 | **Expenses** (`/panel/expenses`) | owner/accountant | fold into Finance |
| G4 | **Cost Approvals** (`/panel/approvals`) | owner/accountant | `ApprovalsCapability` (or Worklist item type) |
| G5 | **Sourcing** (`/panel/sourcing`) | owner↔Jayesh | fold into Purchasing |
| G6 | **Consignments** (`/panel/consignment`) | stock staff | fold into Warehouse |
| G7 | **Partners** (`/panel/partners`) | owner | fold into Finance |
| G8 | **Accounting Audit** (`/panel/audit`, kernel-v3.0 controls) | owner | `AuditCapability` (read-only first) |
| G9 | **Scheduled messages** (`/panel/scheduled`) | owner | fold into Campaigns |
| G10 | **Tasks/Operations, Staff cash, Billing, Diagnostics, Onboard, Guide** | mixed | Worklist absorption / Settings→Billing / keep Diagnostics classic-only (operator tool) |
| R1 | **Purchaser + Field roles** (Jayesh, Asish → `/panel/app`) | 2 roles | Their workflows are G4/G5 + mobile order flows; migrate roles only after those land |

## 2. The three rules that make V3 the default (enforced, not aspirational)

1. **New screens are V3 capabilities.** Mechanically enforced from this zip:
   `ArchitecturalDecisionsTest::test_no_new_classic_panel_screens` pins the current 60
   `resources/panel/*.html` files — a new file there fails the suite with
   *"build it as a Capability under app/Capability, not a classic panel page"*.
2. **Backends stay put.** papi/services/models are UI-agnostic; migrating a screen means
   writing a Capability that calls the same endpoints — never rewriting the engine.
   (This is why the Comms backend needs zero changes for G2.)
3. **Classic is a read-only museum.** From now: bug-fixes only in `resources/panel`;
   every feature request answered in V3.

## 3. Phases

**Phase A — decide + enforce (this zip):** landing default already correct for
owner/manager/sales; guard test added; this plan committed. *You start operating in V3
today; use classic only for §1's ❌ list.*

**Phase B — kill the daily-driver gaps:** G1 Chats (the one that forces everyone back
to classic every day), then G2 Email Center as the pattern-setting Capability port,
then G3/G4/G5 (the money-approval loop with Jayesh/Asish).

**Phase C — migrate the last 2 roles:** flip `LandingController` so purchaser/field
also land on `/v3` once G4/G5 + their mobile flows exist; retire the nav_v2
transitional work at the same moment.

**Phase D — disable V2:** `/panel/*` page routes → 302 to the V3 equivalent (papi
endpoints stay — V3 consumes some of them); keep `?classic=1` one quarter as the
escape hatch; then remove the redirect targets and archive `resources/panel` next to
the other legacy homes. Filament `/app` business panel gets the same treatment once
Products/Categories parity is confirmed (operator Admin panel stays).

## 4. Sequencing note
Do **Phase B–G1 (Chats) before** the seller.html decomposition and Operations control
tower I recommended earlier — this directive supersedes those: decomposing V2 screens
we intend to disable would be wasted motion. The boundary/decision tests, Comms
backend, and nav pre-flight all carry over unchanged; only the *UI destination*
changed from "modernize V2" to "finish V3".
