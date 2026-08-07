# v4.56 — Cross-sell gaps

**No migration.** Unzip, `php artisan optimize:clear`. Completes Milestone 8 alongside v4.55.

> Apply v4.55 first — this shares the `Growth` service namespace with it.

---

## What it does

Every customer record grows a **Not buying yet** card: products that customers like them buy and
they do not, with the evidence in the row and a price. The top one also becomes the next-best-action
when there is nothing more urgent to say about that customer.

```
Cling Film      7 of the 9 customers who buy Aluminium Foil also buy Cling Film.   USD 18.00
Kitchen Towel   5 of the 9 customers who buy Aluminium Foil also buy Kitchen Towel. USD 22.00
```

---

## The one decision the whole feature rests on

**Confidence alone picks exactly the wrong product.**

Take a customer who buys foil. Eight of the nine foil buyers also buy rice — 89% — and seven of the
nine buy cling film — 78%. Rank by confidence and you tell every rep to sell rice.

But 85 of your 100 customers buy rice anyway. Buying foil says nothing about it. Only 12 of 100 buy
cling film, so among foil buyers it is six times likelier than baseline. **Cling film is the
observation; rice is a popularity chart.**

So a gap must clear four bars, and the fourth does the real work:

| | |
|---|---|
| **Support** | at least 3 customers actually do both — two is a coincidence |
| **Anchor** | at least 4 people buy the anchor, or the fraction means nothing |
| **Confidence** | at least half the anchor's buyers take the candidate |
| **Lift** | …and at least 1.25× more than the shop's customers take it in general |

Without lift, the top suggestion for every customer in the shop is whatever sells most, and the
advice is "sell them your best seller" — which you already knew and did not need a screen for.

---

## Three smaller decisions

**Counted per customer, not per order.** A supermarket that buys foil in January and cling film in
February buys both. `ProductRecommendations::alsoBought` counts baskets and is right for the
storefront; a wholesale relationship is a different question and gets its own answer.

**The row shows counts, not a percentage.** "7 of the 9 customers who buy Aluminium Foil" can be
checked against what a rep already knows and argued with. "78% confidence" invites belief without
understanding, which is worse than no number.

**Nothing unsellable is ever suggested.** In stock, priced, not archived, and — reusing v4.54 —
never hidden. Suggesting a hidden line would walk straight around the switch you turned on tonight.

---

## Files

| File | |
|---|---|
| `app/Services/Growth/CrossSell.php` | **new** — the judgement. Pure: no models, no database |
| `app/Services/Growth/CrossSellService.php` | **new** — counts it from the order book, cached per tenant |
| `app/Capability/Object/CustomerCapability.php` | the card, and the next-best-action |

---

## Checks that ran

```
t456/crosssell.php   27 passed, 0 failed
t456/mutants.sh      16 killed, 0 survived
t456/contracts.sh    20 passed, 0 failed
t451–t455            all still green
```

The first mutant removes the lift gate. Nothing crashes — the screen just quietly starts telling
every rep to sell rice, and it would take months to notice. That is the shape of every failure in
this feature, which is why the mutants matter more than the tests.

---

## What is NOT proven

It has not run against your order book. With 112 orders across 676 customers, **most customers will
show no gaps at all, and that is the correct answer** — three customers doing both is a real bar and
a young order history will not clear it often. If the card is empty everywhere, that is the
thresholds working, not the feature failing.

Where it does fire, argue with it. A pairing you know is nonsense means the threshold is too loose
for your trade; tell me which pair and I will raise it.
