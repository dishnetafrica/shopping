# Fix: POS quotation → order shows USD 0.00

## What was happening
Your shop is `currency = USD`, and your catalogue prices (33, 98, 81, 92 …) are already
in **USD**. But the shop was running in **converted** currency basis, which assumes
catalogue prices are in **UGX** and divides them by the USD rate (~3750) to display USD:

    33 ÷ 3750 = $0.0088  → rounded to nearest $0.50 → $0.00

So every line floored to **$0.00**, the quotation was saved with total 0, and
`quotationConvert` copied that 0 into the order → **USD 0.00**.

## The fix — TWO parts (both required)

### 1. Setting (stops the zeroing) — do this once per USD shop
Set the shop to **native** basis so USD prices are used exactly as entered:

    php artisan tinker
    >>> $t = App\Models\Tenant::find(17);
    >>> $t->putSetting('currency_basis','native'); $t->save();
    >>> $t->setting('currency_basis');   // => "native"

(Do NOT change the global default — other tenants that price in UGX rely on
`converted` basis. This is per-tenant.)

### 2. Code (this ZIP) — stops the follow-on inflation bug
`app/Http/Controllers/Panel/PanelApiController.php` → `quotationConvert()` now respects
native basis. Previously it multiplied every USD quotation by the rate when converting to
an order — correct for *converted* shops, but for a *native* USD shop it would inflate
every order by ×3750. It now converts only when USD is a display layer over a UGX base.

No behaviour change for converted-basis shops (native=false → identical to before).

## Recover the broken quotation
The existing quotation is already stored with prices = 0 in the DB — that value is gone and
cannot be recovered. After steps 1 + 2, **re-create the POS quote**, send/accept it, and
convert. The new order will show the correct USD total.

## Deploy
Upload `resources`/`app` path from this ZIP over your repo (overwrites just the one file),
commit, then EasyPanel → Rebuild. Rollback = revert the commit.

## Quick verify after deploy
1. `currency_basis` = native (step 1).
2. POS: add ALUMINIUM FOIL 45CM → quote in USD → PDF shows USD 33 (not 0.00).
3. Accept → Convert → order shows USD 33 (× qty), not 0.00.
