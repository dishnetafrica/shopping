# Currency consolidation — kill the UGX-hardcode bug class (seller.html)

Root cause: ~20 places in the panel hardcoded "UGX" instead of reading the tenant's currency,
so native-currency tenants (e.g. South Sudan USD, Rwanda RWF) saw "UGX" on money & labels.

Fix: one canonical helper `baseCode()` = PLAIN ? PANEL_CUR : 'UGX' (the currency prices are
stored/entered in). Every display site now reads it. 25 call-sites wired.

SAFETY GUARANTEE: for convertible tenants (Uganda) baseCode() returns 'UGX' exactly — their
panel is byte-for-byte unchanged. Only native-currency (PLAIN) tenants get corrected.

Fixed surfaces:
- Analytics money (anMoney), report/CSV headers (Total/Revenue/Total Amount, Spent_/AOV_/Credit_)
- Quotation currency default + dropdown (native tenants get their own currency, not UGX/USD)
- Product add/edit price labels, commission Min/Max labels, refund Amount label
- Credit/refund notes, store-wide discount badge + example note
- POS cart placeholders (no stale "UGX 0" flash)
- Settings screen: syncCurrencyLabels() relabels delivery-fee / discount fields live, and hides
  the UGX/SSP/USD exchange-rate + auto-routing inputs for native tenants (no conversion for them)

Left intentionally: data-column keys (Price_UGX/total_ugx — source CSV field names), the display-
currency switcher (already hidden for native tenants), and help-doc text about the conversion model.

Deploy: drop resources/panel/seller.html into the repo -> commit -> push -> REBUILD.
