# CI — run the test suite automatically (GitHub Actions)

Your production container ships without `tests/`, so `php artisan test` there only sees the two
Laravel stub tests. Tests must run in CI (or a local dev checkout), never in the prod container.

## Install
Unzip at the repo root — it adds `.github/workflows/tests.yml`. Then:
```bash
git add .github/workflows/tests.yml CI-SETUP.md
git commit -m "CI: run PHPUnit suite (incl. Kernel money.receive) against Postgres on push/PR"
git push
```
Open the **Actions** tab on GitHub — the `tests` workflow runs on that push. Green = the 3A/3B
Kernel tests and the full suite pass.

## FIRST, confirm the tests are actually committed to git
CI runs whatever is committed. On your machine, in the repo:
```bash
git ls-files tests | grep -c 'Test.php'      # expect ~130+, NOT 2
git ls-files tests | grep Kernel             # expect the 4 Kernel test files
```
If that count is ~2, the real test suite was never committed — add and commit it:
```bash
git add tests
git commit -m "Add the test suite to version control"
```
(That, by itself, explains why the container only had ExampleTests.)

## Run locally right now (optional, fastest feedback)
On a full checkout with a dev Postgres DB and `.env` pointed at it:
```bash
php artisan test tests/Unit/Kernel tests/Feature/Kernel   # the 3A + 3B tests
php artisan test                                           # full suite
```
Remember: `--filter` matches test CLASS/METHOD names, not folders — so `--filter=Kernel` finds
nothing. Use the path form above, or `--filter='MoneyReceived|ReceiveMoney|PaymentServiceDualWrite'`.

## Notes
- Postgres 16 service matches your jsonb migrations; PHP 8.4 matches production.
- The workflow runs the Kernel tests first (fast, focused), then the whole suite as the regression gate.
- If a specific test needs an extra PHP extension, add it to the `extensions:` line.
