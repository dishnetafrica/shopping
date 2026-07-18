# CONSIGNMENTS-V3-DEPLOY.md — the Juba loads, native in v3

## What this is
The full classic consignment tool as v3 workspaces — the biggest item left on the
honest tail. Inventory → **Consignments** (on-board badge = orders travelling now),
each opening into a 3-tab record: **Manifest** (every order + customer + status) ·
**Stock** (requested / sent / sold with live margin) · **Details** (bus, driver, agent).

## The role split — preserved exactly
- **Delivery side is OPEN** (Asish at the Juba end): "Deliver ALL on-board" (one tap,
  customers get their WhatsApp automatically) and "Set one order's status"
  (delivered / returned / on-board, with a note). Pinned by test: field role gets
  these and ONLY these.
- **Management side is owner/manager**: New consignment (with automatic carry-forward
  of unsent stock from the last load — the prefill even names which one), Add order /
  Add ALL ready, Remove, stock add / update / sell, WhatsApp-customers-on-arrival,
  Manifest sheet (print). Server re-enforces priv() on every one.

All ~12 operations delegate to the proven /papi/consignment-* endpoints — zero new
write paths, the ShipmentService engine untouched.

## Also in this zip
- **Redirect map**: /panel/consignment now → the v3 workspace (out of the honest tail —
  only /panel/scheduled + the admin pages remain classic).
- **Help & Training**: a new "Consignments (Juba loads)" chapter — sending-side steps
  for you, receiving-side steps for Asish, in his manual because it's gated on the
  orders menu he holds.
- **Sidebar badge**: on-board order count.

## Files (10)
ConsignmentListCapability + ConsignmentCapability (NEW) · CoreCapabilityProvider ·
NavCountsCapability · shell.blade.php · ClassicPanelRedirects · V3RetireCommand ·
config/guide.php · ConsignmentWorkspaceTest (4 tests) · this doc

## Deploy
Upload → Rebuild → `php artisan test --filter='ConsignmentWorkspace|ClassicRedirects|GuideWorkspace'`
→ /v3 → Inventory → Consignments. Best QA: Asish opens one on his phone and taps
"Deliver ALL" on a test consignment.

NOTE: if you deploy this AFTER the consolidated zip (recommended), the six shared
files here are strict supersets of that zip's versions — safe to overwrite.
