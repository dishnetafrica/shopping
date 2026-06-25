# The Great Indian Dhabaa — landing page

A single static page. After deploy it is live at:
  https://mycloudbss.com/dhabaa.html
  (and on any custom domain pointed at the app, e.g. https://thegreatindiandhabaa.com/dhabaa.html)

## File
- `public/dhabaa.html`  — the landing page (self-contained: HTML + CSS + JS in one file).

## Real details wired in (from the printed menu)
- Phone / WhatsApp: +256 751 903 647 (primary) and +256 765 946 392
- Address: Plot 3, Wampewo Avenue, Kololo, Kampala (with a Google Maps link)
- Email: jatiumrakitchens@gmail.com
- Events section: Indian fusion menu, special lunches, birthday parties, weddings &
  wedding lunches, bridal & baby showers, kids' play area, private cooking sessions.
- A LocalBusiness/Restaurant schema is embedded for Google.

## Buttons
- "Order on WhatsApp" → wa.me/256751903647 (pre-filled message). Each dish's "Order +"
  pre-fills that dish name.
- "View full menu & order" → your storefront https://mycloudbss.com/tg
- "Call to book" → tel: links to the two numbers.
To change the WhatsApp number or menu link later, edit the two constants near the bottom
of the file: `WA_NUMBER` and `MENU_URL`.

## Deploy
1. Unzip at the repo root — this adds `public/dhabaa.html`.
2. Commit + push → EasyPanel rebuild.
3. Open https://mycloudbss.com/dhabaa.html to confirm.
(No database, migration, routing, or other code touched — it is just a static file in the
web root, so nothing else can break.)

## Optional next step — make it the homepage
If you want this to show at the root of thegreatindiandhabaa.com (instead of /dhabaa.html),
that needs a small routing change so the restaurant serves this page at its domain root
while keeping /tg ordering intact — same idea as the EuroPearl brand-site wiring. Ask and
I'll send that patch separately.
