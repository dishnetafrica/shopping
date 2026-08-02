# CloudBSS v4.8.2 — Storefront 2.0 Phase 3: IDENTIFY (photo → part, on the website)

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.8.2.zip (cumulative — supersedes v4.8.1 and everything before it) · **Migration:** same single guarded one (`php artisan migrate` — skip if already run).

## What it does

A customer taps **"📷 Photo of old part"** on the storefront, takes a photo of the worn part in their hand, and in a few seconds gets a bottom sheet: *"We think it's one of these"* — up to 3 catalogue products with confidence labels (**VERY LIKELY · 86%** / POSSIBLE / MAYBE), each as a full product card (OEM, price, stock, Add to cart, WhatsApp buy). One tap opens the product. If nothing matches confidently — or the vision service is down, or the deploy is old — the sheet offers **"Send it on WhatsApp"** with a pre-filled message, so the customer NEVER dead-ends.

This is the same vision capability your WhatsApp bot has had for weeks, now with a second front door. Same OpenAI key conventions (per-tenant `openai_api_key` setting overrides the global key), vision-capable model enforced.

## How it works (and what protects you)

- **New endpoint** `POST /{shop}/part-id` (+ bare `/part-id` on custom domains) — session-less like /order and /quote, **throttled 8/min per IP** (vision calls cost money), CSRF-exempt, 6 MB cap, images only.
- **Big phone photos are downscaled server-side** (GD, max 1024 px, JPEG) before the vision call — faster and cheaper; skipped gracefully if GD is absent.
- **The model can only answer from YOUR catalogue** — it receives the id|name|brand|category list and must return strict JSON with catalogue ids; anything else is discarded. Ids are re-checked against the database before anything is shown (the model cannot invent a product). Prompt explicitly prefers "no match" over a confident guess — a wrong part costs the customer a day.
- Every identification is logged to BotTrace (`sf_partid`, with what the model saw and how many matches) so you can watch quality from day one.
- **New service** `app/Services/Vision/PartIdentifier.php` — prompt-building and reply-parsing are pure functions (harness-tested); the API call is isolated and fully guarded.

## Files changed vs v4.8.1

`app/Services/Vision/PartIdentifier.php` (NEW) · `StorefrontController.php` (partId endpoint) · `routes/web.php` (2 throttled routes) · `bootstrap/app.php` (CSRF exempt `part-id`, `*/part-id`) · `resources/storefront/autoparts.html` (native upload + results sheet + fallback; src mirrors 01/10).

## Deploy

GitHub upload → Rebuild → `php artisan migrate` (if not yet run). Requires the OpenAI key that already powers your bot — nothing new to configure. Test it yourself after deploy: open eaautopart.com on your phone → 📷 Photo of old part → photograph any part from the boraboda range.

## Verified before ship

- 10/10 harness on prompt/parse (row formatting, ```json fences, JSON-in-prose, confidence clamping 0–1, 3-match cap, prose-only reply → empty).
- Playwright happy path: upload → spinner → results sheet (2 matches with confidence chips + photo description) → card tap navigates to the product and closes the sheet.
- Playwright failure path: endpoint dead → "Couldn't match it confidently" sheet with WhatsApp hand-off. Zero console errors both passes.
- Full regression journeys as Boxer and TVS riders: zero errors, zero failed requests.
- `php -l` ×4 (controller, service, routes, bootstrap) · `node --check` ×2 · zip md5 vs repo.
