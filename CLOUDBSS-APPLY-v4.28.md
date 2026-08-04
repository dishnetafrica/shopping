# CloudBSS v4.28 — the product form finally holds what a wholesaler knows

**Date:** 2026-08-04 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.28.zip (cumulative — supersedes v4.27.1; 150 files) · **Migration: YES — `php artisan migrate`** · **Delta: 13 files.**

## The problem, in your words

> "if i add product i dont see option to add other details"

You were right, and the reason is worth knowing: **most of those fields already existed in the database.** `brand`, `sku`, `barcode`, `description`, selling unit, units per box and minimum order have all been real columns for months. The Add form simply never asked for them.

Worse — the Add form was already *sending* the brand you typed, and the server was throwing it away. Every product added through the panel since that form shipped has lost its brand.

Two things genuinely did not exist: the **size of one piece** ("500ml") and **country of origin**. Those are new columns in this drop.

## What you get

**The Add form now asks for everything.** Name, brand, variant, category, size, selling unit, pieces per unit, minimum order, price, cost, stock, country of origin, SKU, barcode, description, keywords, photo. The Edit form gains brand, size, origin, SKU, barcode and description, which it never had either.

**The name writes itself.** You type the plain descriptor — "Superior Dark Soy Sauce" — and fill in brand, size and pack. Under the name field you see, live:

> Saves as **Pearl River Bridge Superior Dark Soy Sauce 500ml (24 Bottles/Box)**

That is your convention, applied by the system rather than remembered by whoever happens to be adding the product. The word "Bottles" comes from the size — ml and litres give bottles, grams and kilos give packs, anything else gives pcs. Click **edit** next to the preview to type any name you like; the automatic build stops the moment you do.

Nothing is ever doubled: if you have already typed the brand or the size into the descriptor, it is detected and not added again.

**On existing products, it offers rather than acts.** Open any product and if its name does not match the convention you get a *House style: … · apply* line. Click apply or ignore it. It strips the old pack bracket before rebuilding, so applying twice cannot produce "(24 Bottles/Box) (24 Bottles/Box)".

**Barcode: scan it from the bottle.** The camera scanner you already use in POS now works on the product form. The one change needed was letting it report a code it has never seen before — which is the whole point when the product does not exist yet.

**The AI fills more in.** "Identify with AI" already wrote a description and then threw it away in a hint. It now goes into the description field, and the name preview updates from what the AI read.

## What the customer sees

The product page gains a **brand chip**, a **Product information** table — brand, size, packing, sold by, country of origin, item code — and a pack line that says what a piece actually is: **Box · 24 × 500ml** instead of the ambiguous "Box · 24 pcs".

Rows only appear when the product carries that fact. A product with none of it looks exactly as it does today. Size and country of origin also go into the Google structured data on the product page.

## What the bot gets

The catalogue the AI is grounded on now carries **brand** and **country of origin**. "Do you have Pearl River Bridge?" and "is this Chinese or Indian?" previously had nothing to answer from, so the bot either guessed or said no. The brand is only added when it is not already in the product name, so the grounding block does not get longer for no reason.

## A bug you have not noticed yet

Export products to CSV, edit in Excel, import back — the standard way to fix a lot of products at once. The **importer reads Brand, Description and Barcode. The exporter never wrote them.** So that round-trip silently wiped all three on every product, every time.

The export now carries Brand, Size, Origin, Barcode and Description. New columns are appended at the end, so any spreadsheet template you already have keeps working — the importer matches on column name, not position.

Supplier sheets in other wording work too: *Make*, *Net Weight*, *Made In*, *PCS PER CARTON*, *Selling Unit*, *Min Order Qty*, *EAN* all map correctly.

## After you deploy

1. **`php artisan migrate`** — this drop needs it. (If you have not run it since v4.22, that one is still pending too and the Items-to-price board needs it.)
2. Products → **+ Add product** — the form should now be the long one, with the "Saves as" line under the name.
3. Add the soy sauce as a test: brand *Pearl River Bridge*, name *Superior Dark Soy Sauce*, size *500ml*, unit *box*, pcs/unit *24*, origin *China*. Check the name assembles as you expect before you save.
4. Open it on the storefront — brand chip, "Box · 24 × 500ml", and the Product information table.
5. Existing products: open one and look for the *House style* line.

Everything is optional. A product with no brand, size or pack behaves exactly as it does today, on the panel, the storefront and the bot.

## Verified before ship

**89 checks across three harnesses, every one running the shipped code extracted verbatim** — the naming functions pulled out of `seller.html`, `renderDetail()` pulled out of `shop.html`, and the real `ProductImporter` loaded and called by reflection.

Each harness was first run against the **old** code and failed where it should — the CSV one reported all five fields as "WOULD BE SILENTLY DROPPED" and the header/value counts disagreeing 11 vs 16. A test that has never failed proves nothing.

Naming (29): the exact soy-sauce string assembles; piece noun follows the size unit including "1.5 Ltr" and unknown units; brand or size already typed in the descriptor is not repeated; pack of 1 does not produce "1 Bottles/"; pack with no unit falls back to Box; whitespace never reaches the saved name; rebuilding a saved name is idempotent and changing the pack count rewrites the bracket rather than adding one; a bracket that is not a pack spec is left alone; a unit label containing regex characters ("1/2 inch") cannot break the strip; variant lands inside the name, never after the bracket.

Storefront (21): every new row renders for a full wholesale product and **none** of them render for a plain retail one — no empty section, no "Origin: —"; a pre-v4.28 product still reads "Carton · 100 pcs" exactly as before; brand, size and origin are HTML-escaped so a product name cannot inject markup.

CSV (39): all 16 export headers resolve through the real importer, header count equals value count, no alias is claimed by two fields, and "Size" cannot steal the "Pack Size" column.

`php -l` on all 8 PHP files · both HTML files' script blocks parsed by node · the v4.27.1 SEO harness re-run and still 36/36 green.

New columns are `Schema::hasColumn`-guarded in all four write paths, so if the migration is somehow not run the rest of a product still saves and a CSV import does not hard-fail.
