# Quotation PDF 404 fix

## What was wrong
The PDF link pointed to `https://evo-shopbot…easypanel.host/storage/quotations/12/…pdf`. Two faults:
1. **No `public/storage` symlink** on the container, so `/storage/*` 404s (EasyPanel drops the symlink
   on redeploys).
2. The URL used `APP_URL` (the raw EasyPanel host) instead of `mycloudbss.com`.

The file itself was fine — it's on the `public` disk at `storage/app/public/quotations/12/…pdf`. It
just wasn't reachable over HTTP.

## The fix
Stream the PDF through the app instead of linking to `/storage`:
- New route `GET /papi/quotation-pdf?id={id}` → `PanelApiController::quotationPdf()`. It's
  tenant-scoped (a tenant can only open its own quotes), serves the file **inline** with
  `Content-Type: application/pdf`, and needs **no symlink**.
- If the file is missing (wiped by a redeploy), it **regenerates** from the stored line items
  (`quoteArrayFromQuotation` + `QuotationService::generate`, keeping the same quote number) and then
  streams it — so old quotes keep working.
- The list, detail, and resend responses now return a **relative** URL (`/papi/quotation-pdf?id=…`),
  so it always resolves to the panel's own host and rides the session cookie. The panel's existing PDF
  button uses `q.url`, so no front-end change was needed.

WhatsApp sends are unaffected — they already send the PDF bytes (base64), not this URL.

## Verify
1. Deploy, then `php artisan route:list | grep quotation-pdf` shows the route.
2. Open a quote → click **PDF** → it opens inline on `mycloudbss.com`, no 404.
3. (Optional) If you also want product images etc. served from `/storage`, run
   `php artisan storage:link` once — but the PDF no longer depends on it.

In-sandbox: PHP lint clean. The actual stream + regenerate need a click-through in your environment.
