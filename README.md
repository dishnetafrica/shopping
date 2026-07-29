# Fix — supplier LIST showed gross owed (ignored payments)

## Symptom
On the Suppliers list, the OWED column showed the GROSS of a supplier's credit bills
and ignored payments made against them. Clicking into the supplier showed the correct
(net) figure. E.g. AVION showed "2,787 UGX" (gross bills) instead of ~$1,170 net;
Nura showed "$5,667" instead of "$538".

## Cause
`SupplierListCapability` computed owed as
  SUM((native_amount − paid_native) / rate)  over stage='purchase' rows only.
Credit bills keep paid_native = 0, and supplier PAYMENTS live as separate
stage='supplier_payment' rows that this query never included — so payments never
reduced the list figure. (Same class of bug as the dashboard payables overstatement.)

## Fix
One shared source of truth: `PayablesCalculator::bySupplierUsd()` returns net owed per
supplier (credit bills − supplier_payments + opening balance, using stored usd_amount).
The supplier LIST now uses it, and the supplier DETAIL (SupplierController::owedBySupplierUsd)
delegates to it too — so the two screens can never disagree again.

## Files
- app/Services/Finance/PayablesCalculator.php        (new bySupplierUsd())
- app/Capability/Process/SupplierListCapability.php  (uses it; keeps PO-count query)
- app/Http/Controllers/Panel/SupplierController.php  (detail delegates to it)
- tests/Feature/PayablesCalculatorTest.php           (net + overpaid regression)

## Deploy
No migration. Copy files, then:
    php artisan config:clear && php artisan route:clear && php artisan view:clear
Verify:
    php artisan test --filter=PayablesCalculatorTest
The list will then match each supplier's detail page (AVION ~$1,170, Nura $538, etc.).
