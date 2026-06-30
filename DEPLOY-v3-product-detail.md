# Storefront Product Detail — v3 (deploy via repo rebuild)

WHY a rebuild is required: code is baked into the Docker image (Dockerfile COPYs
app/ routes/ resources/ etc. from the repo). opcache.validate_timestamps=0 means
the running container never re-reads files. So editing files inside a running
container is temporary (wiped on restart) and not picked up by the web process.
The ONLY way to update the live site is: commit these files to the repo, then
REBUILD the image in EasyPanel (not Restart).

## Files in this zip (unzip into repo ROOT, preserving paths)
- app/Http/Controllers/Storefront/StorefrontProductController.php
- app/Services/ProductRecommendations.php
- app/Models/Tenant.php
- routes/web.php
- resources/storefront/shop.html

## What changed vs the v2 currently live
1. show($product) takes the route param untyped and casts to int
   (fixes the TypeError: "Argument #1 ($product) must be of type int").
2. resolve() guard changed to:  abort_if(! $product->active, 404);
   (was: archived_at !== null || !active). Archived-but-active products now open,
   matching the catalogue which lists on active=true only. THIS is what was 404ing
   products like 61225 / 61253.
3. ?debug=1 returns JSON {_error: ...} instead of an HTML 404, so failures are
   diagnosable from the browser.
4. ProductRecommendations::topInCategory rewritten with leftJoinSub (no outer
   GROUP BY) — valid on PostgreSQL (the old SELECT products.* ... GROUP BY would
   have 500'd as soon as a reco rail ran).
5. Response carries header X-Cb-Detail: v3 so you can confirm the new code is live.

## Deploy
1. Unzip into the repo root (paths preserved). Commit + push.
2. EasyPanel -> this app -> Rebuild / Deploy (build image from repo). NOT Restart.
3. Verify in browser DevTools console:
   (async()=>{for(const id of[61225,61253]){const r=await fetch(`/shree/product/${id}?debug=1`,{cache:'no-store'});const t=await r.text();let b;try{b=JSON.parse(t)}catch{b=t.slice(0,120)}console.log(`#${id} -> ${r.status} X-Cb-Detail=${r.headers.get('X-Cb-Detail')}`,b)}})();
   Expect: #61225 -> 200 X-Cb-Detail=v3 {product:{...}}
