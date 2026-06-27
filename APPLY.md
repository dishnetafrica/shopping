# Apply — product-page fix (multi-tenant + qty)

Two corrected files (overwrite the ones already in the repo):

```
app/Http/Controllers/Storefront/StorefrontProductController.php
app/Services/ProductRecommendations.php
```

What changed vs the first version (both proven by the dry run):
1. **Global tenant scope** — `Product` uses `BelongsToTenant`; with no tenant context it hides
   ALL rows (`with scope: 0 | without scope: 11001`). So the controller no longer route-model-
   binds; it takes `{product}` as an int and resolves `withoutGlobalScopes()`, and the service
   adds `withoutGlobalScopes()` to every product query. Tenant safety kept: each query is pinned
   to `$product->tenant_id`.
2. **`order_items.qty`** — confirmed column is `qty`, not `quantity`. `OI_QTY` updated.

## Add the routes (NOT included in this zip — edit your own routes/web.php)

Top of `routes/web.php`, with the other imports:
```php
use App\Http\Controllers\Storefront\StorefrontProductController;
```
Anywhere not inside a tenant group/closure (this install has no `/{slug}` catch-all, so order
doesn't matter):
```php
Route::get('/s/{product}/page', [StorefrontProductController::class, 'show']);
Route::get('/s/{product}/reco', [StorefrontProductController::class, 'recommendations']);
```

## Deploy + verify
Commit, then **redeploy** (routes only register on a fresh build via `route:cache`). After it's green,
in the container:
```bash
php artisan route:list | grep '/s/'                 # expect both lines
ID=$(php artisan tinker --execute="echo App\Models\Product::withoutGlobalScopes()->where('active',1)->whereNull('archived_at')->value('id');")
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1/s/$ID/page   # expect 200
```
200 = backend signed off. 500 = paste the error. 404 = routes didn't register (recheck web.php).
