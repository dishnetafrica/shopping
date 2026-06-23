# AI bot now sends product photos (like the inbuilt bot / DishNet)

## Answer to "does it send an image if I ask toilet paper?"
**Now: yes.** When a customer's message is a clear product query ("toilet paper", "napkins",
"A4 paper", "show packaging", a category name), the AI bot sends the product photo(s) with a price
caption — in addition to its text answer. It reuses the same `ProductImageResponder` the inbuilt
cart bot uses, so behaviour matches.

## What it does / doesn't
- **Sends photos for:** confident product matches, category browse ("show napkins"), and "more photos
  / show packaging" (sends that product's gallery). Up to 3 images.
- **Sends nothing for:** greetings, yes/no, "total", "checkout", "confirm", payment — the responder
  self-excludes those, so a greeting or a total request never gets random photos.
- **Needs:** the product to have an image in the catalogue (`image_url` / gallery). No image on the
  product = no photo (text answer still goes out).
- **Toggle:** the existing **"Send product photos"** setting (`send_product_images`, default ON).
  Turn off to send text only.
- Skipped on a quotation turn (you already get the PDF) and on incoming-image turns.

## File
- `app/Services/Bot/AiBrain.php` — step 4b sends product images after the text reply.

## Deploy
Part of the Krishna production bundle — pull → restart → `optimize:clear`. No migration.
Make sure your products have photos uploaded (seller panel) or the bot has nothing to send.

## Test
- "toilet paper" → text answer + EuroPearl toilet-paper photo(s).
- "show me napkins" → Angel Soft photo(s).
- "more photos" → that product's gallery.
- "hi" / "what's my total" → no photos (correct).
