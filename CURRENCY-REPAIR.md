# CURRENCY-REPAIR.md — why invoices show mixed UGX/USD, and the fix

## Diagnosis (from your screenshot)
Asish: Total $625.00 · Paid $2,284,375.00 — that "paid" is a UGX amount
(2,284,375 ÷ 3650 ≈ $625, the exact match) written RAW into a USD invoice by an
older payment path. The display and KPI code are already FX-correct — they
faithfully consolidate whatever is stored, so stale rows poison "Paid (30d)"
into $14.6M nonsense. Data problem, not code problem.

## The fix: one command (in this zip)
```
php artisan invoices:repair-paid 17 --dry     # READ the before→after list
php artisan invoices:repair-paid 17           # apply
```
It re-runs the EXISTING correct math (true transaction currency + USD-bridged FX)
over every live invoice. Idempotent — correct invoices don't move. Expect Asish
to become: paid $625.00 · balance $0 · status paid. Cached PDFs of changed
invoices are invalidated automatically.

## Make sure the rates are yours (they drive the FX bridge)
```
php artisan tinker --execute="$t=App\Models\Tenant::find(17); $t->putSetting('rate_ugx_per_usd', 3650); $t->putSetting('rate_ssp_per_usd', 4500); echo 'rates set';"
```
(Use your real SSP rate. The Fx service prefers effective-dated `fx_rates` when
present; these legacy keys are its reliable fallback.)

## Going forward
Every new payment already flows through the FX-correct sync — this was a
one-time cleanup of pre-machinery data. If a future import writes raw amounts
again, the same command repairs it.
