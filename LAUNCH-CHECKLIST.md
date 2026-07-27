# Starting the parts business — what's left ("the other thing")

The **storefront is built**. To actually sell, you need the catalogue loaded,
priced, stocked, and paid/delivered. Here's the plan, in order.

---

## 1. Finish the parts data (phased — "cut the work in half")

Extraction is done in batches so every part number is verified, not guessed.

| Batch | Scope | Parts | Status |
|---|---|---|---|
| **1** | Boxer 100 — Engine + Transmission (plates 1–11) | 126 | ✅ done — in `parts-import.csv` + diagram page |
| 2 | Boxer 100 — Fuel, Electrical, Starting (plates 12–21) | ~90 | ⏳ next |
| 3 | Boxer 100 — Frame, Brakes, Suspension, Wheels, Controls, Accessories (plates 22–45) | ~180 | ⏳ |
| 4 | Boxer BM125X — full catalogue | ~400 | ⏳ |
| 5 | Boxer BM150X — full catalogue | ~400 | ⏳ |

Each batch appends to `parts-import.csv` (and the diagram data) the same way. Say
"do batch 2" and I extract the next set.

## 2. Set real prices (catalogues have NO prices)

`parts-import.csv` ships with **starter retail estimates** so nothing is empty —
**replace them before go-live.** Two columns drive it:

- **Base Price** = your cost (what you pay the distributor)
- **Price** = your retail (cost × markup). Fast-moving service parts (clutch,
  filters, plugs, cables, shoes) typically carry 30–60% markup; slow/expensive
  assemblies less. Decide your markup, then I can compute `Price` from `Base
  Price` across the whole sheet in one pass.

## 3. Stock the fast movers first

Don't stock all 1,100+ SKUs on day one. Start with what sells weekly: clutch
plates, air filters, spark plugs, brake shoes, cables, chains/sprockets, bulbs,
gaskets, oil seals. Set the **Stock** column for those; leave the rest at 0
("order on request"). The AI-diagnosis and popular-parts sections already
surface these.

## 4. Sourcing

Line up your genuine-parts supply (authorised Bajaj distributor / importer)
before advertising stock you can't fulfil. Match their price list to the **Base
Price** column.

## 5. Load it into CloudBSS (your tenant)

```
Master admin → Create business → Business type = "Auto Parts / EPC catalogue"
→ set brand colour, country/currency, WhatsApp number
→ Catalogue → Import CSV → upload parts-import.csv   (ProductImporter, mode: replace)
```

The importer maps our columns automatically (Name, SKU, Category, Price, Base
Price, Stock, Image, Keywords). Re-importing never wipes photos/stock you've
added — safe to run each batch.

## 6. Photos (optional, powerful)

Every image is an upload slot. Upload real photos from the seller panel and they
replace the demo images across all pages — exactly like Family Shoppers
(`image_url`). Priority: the model heroes + the fast-moving parts.

## 7. Payments & delivery

- **Payments:** mobile money (MTN / Airtel) + cash on delivery — via the existing
  CloudBSS checkout / WhatsApp bot. One cart, one checkout.
- **Delivery:** the `auto_parts` vertical enables **riders + POS**, so dispatch
  and counter sales work out of the box. Set your delivery zones/fees.

## 8. Go-live checklist

- [ ] Batches 2–5 extracted, catalogue complete
- [ ] Base Price (cost) filled from supplier; Price = cost × markup
- [ ] Stock set for fast movers
- [ ] CSV imported into the tenant
- [ ] Brand colour, logo, WhatsApp number, delivery zones set
- [ ] Real photos uploaded for heroes + top parts
- [ ] Test order end-to-end (add → checkout → mobile money → dispatch)

---

**Next step I can do now:** extract **Batch 2** (Boxer 100 fuel/electrical/starting),
or compute retail prices from a markup % you give me across the current sheet.
