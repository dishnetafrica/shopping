# CloudBSS v4.30 — your settings stop belonging to somebody else's trade

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.30.zip (cumulative — supersedes v4.29; 161 files) · **Migration:** none · **Delta: 9 files.**

## The problem

A grocery shop opening Settings was asked which Bajaj motorbike models to show on its website, whether to display exploded parts diagrams, and to write a *"100% genuine Bajaj — verify the hologram"* promise. The left menu offered it a **Solar Advisor**.

There was a reason: **the schema had no idea what kind of business it was looking at.** `SettingsSchema::sections()` is a flat list with no conditions in it, and `Vertical::of()` — which already knows about grocery, restaurant, snacks, manufacturer, solar and auto parts — was never consulted by the settings screen or the menu.

And you could not have fixed it yourself, because **only a master admin could set a shop's business type.** It lived in the back-office admin, not in your Settings.

## What changes

**You can now say what your business is.** Settings → Business profile → Identity → *What kind of business is this?*

**Settings that belong to another trade disappear.** A grocery no longer sees Bike models, Availability (exploded diagrams) or the parts trust promises. A restaurant also stops seeing Tenders and the Dealer programme.

**Nothing you have set up is ever hidden.** This is the rule that makes the whole thing safe:

> A group is hidden only when it does not match your business type **and** you have never set anything in it.

Family Shoppers is a grocery that also bids for tenders. TenderBot is switched on, so the Tenders group stays — nobody had to write a special case for it. When a group is kept for that reason it says so on screen: *"not usual for your business — you have set this up"*.

"Set something" means a value **different from the default**, not merely a value present. That distinction matters: the mobile settings screen posts every section at once, so a grocery that ever saved settings on a phone already has `show_model_boxer100` sitting in its settings. Treating that as intent would have hidden nothing at all.

**Nothing is ever unreachable.** A **Show other business types** link at the bottom of the settings list brings everything back.

**Solar Advisor now follows your business type.** It was shown to every shop regardless. If the server doesn't tell the menu your business type — an older build, a cached script, an error — the entry still shows. This gate can only ever reveal too much, never hide a tool someone is using.

## Two bugs fixed on the way through

**`delivery_note` existed twice** — in Business profile → Delivery *and* in the Delivery section, the same setting behind two different labels. On the mobile settings screen, which renders every section at once, both landed in the same save and whichever came second silently won. You could edit your delivery policy, save, and watch the edit vanish with no error. There is one field now, under Business profile.

**The parts wording in the catalogue trust badge** now offers a grocery example alongside the OEM one.

## One thing I deliberately did NOT fix

The Director-loan setting defaults to the lender name **"Bhavin"**, so every other shop's settings and Books screen carry your name. It is a real leak, but the same name is hardcoded in two further places (the Books controller and the Books page itself), and blanking only the schema would have zeroed a live loan balance rather than fixed anything. It needs one deliberate pass across all three, which is not this drop. It is written down in the project notes.

## After you deploy

1. **Settings → Business profile → Identity** → set *What kind of business is this?* — **Grocery / FMCG** for Family Shoppers, **Auto parts** for East Africa Auto Parts.
2. Save, and watch the sections re-filter immediately.
3. Check Tenders is still there for Family Shoppers (it should be — it's switched on).
4. Reload the panel and confirm **Solar Advisor** has gone from the Sales menu.
5. If you ever need a hidden setting: **Show other business types** at the bottom of the settings list.

No migration. If you set nothing, every shop is treated as grocery — which is what `Vertical::of()` has always defaulted to, so only the three parts-specific groups move.

## Verified before ship

**81 checks against the shipped code** — the real `SettingsSchema` and `SettingsVisibility` loaded and executed, and the nav gate lifted verbatim out of `shell.js`.

Both harnesses were run against the **old** code first and failed where they should: 16 failures on the settings one, including the duplicate `delivery_note` it detects independently.

Settings (61): the three parts groups vanish for a grocery and stay for a parts shop; **Family Shoppers keeps Tenders while losing the bikes**; a restaurant that switched TenderBot on keeps it and is flagged, while one that never touched it does not see it; mobile-save pollution at default values is correctly *not* treated as configuration, while a genuine change — including turning a bike model *off* — keeps the group; the escape hatch reveals exactly what a parts shop sees, so no group is unreachable by anybody; a null tenant and an unknown business type both fail open; no section is ever left rendering as an empty page, checked across all six business types; value comparison across bool/string/int/float so `false`, `""`, `"0"` and `0` are one thing and `0.03` equals `"0.030"`; no field key appears in two groups; and `SettingsSchema::fields()` stays **unfiltered**, because it is static, has no tenant, and the branding-image uploader derives its allow-list from it.

Menu (20): Solar Advisor hidden for grocery, restaurant and parts, shown for solar; shown when the vertical is empty and when the flags object is missing entirely; Settings, POS, AI Campaign, Orders, Products, P&L, Books and the storefront entries all unchanged; Tenders still gated on its flag; the finance entries still need the finance capability; and both copies of `shell.js` byte-identical, since the browser loads the `public/` one.

`php -l` on 5 PHP files · both JS surfaces parsed by node · v4.29's 97, v4.28's 89 and v4.27.1's 36 all re-run and still green.
