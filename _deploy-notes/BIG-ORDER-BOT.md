# Bot fix — big / wholesale orders no longer get a retail auto-quote

**Problem it fixes:** when a customer asked for a large quantity (e.g. 1,400 bags of sugar), the
WhatsApp bot quoted it at the **retail price-list price** and sent a firm total (e.g. $65,800) —
which reads as "too expensive" on a wholesale order and loses the deal. It also didn't alert you.

**What changes now:** when an order is worth **USD 5,000 or more** (configurable), the bot:
1. does **not** send a firm retail total / retail PDF,
2. replies: *"That's a wholesale quantity — our team will send you a special bulk price… would you
   like it delivered, or will you collect from Kampala?"*,
3. **WhatsApps you immediately** with the lead (customer, items, ~USD value, destination), and
4. **flags the quotation** as `wholesale` / `high_value` in its record (system flag).

Normal, smaller orders behave exactly as before. The bot's instructions were also updated so it
handles big orders and product availability more carefully in its own wording.

## File in this package
- `app/Services/Bot/AiBrain.php` — the guard is added in **both** quote paths
  (`captureOrderAsQuote` for order-style messages, and `captureQuote` for explicit quote requests),
  so a big order can't slip through either route.

## Settings
| Setting | Default | Meaning |
|---|---|---|
| `bot_wholesale_threshold_usd` | `5000` | Orders at/above this USD value are treated as wholesale. Set `0` to disable. |
| `owner_alert_phone` | (your number) | Where the "Big order lead" WhatsApp goes. **Must be set** for the alert to reach you — it uses the same owner-alert numbers as your other notifications. |
| `free_delivery_towns` | `Juba, Konyo Konyo, Custom, Jebel, Munuki, Gudele, …` | Comma list of places that get **free delivery** (Juba + its markets/areas). Every other town → transport negotiated per shipment. Add any Juba area a customer types that isn't listed. |
| `delivery_from_city` | `Juba` | The store you **deliver from / customers collect from**. The bot uses this everywhere it talks about delivery or pickup — it will **never** tell a customer to collect from Kampala (Kampala is only where you source). |

To change the threshold later, set `bot_wholesale_threshold_usd` in the tenant settings (e.g. to
3000 or 10000). No code change needed.

## Deploy
```
# copy the file, then:
php artisan config:clear
php artisan queue:restart        # the owner alert is a queued WhatsApp job
```
No migration needed.

## Test it (safe) before trusting it live
Message the shop's WhatsApp from a test number asking for a large quantity of a real product
(enough to cross $5,000). You should get the **wholesale holding reply** (no retail total), and
**your owner number should receive the "Big order lead" alert**. A small order should still get the
normal quotation. Check the quotation record shows the `wholesale` flag.

## Rollback (instant)
Set `bot_wholesale_threshold_usd = 0` in settings — the guard switches off and the bot reverts to
its previous behaviour immediately, no redeploy needed.

## Delivery / transport rule (Juba-store model)
The bot delivers **from your Juba store**, and follows your model automatically on every quote and in
free conversation:
- **Juba + its markets/areas (Konyo Konyo, Custom, Jebel, Munuki, Gudele, …) → free.**
- **Any other South Sudan town (Wau, Malakal, etc.) → negotiated.** Goods go from your Juba store; the
  team confirms transport, or the customer collects from **Juba** (never Kampala). It never invents a freight price.

Fix included this round: the bot used to treat every South Sudan address — even Juba's own markets like
**Konyo Konyo** — as "priced ex-Kampala, collect from Kampala." It now recognises Juba's areas as free and
always refers to your **Juba store** for delivery/pickup. Kampala is treated purely as where you *buy*,
never mentioned to customers. Extend the free areas via `free_delivery_towns`; set your store city via
`delivery_from_city`.

## Not included (say the word and I'll add)
- A visible **"Wholesale" badge** on the Quotations list (right now the flag lives in the record's
  meta + bot trace, not as a list badge).
