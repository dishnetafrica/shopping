# Family Shoppers — Blinkit-style product page (CloudBSS)

Three drop-in files plus two small snippets. No new Composer packages.

```
app/Services/ProductRecommendations.php                      <- the engine
app/Http/Controllers/Storefront/StorefrontProductController.php  <- JSON endpoints
database/migrations/2026_06_27_000001_add_storefront_to_tenants.php
```

`entrypoint.sh` already runs `php artisan migrate --force` on boot, so the
migration applies itself on the next EasyPanel deploy.

---

## 1. Confirm the schema constants

Open `ProductRecommendations.php`. The top four constants name the order-line
table/columns used for "Top 10" and "People also bought":

```php
private const OI_TABLE   = 'order_items';
private const OI_ORDER   = 'order_id';
private const OI_PRODUCT = 'product_id';
private const OI_QTY     = 'quantity';   // <- if yours is `qty`, change this one
```

It also assumes `products.category` is a string (matches your catalogue
importer, which sets the category name on each product). If category is a FK,
swap `->where('products.category', $category)` for `category_id`.

## 2. Routes

```php
use App\Http\Controllers\Storefront\StorefrontProductController;

Route::get('/s/{product}/page', [StorefrontProductController::class, 'show']);
Route::get('/s/{product}/reco', [StorefrontProductController::class, 'recommendations']);
```

`{product}` is route-model-bound. If your storefront already resolves the tenant
from the slug/subdomain, keep that middleware on the group so the binding is
tenant-scoped.

## 3. Tenant model — add the `whyShop()` accessor

```php
// app/Models/Tenant.php
protected $casts = [
    // ...existing casts...
    'storefront' => 'array',
];

/** Editable value-props for the "Why shop from <store>?" band, with safe defaults. */
public function whyShop(): array
{
    return $this->storefront['why_shop'] ?? [
        ['icon' => 'truck',     'title' => 'Same-day delivery',  'body' => 'Order before 4 pm and we deliver to your door today.'],
        ['icon' => 'whatsapp',  'title' => 'Order on WhatsApp',  'body' => 'Send your list on WhatsApp — we pack it and confirm in minutes.'],
        ['icon' => 'tag',       'title' => 'Real shelf prices',  'body' => 'What you pay in the shop is what you pay online.'],
        ['icon' => 'card',      'title' => 'Pay your way',       'body' => 'Cash, Mobile Money or card on delivery.'],
    ];
}
```

## 4. Filament — let each tenant edit the band

Add to the `TenantResource` form (Filament v3). Uses a Repeater of up to 4 rows:

```php
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;

Repeater::make('storefront.why_shop')
    ->label('Why shop from this store?')
    ->maxItems(4)
    ->schema([
        Select::make('icon')->options([
            'truck' => 'Delivery', 'whatsapp' => 'WhatsApp', 'tag' => 'Price',
            'card' => 'Payment', 'leaf' => 'Fresh', 'clock' => 'Fast',
        ])->required(),
        TextInput::make('title')->required()->maxLength(40),
        Textarea::make('body')->rows(2)->maxLength(120),
    ])
    ->columns(1)
    ->collapsible(),
```

(Filament writes/reads `storefront->why_shop` directly because of the array cast.)

## 5. Front end

The prototype (`familyshoppers-product-page.html`) already has the layout and the
three rails. To make it live, replace the inline `R = {...}` sample data with a
fetch:

```js
const res  = await fetch(`/s/${PRODUCT_ID}/page`).then(r => r.json());
renderWhyShop(res.why_shop);
renderRail('r1', res.similar);
renderRail('r2', res.top_in_category);
renderRail('r3', res.also_bought);
// each card uses item.image_url (the photos you've been filling into the catalogue)
```

`renderRail(id, items)` is the same `card()` template already in the prototype —
just swap the glyph tile for `<img src="${item.image_url}">`.

---

## Performance notes

- Every method is `Cache::remember`-wrapped (15 min). Clear on product/order
  writes if you want it fresher: `Cache::forget("reco:also:{$id}:12")` etc.
- "Top 10" and "People also bought" are single grouped queries; for big tenants
  add an index on `order_items(order_id)` and `order_items(product_id)`.
- "People also bought" returns category best-sellers until real co-purchase data
  builds up — no empty rails on day one.
