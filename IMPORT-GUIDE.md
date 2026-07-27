# EA Auto Part — Parts catalogue (import via Product, NOT code)

This package is **data only**. Importing it puts the full Boxer 100 catalogue
(543 genuine parts + their exploded diagrams) into the system as products — so it
does **not** add anything to your codebase or grow the repo.

## What's inside
- `parts-import.csv`  — 543 products, one per part number, categorised by system,
  priced (starter estimates), each pointing to its exploded-diagram image.
- `images/catalogue/boxer100/` — the 45 exploded-diagram PNGs.

## Step 1 — put the images where the app can serve them
The CSV's Image column points at `/storage/catalogue/boxer100/...`. Upload the
folder to the app's public storage so those URLs resolve:

    # copy into the app (on the server or via your file manager):
    storage/app/public/catalogue/boxer100/   <-- put the 45 PNGs here
    # if you have never linked storage before, run once:
    php artisan storage:link

(Alternative: host the images on any CDN/bucket and find-and-replace
`/storage/catalogue/boxer100` in parts-import.csv with your CDN base URL. No code
change either way.)

## Step 2 — import the CSV as EA Auto Part
Log in as the EA Auto Part business (or switch tenant to it), then:

    Catalogue → Import CSV → choose parts-import.csv → mode: Merge (or Replace)

The importer maps the columns automatically (Name, SKU, Category, Price, Image,
Keywords…). Re-importing never wipes photos/stock you've added, so you can run it
again for future batches.

## Step 3 — categories = your storefront "Browse by system" tiles
Each product's Category is its system (Engine, Transmission, Fuel System,
Electrical, Brakes, Suspension, Frame & Body, Controls, Wheels & Tyres,
Accessories). The storefront tiles filter the shop by these.

To make the tiles line up exactly, set this tenant setting (Operator → business
settings, or tinker) so the storefront links use the same category names:

    epc_systems = [
      {"id":"Engine","name":"Engine"},{"id":"Transmission","name":"Transmission"},
      {"id":"Fuel System","name":"Fuel System"},{"id":"Electrical","name":"Electrical"},
      {"id":"Brakes","name":"Brakes"},{"id":"Suspension","name":"Suspension"},
      {"id":"Frame & Body","name":"Frame & Body"},{"id":"Controls","name":"Controls"},
      {"id":"Wheels & Tyres","name":"Wheels & Tyres"},{"id":"Accessories","name":"Accessories"}
    ]

## Step 4 — set your real prices
The Price column holds starter estimates. Before selling, fill Base Price (your
cost) and set Price (cost × your markup), then re-import. Ask me to compute retail
across all 543 rows from a markup % if you want.

## Why this keeps the code small
- The 543 parts live in the database (imported), not in code.
- The 45 images live in storage / a CDN, not in the repo.
- The only code change is the storefront patch (Vertical.php + StorefrontController
  + autoparts.html) — a few KB. Everything heavy comes in as product data.
