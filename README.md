# Fix — empty morning cashbook (and task) digest

## Symptom
The daily WhatsApp cashbook digest read "Combined cash: $0.00" with no holders/movement,
even though the live cashbook holds real money (~$4,950 combined). Only the Facebook-ad
line showed numbers.

## Cause
A scheduled Artisan command runs with NO TenantContext. `OrderCost` (and `OrderTask`)
carry a global tenant scope that, when the context is unset, appends `AND tenant_id = NULL`
to every query — matching zero rows. So `CashbookExport` saw no cash. The ad line was the
only survivor because it uses a raw `DB::table()` query, which the Eloquent scope doesn't touch.

## Fix
Set the tenant context per tenant inside the command loop (one line), exactly like
`TaskReminderCommand` already does. The `TaskDigestCommand` had the identical bug and is fixed too.

Other morning digests (Bot / OrderPending / StorefrontGaps / Demand) were audited and are safe.

## Files
- app/Console/Commands/CashbookDigestCommand.php
- app/Console/Commands/TaskDigestCommand.php
- tests/Feature/CashbookDigestScopeTest.php (new)

## Deploy
Copy the files, then:  no migration needed.
    php artisan config:clear
Test tomorrow's digest immediately (safe, sends to the configured number):
    php artisan cashbook:digest --tenant=<FamilyShoppersId> --force
It should now show the real combined cash and per-person balances.
