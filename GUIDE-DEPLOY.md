# GUIDE-DEPLOY.md — Help & Training inside v3 (the UCRM-style manual)

## What this is
A **Help & Training** workspace in the v3 sidebar (Overview group, book icon) — the
built-in manual, like UISP/UCRM's. Three properties make it more than a static page:

1. **Role-aware** — chapters are gated by the same menu keys as the screens they teach.
   Jayesh opens it and sees Getting started · Sales · Purchasing (his buyer flow) ·
   Cash — and nothing about Email Center admin. You see all 8 chapters.
2. **Reading and doing, one tap apart** — every chapter ends with "Try it now" links
   that open the actual workspace it just explained.
3. **Editable without code** — ALL wording lives in `config/guide.php`. Change any
   sentence, add a chapter, redeploy. The suite verifies every deep link still targets
   a real workspace, so the manual can never point at a screen that doesn't exist.

## Chapters shipped (content written from your real flows)
Getting started (everyone) · Sales & orders · Purchasing — buyer flow + the approver's
side · Cash & money (the three-currency Cashbook rules) · Finance & expenses (overhead
vs order-attached, the rate rule) · WhatsApp chats (bot ⇄ takeover ⇄ hand back) ·
Email Center admin (setup 1-2-3-4, dark launch → go live) · Settings & team (who sees
what, per role).

## Files
| File | Change |
|---|---|
| config/guide.php | NEW — the entire manual content (edit freely) |
| app/Capability/Process/GuideCapability.php | NEW — renders config into a tabbed workspace |
| app/Capability/Registry/CoreCapabilityProvider.php | EDIT — registers `guide` (open permission) |
| resources/views/shell.blade.php | EDIT — Overview group gains Help & Training + book icon |
| app/Http/Middleware/ClassicPanelRedirects.php | EDIT — /panel/guide now redirects here (out of the honest tail) |
| app/Console/Commands/V3RetireCommand.php | EDIT — tail listing updated |
| tests/Feature/Capability/GuideWorkspaceTest.php | NEW — owner sees 8 chapters, purchaser sees only theirs, every link verified, classic redirect pinned |

## Deploy
Upload → Rebuild → `php artisan test --filter='GuideWorkspace|ClassicRedirects'`
→ /v3 → Overview → **Help & Training**. Have Jayesh open it on his phone — what he
sees IS his training manual.

## Extending it later ("put details where necessary")
Add a chapter = one array block in config/guide.php (key, title, menu gate, cards,
links). Good candidates as they come up: a Consignments chapter when that capability
is built, a storefront chapter for sales staff, Gujarati/Hindi wording if staff prefer.
