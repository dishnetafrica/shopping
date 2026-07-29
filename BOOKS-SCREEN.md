# Books — a visual "Company Situation" report in the v3 menu

Adds a **Books** item under **Finance** in the v3 sidebar. Clicking it opens a live, visual
one-page report (owner-only): KPI tiles, a **P&L waterfall** (revenue → net profit), the
**balance sheet** drawn as two columns that visibly match, **cash by currency**, and the
**Bhavin loan** summary. It reads the live cashbook every time it loads and **writes nothing**.

It's wired exactly like the existing "Profit & Loss" screen — a self-contained page shown in an
iframe inside the shell — so it is fully isolated and cannot affect the rest of the app.

## Files in this package
| File | Change |
|---|---|
| `resources/panel/situation-report.html` | **NEW** — the report page (self-contained; fetches `/papi/*`) |
| `app/Http/Controllers/Panel/PanelApiController.php` | adds `situationReportPage()` (owner-gated, serves the html) |
| `routes/web.php` | adds `GET /panel/situation-report` |
| `resources/views/shell.blade.php` | adds the **Books** menu item + route (see exact edits below) |
| `app/Capability/Shared/MoneyTimingCapability.php` | Finance "Cash on hand" tile now counts all currencies |

If your production tree has diverged from this repo, **don't overwrite `shell.blade.php` /
`routes/web.php` / `PanelApiController.php` wholesale** — apply these small edits by hand instead:

**routes/web.php** — after the `/panel/pnl-report` route:
```php
Route::get('/panel/situation-report', [\App\Http\Controllers\Panel\PanelApiController::class, 'situationReportPage']);
```

**PanelApiController.php** — add a method (copy of `pnlReportPage`) that serves
`resource_path('panel/situation-report.html')` behind `$this->ownerGate($r)`.

**shell.blade.php** — five one-line additions, each mirroring the existing `pnl` entry:
1. `LABELS`: add `books:'Books',`
2. `GROUPS` Finance row: `['Finance',['finance','pnl','books','cashbook']],`
3. `READY` set: add `'books',`
4. `has = k => (k==='pnl' && keys.has('finance')) || (k==='books' && keys.has('finance')) || …`
5. in `route(key,id)`, after the `pnl` line:
   ```js
   if(key==='books'){ document.getElementById('view').innerHTML='<div class="crumb">Books</div><iframe title="Books" src="/panel/situation-report" style="width:100%;height:calc(100vh - 130px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
   ```
   (and the matching `hasScreen`/icon lines — all in this package's copy)

## Deploy
```
# copy the files, then:
php artisan view:clear && php artisan route:clear && php artisan config:clear
php artisan queue:restart
```
No migration needed.

## First-run check (built & rendered, but not run against your live DB — verify once)
1. Open v3, look under **Finance** in the left menu → **Books**. Click it.
2. Confirm it matches your audited numbers: net profit ≈ **$2,327**, cash ≈ **$4,949**,
   loan ≈ **$2,469**, and the balance sheet's two columns are equal.
3. The **Save PDF** button (top-right of the report) prints a dated copy.

## Change the stock value
The report uses a manual stock value until inventory tracks from purchases. To change it, edit
one line near the top of `resources/panel/situation-report.html`:
```js
const STOCK_USD = 3798.33;   // set to your current stock value
```

## Rollback (instant, harmless)
Remove `'books'` from the `GROUPS` Finance row in `shell.blade.php` (the menu item disappears),
or delete `resources/panel/situation-report.html`. It's read-only and isolated — nothing else is affected.

## Also in this package: Finance "Cash on hand" tile now counts all currencies
`app/Capability/Shared/MoneyTimingCapability.php` — the tile (and Morning Briefing) previously
showed the **USD wallet only**, ignoring UGX + SSP. It now totals all three in USD — matching Books
and the audit. Expect the tile to jump from ~$2,207 to ~$4,949 and Runway to recalculate. Display
fix only; no ledger data changes; the frozen `CashService` is untouched.
