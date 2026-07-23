CloudBSS — COMBINED DEPLOY (everything from this session that's safe to ship now)
================================================================================

Unzip this into your repo root and it brings you fully up to date: the payment fix, the Finance/Run route
fix, and the whole mobile modernization (SW cache, native Payments/Settings-VAT/P&L, list card-mode,
create sheets, Quick Invoice). One commit, one rebuild.

NOT in this zip (on purpose): the "money-five" tenant-scoping (Stage 2). That one is still GATED on your
tenant-isolation test suite going green — deploy it separately from CloudBSS-money-five-scoping-STAGE2 when
your tests are green, per its own note. Nothing here depends on it.

────────────────────────────────────────────────────────────────────────────────────────────────
WHAT'S INSIDE
  FRONTEND
    resources/views/shell.blade.php     the whole modern app: interaction pass (transitions, pull-to-
                                        refresh), desktop-vs-phone gating, native Receive-Payment, phone
                                        card-mode for classic lists, native Settings + VAT, native P&L,
                                        quick-add create sheets (New Customer/Expense), Quick Invoice,
                                        + service-worker registration for all users
    public/sw.js                        weak-signal cache (network-first shell, cache-first assets, never
                                        caches /papi or /api)

  BUG FIXES (money + routing)
    app/Services/Money/PaymentService.php               captures $idem in the txn closure — payments were
                                                        500ing on EVERY record before this
    routes/web.php                                      registers /papi/bos-finance + /papi/bos-run (Finance
                                                        was 404) alongside bos-today/bos-customers

  BACKEND THE MODERN SCREENS USE
    app/Http/Controllers/Panel/BosFinanceController.php   Finance hub data (CashService SSOT)
    app/Http/Controllers/Panel/BosRunController.php       Collect Run + open-invoice payloads
    app/Http/Controllers/Panel/BosCustomersController.php Customers list data
    app/Capability/Object/CustomerCapability.php          Customer 360 (score/lifetime/credit/history)

  SETTINGS / VAT / EXPENSE
    app/Support/SettingsSchema.php                        new VAT / Sales-tax field group
    app/Http/Controllers/Panel/PanelApiController.php     settings-hub resolves the VAT values (VatConfig)
    app/Services/Settings/SettingsWriteService.php        VAT keys added to the write whitelist
    app/Http/Controllers/Panel/TradeCashbookController.php FX-rate fallback (non-USD expense needs no rate)

  MIGRATION (already applied on your server — harmless to re-run; guarded by hasColumn)
    database/migrations/2026_07_24_000001_add_idempotency_key_to_customer_payments.php

────────────────────────────────────────────────────────────────────────────────────────────────
DEPLOY
  unzip into repo root, then:

  git add \
    resources/views/shell.blade.php public/sw.js routes/web.php \
    app/Services/Money/PaymentService.php \
    app/Http/Controllers/Panel/BosFinanceController.php \
    app/Http/Controllers/Panel/BosRunController.php \
    app/Http/Controllers/Panel/BosCustomersController.php \
    app/Capability/Object/CustomerCapability.php \
    app/Support/SettingsSchema.php \
    app/Http/Controllers/Panel/PanelApiController.php \
    app/Services/Settings/SettingsWriteService.php \
    app/Http/Controllers/Panel/TradeCashbookController.php \
    database/migrations/2026_07_24_000001_add_idempotency_key_to_customer_payments.php \
    README-DEPLOY-ALL.txt

  git commit -m "CloudBSS: payment fix + Finance/Run routes + full mobile modernization"
  git push                         # -> EasyPanel Rebuild
  php artisan migrate              # applies idempotency_key if not already (no-op if it is)
  php artisan route:clear && php artisan optimize:clear && php artisan view:clear

────────────────────────────────────────────────────────────────────────────────────────────────
QUICK VERIFY ON A PHONE
  • Record a payment (Collect or "+ → Receive Payment") → "Payment recorded".
  • Finance → the cash hero + rows; tap Profit & Loss → the income statement.
  • Me → Settings → set a VAT rate, toggle Charge VAT, Save.
  • Open Operations → Orders (or Finance → Invoices) → cards, not a sideways table.
  • "+" → New Customer / New Expense → the modern sheets.
  • Airplane mode: reopen the app — it still loads (cache); collect a payment — "Saved offline", syncs later.

  MONEY-DOCUMENT FIELD-TEST (do this once): "+" → New Invoice → pick a delivered order → Issue invoice →
  confirm the number matches your classic Invoices screen and appears exactly once; tap the same order
  again → same number (idempotent, never double-numbers).

  On a laptop: Customers/Finance/Operations/Settings/P&L/Payments all stay the classic desktop views —
  untouched.

SAFETY
  Additive + reversible; desktop behaviour unchanged. The only money-writing addition (Quick Invoice)
  reuses your existing idempotent invoice-issue endpoint — no new money path. `git revert` the commit to
  roll everything back at once.
