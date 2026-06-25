# Landing page at thegreatindiandhabaa.com/  (v2 — reliable path)

Your earlier deploy worked EXCEPT the landing HTML file never reached the server
(controller = OK, domain = OK, file = missing). This version puts the landing page in
`resources/storefront/` — the SAME folder the app already reads shop.html from at runtime —
so it is always part of the deploy.

## Files (2)
- app/Http/Controllers/Marketing/MarketingController.php  — now reads the landing page from
  resource_path('storefront/landing-{slug}.html').
- resources/storefront/landing-tg.html                   — The Great Indian Dhabaa landing page.

## Deploy
1. Unzip at the repo ROOT. Confirm BOTH files exist before committing:
     git status        # you must see resources/storefront/landing-tg.html as a new file
     git add -A
     git commit -m "dhabaa landing page at domain root"
     git push
2. In EasyPanel, REBUILD the shopping service (don't just restart — rebuild refreshes PHP opcache).
3. Verify in the service console:
     ls -la resources/storefront/landing-tg.html        # must exist
     curl -s -H "Host: thegreatindiandhabaa.com" http://localhost/ | grep -o -m1 "Wampewo Avenue"
   If it prints "Wampewo Avenue" -> the landing page is live.
4. Open https://thegreatindiandhabaa.com/ in incognito.

## Why the file went missing last time
Only app/ got committed; resources/storefront/ is guaranteed-tracked (shop.html lives there
and clearly deploys), so this avoids the issue. The empty .gitignore was not the cause.

## Cleanup
The old public/landing/ approach is no longer used. If public/landing/tg.html exists you can
delete it; it does no harm.

## Rollback
Remove resources/storefront/landing-tg.html (root falls back to the storefront), or restore the
previous MarketingController.php. Rebuild.
