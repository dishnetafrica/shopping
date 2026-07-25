# Asset Lifecycle Extension — Accepted Architecture Decisions

> Formerly "Solar Extension." Renamed per architecture review: what we are building is a generic
> **asset lifecycle** platform (specs, serials, warranty, kits, work orders, service). Solar is
> merely the first tenant/preset. The core stays generic; industries are presets on top.

These decisions are **approved** and bind all future sessions. One capability per session,
plan → review → code, extend the existing domain model (never fork; never duplicate
inventory/sales/accounting).

## Naming & packaging
- **No `SolarCapabilityProvider`.** Build generic providers:
  `ProductSpecsCapabilityProvider`, `SerializedInventoryCapabilityProvider`,
  `WarrantyCapabilityProvider`, `KitCapabilityProvider`, `FieldServiceCapabilityProvider`.
- **`SolarPreset`** simply enables the relevant modules and seeds starter data (spec catalogue, etc.).
  Other presets (Electrical, Generators, HVAC, Networking, Medical) reuse the same modules.
- Per-tenant enablement via the existing `setting('modules')` / `setting('vertical')` mechanism
  (same pattern the WinWorld module already uses).

## Module decisions
1. **Product Specifications = a CORE platform capability**, not an extension. Useful to every
   business (furniture, electronics, food, industrial, medical). The solar preset only *seeds* the
   starter catalogue (Wattage, Voltage, Ah, IP Rating, …).
2. **Serialized inventory: table is the source of truth.** `inventory_units` holds `status`,
   `location`, `order_id`, `customer_id`, `warranty_id`, timestamps. Events
   (`SerialRegistered/Reserved/Sold/Returned/Scrapped`) are the **audit log**, not the state — we do
   NOT reconstruct current state from events. (Dual pattern, like the money path: row authoritative,
   event mirrors. Simpler; still full history/replay/audit.)
3. **Kits support two modes from day one** (design the model once):
   - *Virtual kit* — inventory deducted on sale (explode-on-issue). Default for solar.
   - *Preassembled kit* — inventory deducted at assembly (`KitAssembled`); sold as one unit.
     For gift hampers, first-aid/CCTV kits, packaged routers, promo bundles.
4. **Warranty attaches to EITHER a serial OR a non-serialized invoice line.** Solar cable, bulk
   batteries, LED fixtures and accessories have warranties without serials. Support serial-warranty
   and invoice-line-warranty both.
5. **"Installation Jobs" → generic `Work Orders`** (module `field_service`). Serves solar, HVAC,
   generators, CCTV, networking, electricians. Installation is one work-order type.
6. **Manufacturer is a first-class entity**, distinct from Brand and Supplier
   (e.g. Manufacturer = Victron, Brand = Victron, Supplier = ABC Imports). Warranty claims usually
   route to the manufacturer.
7. **Serialized units support QR/barcode + attachments/certificates from the start**: scan-to-open a
   warranty; attach installation certificate, warranty certificate, test report, delivery note, photos.

## Non-negotiables (unchanged from the core Constitution)
- Multi-tenant (every table `tenant_id`, global tenant scope).
- Permission-aware (each capability declares a `permission`, gated via `Access.php`).
- Auditable (state changes emit events on the `bos_events` spine).
- Do NOT modify accounting; do NOT duplicate inventory or sales — extend via existing seams
  (`StockLedger`, quotations, invoices, renderers).

## Build order (one capability per session)
Phase 1: **S1 Product Specifications (core)** → S2 Serialized Inventory → S3 Warranty → S4 Kits.
Phase 2: S5 Work Orders → S6 Service Visits → S7 Warranty Claims → S8 Project Quotes.
Manufacturer, QR/attachments land alongside S2/S3.

## Decisions confirmed by the owner (2026-07-25)
- Specs stored as configurable rows (definitions + values), not a JSON blob. ✅
- Serial enforcement rolls out observe-first, hard-stop later. ✅
- Kits: explode-on-sale default, preassembled mode available. ✅
- Granular modules + solar preset (not one monolithic solar flag). ✅
