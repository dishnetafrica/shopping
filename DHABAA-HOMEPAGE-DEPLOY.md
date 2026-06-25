# Serve the landing page at thegreatindiandhabaa.com/

Makes the custom-domain ROOT show The Great Indian Dhabaa landing page, while the
ordering storefront stays at /tg. No database change, no migration.

## How it works
A shop can ship a bespoke homepage at `public/landing/{slug}.html`. When a request hits
the ROOT of that shop's custom domain, the app serves that file. If the file isn't there,
nothing changes (it falls back to the brand site / shop as before).

- thegreatindiandhabaa.com/        -> public/landing/tg.html  (the landing page)
- thegreatindiandhabaa.com/tg      -> ordering storefront (unchanged)
- mycloudbss.com/tg                -> ordering storefront (unchanged)
- every other shop / custom domain -> unchanged

## Files (2)
- `app/Http/Controllers/Marketing/MarketingController.php` — root handler now checks for a
  per-shop landing file before falling back to the shop. (Also keeps the earlier brand-site
  fix: manufacturers serve their brand site at the domain root.)
- `public/landing/tg.html` — The Great Indian Dhabaa landing page (real phones, Kololo
  address, email, events, WhatsApp ordering, menu link to /tg).

## Deploy
1. Unzip at the repo root (adds the landing file, updates the one controller).
2. Commit + push -> EasyPanel rebuild.
3. `php artisan optimize:clear`.
4. Open https://thegreatindiandhabaa.com/ -> landing page. Open /tg -> ordering still works.

Prerequisite (already done on your side): the domain's DNS A-record points to the server,
and the domain is set as tg's custom_domain in the panel.

## Cleanup (optional)
If you deployed the earlier `public/dhabaa.html`, you can delete it — this uses
`public/landing/tg.html` instead. Leaving it does no harm.

## Give another restaurant its own homepage later
Drop its page at `public/landing/{thatslug}.html` and point its domain — no code change.

## Rollback
Delete `public/landing/tg.html` (root falls back to the storefront), or restore the previous
MarketingController.php. Redeploy.
