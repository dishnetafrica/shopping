# CloudBSS v4.24 — a customer's photo now checks your real product list

**Date:** 2026-08-03 · **Zip:** GITHUB-UPLOAD-cloudbss-v4.24.zip (cumulative — supersedes v4.23; 137 files) · **Migration:** none new · **Delta: 4 files.**

## Short answer: yes — and half of it was already there

CloudBSS has matched customer photos against the catalogue since v4.4, but **only on the rules bot**. Family Shoppers and East Africa run the **AI bot** (`bot_mode = ai`), and on that path the photo went straight to the vision model as a picture in the chat. The AI could describe it beautifully — and quote a price for something you do not stock, because nothing checked the photo against your product list first.

This drop moves that check in front of the AI on both bots.

## What happens now when a customer sends a photo

1. **Have we seen this exact photo before?** If previous customers picked the same product for it, that product is used immediately — no AI call, no cost, instant.
2. Otherwise the photo (plus any caption) goes to vision, which returns *what it thinks the item is* and how confident it is. Below your confidence floor (default 50%) it is treated as unreadable.
3. **The phrase is searched against YOUR live catalogue.** Only real products survive — with your prices and your stock levels.
4. The AI is then handed those products as facts and told, in the prompt: quote only from this list, at exactly these prices, name the best match first and ask the customer to confirm.

Three honest outcomes, never a guess:

- **We sell it** → up to 5 matching products with prices and *in stock / low stock / to order*, best match first.
- **We don't** → "we don't currently stock that — let me check with our supplier and come back to you", and an invitation to give the exact name in case you list it differently. It is explicitly forbidden from inventing a substitute.
- **Can't read it** → a short apology and a request to type the name or send a clearer photo of the label.

**The shop teaches itself.** Every time a customer confirms which product a photo was, that vote is recorded. Once a photo has enough agreement, it resolves instantly and for free forever after.

## Nothing new to configure

It uses the switches you already have under the bot settings: **image search** (on by default) and the **minimum confidence** (50%). Turning image search off restores the old behaviour exactly.

## Honest limits

If a customer sends several uncaptioned photos in one burst, only the first is identified — the existing anti-spam rule answers once per burst and stays quiet, which is deliberate. A photo of a shelf or a whole basket resolves to one item at best; single-product photos work far better. And an identification is a strong lead, not a promise — that is why the bot asks the customer to confirm the product before it goes in the cart.

## Verified before ship

33-check harness with a fake vision model and a five-product catalogue: a "cooking oil jerrycan" photo returns the three real oil products, best match first, **with the catalogue's price rather than the model's**, candidate ids recorded for learning, traced as `photo_matched`; an item we do not sell returns `no_match` with zero products and a prompt that forbids substitutes; an unreadable photo and a 31%-confidence photo both fall back to "type the name", with the numbers named in the reason; the tenant can lower the floor; feature-off and no-API-key paths do no work, make no AI call and explain themselves; a photo with strong previous consensus resolves **with no vision call at all** and re-reads today's price, while a split vote correctly falls back to vision. The learning row was checked column-by-column against the 2026-06-18 migration — `vision_query` is NOT NULL and there is no `updated_at`, either of which would have silently thrown and left the shop unable to learn. `php -l` ×3 · zip md5-verified (137 files).
