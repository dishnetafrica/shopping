# CloudBSS v4.29 — the catalogue researches itself

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.29.zip (cumulative — supersedes v4.28; 160 files) · **Migration: YES — `php artisan migrate`** · **Delta: 16 files.**

## First, the honest part about Google

You asked whether this makes you number one. Nobody can promise that, and the straightforward version of the idea would have made things worse, so it is worth being precise about what this does and does not do.

Google's spam policy names the failure mode directly: *"using generative AI tools to generate many pages without adding value for users"* and *"scraping feeds… to generate many pages… where little value is provided."* And copying the manufacturer's description has a quieter problem — no penalty, but Google indexes only one copy of identical text and the rest simply never surface. Copied descriptions are pages nobody finds.

Structured data is not a ranking signal either. It decides whether you are *eligible* for rich results and it lifts click-through; ranking still comes from relevance, authority, page speed and unique content.

So this drop does the version that works: **facts from authoritative sources, prose written fresh.** A barcode gives brand, size and origin as recorded fact. The description is then composed from those facts plus the things only you can say — the case size, the minimum order, that you are in Juba. That is content no other shop has. You will not outrank Alibaba for "soy sauce"; you can own "soy sauce Juba" and "wholesale groceries South Sudan", and that is where your buyers actually are.

## How it works

**One product, on demand.** A new **🔎 Look this product up** button on the Add and Edit forms. Scan the barcode, press it, and brand, size, country of origin, category, description and even the product photo fill themselves in.

**It only ever fills empty boxes.** Anything you typed is left exactly as it is and reported back to you as *"Kept yours: brand, category"*. This is enforced in the browser and again on the server, and once more when you approve a queued suggestion — because a suggestion raised on Monday must not overwrite something you typed on Tuesday.

**Where the fact came from is always visible.** A field filled from a barcode is fact. A field the AI inferred from the name is marked with an asterisk and *"guessed from the name — check it"*.

**What it is unsure about waits for you.** A new **💡 Suggestions** button appears on the Products screen when there is something in the queue — and only then. Each entry shows the product, the field, the proposed value, where it came from and how sure it is. Use it or dismiss it.

**Country of origin is deliberately never auto-filled from a guess.** It reaches customs forms and tender submissions, so an inferred origin is capped below the auto-apply line by design and always goes to the queue. A barcode-verified origin is applied; a guessed one is not.

**The whole catalogue at once:**

```
php artisan products:research --tenant=17                        # see what it would do
php artisan products:research --tenant=17 --apply                # AI only, no web calls
php artisan products:research --tenant=17 --barcode-api --apply  # also look barcodes up
php artisan products:research --tenant=17 --descriptions --apply # and write descriptions
```

**Dry run by default** — like `products:enrich`, nothing is written without `--apply`. Run it once, read the table, then run it again.

## Things I deliberately constrained

**Open Food Facts asks that one API call equals one real scan by a user.** So the interactive button calls them once per scan, exactly as intended, and the bulk command only touches them behind `--barcode-api`, throttled to about one per second and capped at 250 per run. Their data is ODbL, so the attribution line is shown wherever their facts appear. For a much larger backfill, their published data export is the correct route, not the API.

**There is no AI spend metering anywhere in this application** — I checked, and nothing has ever counted a token. Until that exists, this drop adds a blunt guard: a per-shop daily ceiling on research calls (default 400, in Settings). Reaching it pauses research until tomorrow and affects nothing else.

**The model is told that silence is the right answer.** It must return a confidence per field and is told plainly there is no penalty for omitting one. It gets a closed list of countries, so "made in Europe" cannot come back as an origin. A product name that is a bare code — `30056640` — is refused before any call is made, because a model will confidently classify a serial number.

**Research can never write price, cost, stock or the barcode.** Price is commercial, stock is physical, and the barcode is the key the lookup is done on — overwriting it would corrupt the identity of the row.

## After you deploy

1. **`php artisan migrate`** — new table `product_suggestions`. (v4.28's columns and v4.22's `tender_items` too if those are still outstanding.)
2. **Settings → Storefront → Research my products** → turn *Research products for me* ON.
3. Products → **+ Add product** → scan a barcode → **🔎 Look this product up**.
4. Try it on something with an unusual name too, so you see the asterisk marking on a guessed field.
5. Then the catalogue pass, dry run first: `php artisan products:research --tenant=17`. Read the table. If it looks right, add `--apply`.
6. Check **💡 Suggestions** on the Products screen afterwards.

If you leave the setting off, nothing changes anywhere and nothing is ever sent to the AI.

## Verified before ship

**97 checks against the real classes**, loaded and executed — the providers, the validator, the planner and the copywriter, not copies of them.

**The harness found a live bug before you did.** `compactSize()` stripped trailing zeros unconditionally, so every `500 ml` bottle on Open Food Facts would have been recorded as **5ml**, and every `100 g` pack as `1g`. Silent, plausible, and wrong on a large share of the grocery catalogue. Fixed: trailing zeros are only noise after a decimal point.

Covered: a 0.99-confidence claim does **not** overwrite a field you typed (checked on brand and category simultaneously); the apply/review/skip thresholds at their exact boundaries; a failed validation caps confidence below the review floor rather than passing through, tested on an invented country, a non-size, a one-character brand and a stub description; the country vocabulary with aliases (`UK`, `Dubai`, `Türkiye`) and its refusals (`Wakanda`, `Europe`); size normalisation across eleven real Open Food Facts spellings; a full soy-sauce payload mapping correctly including the CJK brand alias being dropped; `origins` beating `countries_tags` so *sold in* is never mistaken for *made in*, and a product sold in several countries producing no origin claim at all; an off-host image URL being refused; barcode-vs-shelf-code shapes; the AI provider's ceilings, including proof that an inferred origin can never reach auto-apply; a bare numeric name being refused before any API call; authoritative sources beating a *more confident* guess while the guesser still contributes what the barcode did not know, and being asked only for the fields still open; a provider that throws not taking the pipeline down; the copywriter's length gate and its two confidence ceilings; and that the prompts actually contain the instructions this all depends on — do not copy the manufacturer, do not invent facts, omit when unsure — because a prompt rule that gets edited away is invisible otherwise.

`php -l` on 16 PHP files · panel script blocks parsed by node · v4.28's 89 checks and v4.27.1's 36 re-run, all still green.
