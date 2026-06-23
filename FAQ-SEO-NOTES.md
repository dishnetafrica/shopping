# FAQ (both places) + SEO

## FAQ
- **Brand site** (`/{shop}`) — a "How to order & FAQ" section with an accordion. Content from the
  `faq` tenant setting (array of `{q,a}`); a strong 9-question default ships if unset.
- **Shop** (`/{shop}/shop`) — a "How to order & FAQ" link in the footer opens a help sheet with the
  same accordion. Overridable per tenant via `settings.faq`; sensible default built in.
- The FAQ content was written from the real questions wholesale & retail buyers ask (order steps,
  minimums, single packs, delivery, payment, certification, distributor sign-up, institutions).

## SEO (brand site)
Server-rendered (so crawlers see it without running JS):
- `<title>` + `<meta name="description">` (auto-built from the business name + brands, or a
  `meta_description` setting), `robots`, and a **canonical** link.
- **Open Graph + Twitter** tags (title, description, url, site_name, image=logo) for good link previews
  on WhatsApp / social.
- **JSON-LD structured data**: `Organization` (name, url, logo, phone, email, PostalAddress, sameAs=website,
  brands) and `FAQPage` (every Q&A) — these are what earn Google rich results / FAQ snippets.

## Settings used (all optional, with defaults)
`faq` (array), `meta_description`, plus the existing `website`, `public_phone`, `public_email`,
`address`, `brands`. Edit via the panel settings or tinker.

## Files
- `resources/storefront/brand.html` — FAQ section + SEO placeholders.
- `resources/storefront/shop.html` — FAQ help sheet + footer link + per-tenant override.
- `app/Http/Controllers/Storefront/StorefrontController.php` — FAQ defaults + server-rendered SEO/JSON-LD.
- `qa/brandsite_seo.php` — **12/12** (FAQPage + Organization structure, meta description).

## Deploy
Pull → restart. No migration, no new route (the routes already exist from the brand-site change).
After deploy, validate the structured data with Google's Rich Results Test on `https://mycloudbss.com/ep`.
