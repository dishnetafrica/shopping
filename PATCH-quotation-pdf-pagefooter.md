# Patch — quotation PDF footer "Page 1 of 0" → "Page 1"

The professional quotation PDF (Q3) is working in production (thumbnails, columns, totals, stamp/
approval, terms all render). One cosmetic fix: dompdf does not resolve counter(pages) inside the
fixed footer, so it printed "Page 1 of 0". The footer now shows just "Page N".

## File
app/Services/Bot/QuotationService.php   (one CSS line in the PDF template)

## Deploy
Replace the file. No migration, no cache clear needed (it's a runtime template).

## Optional: enable the QR image
The PDF shows the live-portal LINK; to also print the QR square:
  composer require bacon/bacon-qr-code
(No code change — QuotationService already renders the QR when the library is present.)
