# Product Candidates → one-tap approve into catalogue (v35)

**What this adds.** The Business Brain now surfaces the discovery report's `unverified_products`
— frequent words customers type in chat that match **no** catalogue product — as **Product
Candidates** the owner can approve (one tap) into the catalogue or dismiss. Approving creates a
**draft** product the owner finishes by setting a price.

## Files changed / added

**New**
- `database/migrations/2026_06_22_000001_create_discovery_product_candidates_table.php`
- `app/Models/DiscoveryProductCandidate.php`
- `app/Services/Bot/Discovery/CandidateFilter.php` (pure gating logic)
- `app/Services/Bot/Discovery/ProductCandidateService.php` (list / approve / dismiss)
- `qa/product_candidates.php` (17 asserts, framework-free)

**Edited**
- `app/Http/Controllers/Panel/PanelApiController.php`
  - `brainData` now returns a `candidates` block (count + first 8).
  - new: `brainCandidates`, `brainCandidateApprove`, `brainCandidateDismiss` (all try/catch guarded).
- `routes/web.php` — papi group: `brain-candidates`, `brain-candidate-approve`, `brain-candidate-dismiss` (GET).
- `resources/panel/seller.html`
  - EP map: `brainCandidates`, `brainCandApprove`, `brainCandDismiss`.
  - `brainCandidatesCardHtml()` + `brainCandidate()` approve/dismiss handler (uses existing `toast()`).
  - Overview: new "Product Candidates" tile + Quick Action + card (shown only when any).
  - Activity & Reviews tab: candidates card at the top.

## Behaviour / design decisions

- **Approve = DRAFT product, not live.** Created `active=false, price=0`. A catalogue product needs a
  real price before the bot quotes it, so we never fabricate one — the owner opens Products and sets
  the price to make it live. The draft already enters the discovery whitelist on next scan via its
  keywords, so the term won't re-appear as a candidate.
- **Idempotent.** A `discovery_product_candidates` row records each approve/dismiss (normalised key).
  Re-approving is a no-op; an approved/dismissed term never re-surfaces. If a product with the same
  (normalised) name already exists, approve links it instead of duplicating.
- **De-dupe is normalised**, byte-compatible with `ProductMiner::keyNorm` (lowercase, non-alnum→space,
  collapse). "Star-Link" ≡ "star link".

## ⚠ Important scope caveat (read before testing on DishNet)

`unverified_products` is **only produced in catalogue mode** — i.e. for tenants that already HAVE a
product catalogue. The no-catalogue path (`ProductMiner::legacy`) deliberately emits **none** (that's
the v31 fix that killed the "Https/Tinyurl/More" chat-token garbage).

So candidates will populate for **Family Shoppers, Pal's, Galaxy Pack, Spicey Herbs** (have
catalogues) but **DishNet shows zero** until it has a catalogue — even though "Starlink" is the
motivating example. This is by design; I did **not** reintroduce the no-catalogue garbage miner.
If you want DishNet to surface "Starlink" before it has a catalogue, that's a separate, deliberately
guarded miner — say the word and I'll build it as a follow-up (high min-count, hard STOP/GENERIC,
proper-noun bias) rather than reverting v31.

## Deploy

```bash
# GitHub dishnetafrica/shopping → EasyPanel pull → restart container, then:
php artisan migrate            # adds discovery_product_candidates
php artisan optimize:clear     # new routes
```

## Verify

- Open a catalogue tenant's Business Brain → **Activity & Reviews** → **Product Candidates** card.
- Approve one → toast "Added to Products as a draft — set a price to go live"; it disappears from the
  list and shows in **Products** as inactive (price 0). Set a price + activate to go live.
- Dismiss one → gone, stays gone after re-running discovery.
- Sandbox QA: `php qa/product_candidates.php` → 17/17.
