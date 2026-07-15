# HOTFIX — "CSRF token mismatch" on Request Quotation (and portal actions)

## Cause
The storefront POSTs (order, quote, event, auth, account, portal revise/approve/share) are token /
no-session by design and must be CSRF-exempt. The exemption list in bootstrap/app.php only covered
the platform-slug form (e.g. "*/order") — not the BARE paths used on a tenant custom domain
(familyshoppers.net/quote → path "quote"), and had nothing for the new /quote and /q/{token}/* routes.
So on familyshoppers.net the Request Quotation POST was rejected by CSRF.

## Fix (one file)
bootstrap/app.php — the validateCsrfTokens(except: […]) list now exempts every storefront POST on
BOTH the custom domain (bare) and the platform slug:
  order, */order · quote, */quote · event, */event · auth/*, */auth/* · account/*, */account/* · q/*, */q/*

This also fixes the same latent gap for web orders / login / analytics events on custom domains.

## Deploy
1. Replace bootstrap/app.php.
2. php artisan optimize:clear   (or at least: config:clear && route:clear)
3. Retry "Request Quotation" — it will succeed.

Nothing else changes; this only ADDS exemptions for endpoints that are already token-authenticated.
