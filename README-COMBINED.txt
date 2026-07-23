CloudBSS — COMBINED BUNDLE (two parts, TWO different deploy timings)
===================================================================

This zip carries BOTH pieces of work. Unzipping drops the files into your repo, but NOTHING deploys
until you `git add` + push — and the two parts are NOT on the same schedule. Read this first.

────────────────────────────────────────────────────────────────────────────────────────────────
PART 1 — INTERACTION PASS  ✅ SAFE TO DEPLOY NOW  (frontend-only, no DB, no money path)
────────────────────────────────────────────────────────────────────────────────────────────────
  Page transitions + pull-to-refresh + skeletons across the app. Fully additive and reversible.
  File:  resources/views/shell.blade.php
  Note:  DEPLOY-INTERACTION-PASS.txt

  Deploy now:
     git add resources/views/shell.blade.php DEPLOY-INTERACTION-PASS.txt README-COMBINED.txt
     git commit -m "Interaction pass: page transitions + pull-to-refresh + skeletons (design v1.0)"
     git push   ->  EasyPanel Rebuild
     php artisan optimize:clear && php artisan view:clear
  Then field-test pull-to-refresh on your phone (it's a touch gesture — see the note).

────────────────────────────────────────────────────────────────────────────────────────────────
PART 2 — MONEY-FIVE TENANT SCOPING  ⚠️ DO NOT DEPLOY YET — GATED ON GREEN TESTS
────────────────────────────────────────────────────────────────────────────────────────────────
  Adds tenant isolation to the five money models (CustomerPayment, OrderCost, Ledger*) + patches the
  CLI commands that read them. A mistake here risks cash/GL data, so it is deliberately held behind a
  green tenant-isolation test suite.
  Files:  app/Models/CustomerPayment.php, OrderCost.php, LedgerAccount.php, LedgerJournal.php,
          LedgerLine.php
          app/Console/Commands/AuditPaymentsCommand.php, FixPaymentsCommand.php,
          RepriceForeignCashCommand.php, LedgerBackfill.php
          tests/Unit/TenantIsolationTest.php, tests/Feature/TenantIsolationDbTest.php
  Note:   DEPLOY-MONEY-FIVE-SCOPING.txt  (full detail, incl. how to get to green)

  DO NOT `git add` the Part-2 files until `php artisan test --filter=TenantIsolation` is GREEN on your
  server. Deploying the isolation-test harness first, seeing green, THEN adding these files is the whole
  safety mechanism. Full steps are in DEPLOY-MONEY-FIVE-SCOPING.txt.

────────────────────────────────────────────────────────────────────────────────────────────────
IF YOU JUST WANT ONE COMMIT
  Deploy Part 1 now. Leave Part 2's files sitting in the working tree (un-added) until your tests are
  green, then commit them per DEPLOY-MONEY-FIVE-SCOPING.txt. `git status` will keep reminding you they
  are staged-but-not-committed — that's intentional.
