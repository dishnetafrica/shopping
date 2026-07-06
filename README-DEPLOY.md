# Deploy CloudBSS ledger into ShopBot (git → EasyPanel Rebuild)

Unzip this at the ROOT of your ShopBot repo (the one EasyPanel builds from). It drops the kernel
and all integration files into place. Then make TWO small edits by hand (in a text editor — NOT in
the terminal), commit, push, and Rebuild.

## What unzipping adds (already at correct paths)
- cloudbss-kernel/                                   the accounting engine (src/ app/ infra/)
- app/Services/Ledger/OrderLedgerService.php         Order → sale, LedgerEntry → receipt
- app/Services/Ledger/StatementMessenger.php         send a statement on WhatsApp
- app/Observers/OrderLedgerObserver.php              posts the SALE on delivery
- app/Observers/LedgerEntryObserver.php              posts a RECEIPT on each order_payment
- app/Providers/LedgerServiceProvider.php            binds services + registers observers
- app/Filament/Pages/Books.php                       Books dashboard page
- app/Filament/Pages/Settings.php                    MODIFIED — adds "Accounting / Ledger" section
- resources/views/filament/pages/books.blade.php     the Books view
- database/migrations/2026_07_06_000000_create_cloudbss_ledger.php   creates cloudbss_* tables

⚠️ Settings.php here is the FULL file. If you changed your Settings page recently, DIFF it before
overwriting (it already contains the Special Order section).

## EDIT 1 — composer.json (add the three CloudBSS autoload paths)
Open composer.json in a text editor. Find the "autoload" → "psr-4" block and add the 3 lines so it
looks like this (keep your existing "App\\" line):

    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "CloudBSS\\Kernel\\": "cloudbss-kernel/src/",
            "CloudBSS\\App\\": "cloudbss-kernel/app/",
            "CloudBSS\\Infra\\": "cloudbss-kernel/infra/"
        }
    },

Also make sure ext-pdo is fine (it is on this image). Nothing else changes.

## EDIT 2 — bootstrap/providers.php (register the provider)
Open bootstrap/providers.php and add this line to the returned array:

    App\Providers\LedgerServiceProvider::class,

## Then
1. git add -A && git commit -m "Add CloudBSS event-sourced ledger + Books dashboard" && git push
2. EasyPanel → your ShopBot service → **Rebuild** (not Restart). The rebuild runs composer install
   and picks up the new autoload paths.

## After the rebuild — verify IN ORDER (container console)
    # (a) kernel autoloaded? MUST be true before anything else:
    php artisan tinker --execute="var_dump(class_exists('CloudBSS\\Infra\\SalesLedger'));"   # bool(true)

    # (b) create the tables:
    php artisan migrate     # creates cloudbss_events, cloudbss_ledger_balances, cloudbss_subledger

    # (c) confirm tables exist:
    php artisan tinker --execute="var_dump(\Illuminate\Support\Facades\Schema::connection('pgsql')->hasTable('cloudbss_events'));"  # bool(true)

If (a) is false, STOP — the autoload edit didn't take. Do not enable books until (a) is true, or the
Books page will error.

## Turn it on SAFELY (one tenant)
Books default OFF for everyone, so the rebuild changes nothing for sellers until you opt a tenant in.
Pick ONE test tenant, open the panel → Settings → Accounting / Ledger → "Keep automatic books" ON,
set VAT 18 and terms. Deliver a test order, then open the Books page. Watch it post + balance.
For Family Shoppers testing use that tenant; for Patronics use tenant 7.

## Rollback
If anything looks wrong: set ledger_enabled OFF (or remove the provider line + Rebuild). The cloudbss_*
tables are isolated and harmless if unused; orders/payments behave exactly as before.

## Honest note
The accounting engine is fully tested (68 assertions green). The Filament Books page + WhatsApp
button were NOT render-tested outside your app — click through them on a test tenant before relying
on them, ideally on a staging copy of this service if you can clone one in EasyPanel.
