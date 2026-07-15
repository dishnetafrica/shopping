# CloudBSS — Storefront menu (tabbed) in v3

Consolidates the three storefront-config editors into **one "Storefront" sidebar
item** with tabs, instead of separate menu entries.

Storefront ▸ **Categories** · **Shop for Every Need** · **Ready-made Kits**

## What changed
- Single file: `resources/panel/seller.html`.
- The sidebar now has one **🏪 Storefront** item (the separate "Category",
  "Shop for Every Need", and "Ready-made Kits" items are gone).
- Opening it shows a tab bar; each tab loads its existing editor unchanged
  (same fields, same `/papi` save endpoints). Categories is the default tab.
- No backend change — this is purely how the panel is organised. The editors,
  their data, and the storefront rendering are identical.

## Requires
Deploy on top of the "Ready-made Kits" bundle (which already contains Shop for
Every Need). This replaces only the `seller.html` from that bundle — the other
four files (StorefrontController, PanelApiController, routes, shop.html) are
unchanged.

Verified: seller.html JS compiles; Playwright — Storefront opens on Categories,
tabs switch between all three editors, one "Storefront" nav item highlights, the
title reads "Storefront", and leaving the section hides it cleanly. No new errors.
