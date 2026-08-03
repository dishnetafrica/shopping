# CloudBSS v4.21 — TenderBot has a home in the menu

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.21.zip (cumulative — supersedes v4.20.1; 132 files) · **Migration:** none new. **Delta: 6 files.**

## The problem

TenderBot only existed at a URL you had to type (`/papi/tenders`). In the v3 panel there was nothing to click.

## What this adds

**A "Tenders" entry in the v3 left menu, under SALES (right after Leads)** — trophy icon, opens the TenderBot screen inside the panel like POS and AI Campaign do.

It appears **only when TenderBot is switched on for that business**: the catalog endpoint now sends a `flags` block, and `flags.tenders` is true when the signed-in user is owner/admin **and** Settings → Storefront → Tenders (TenderBot) → *Watch for tenders* is ON. Every other tenant's menu is untouched. The flag is read again on the shell's 45-second refresh, so after you save the setting the menu entry appears **without reloading the page**.

The `/papi/tenders` URL still works directly, exactly as before.

## Also in this drop

- The Settings help text no longer says the AI classifies into "ISP / General Trading / Both / Ignore" — since v4.20.1 it classifies into **your own** division names, and the help now says so and gives an example.
- Both Tenders help lines now tell you the switch also puts *Tenders* in the left menu.
- The "← Settings" link on the TenderBot page opens in the **whole window** (`target="_top"`) instead of inside the embedded frame.

## How to see it

Upload the delta (6 files) → Rebuild → sign in to the panel → **Settings → Storefront → Tenders (TenderBot) → Watch for tenders: On → Save**. Within a minute (or on your next page load) **Tenders** appears in the left menu under SALES. Click it — that is the drop-files / Scan-forum-now / recent-tenders screen.

If it does not appear: you are signed in as a non-owner role, or the setting is still Off.

## Changed files

`app/Capability/Http/WorkspaceController.php` (new to the zip — catalog `flags`) · `resources/v3/shell.js` + `public/assets/v3/shell.js` (menu entry, icon, route, flag plumbing — kept byte-identical to each other) · `app/Http/Controllers/Panel/TenderController.php` · `app/Support/SettingsSchema.php`.

## Verified before ship

Real v3 shell rendered in a browser against a stubbed catalog: flag ON → menu item present, labelled "Tenders", icon path renders, route mounts the `/papi/tenders?embed=1` frame, item highlights as active; flag OFF **and** flag absent → no menu item, one fewer entry, no errors; sidebar order confirmed `… Leads | Tenders | Sales Orders …`; live-flip test (flag flips true on the poll) → entry appears with no reload · `php -l` ×3 · `node --check` · both shell.js copies md5-identical · zip md5-verified (132 files).
