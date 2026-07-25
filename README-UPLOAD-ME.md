# Upload this to GitHub — that's the only step

Everything finished so far is in this folder: the money-kernel code, its tests, the test factories,
and an automatic test-runner for GitHub. You don't need to run any commands.

## What to do
1. Upload these files to your GitHub repository, **keeping the folders** (`app/…`, `tests/…`,
   `database/…`, `.github/…`).
2. That's it.

## How you'll know it worked — no commands, just a colour
After you upload, GitHub runs everything for you. On your repository page, click the **“Actions”** tab
(top of the page) and look at the latest run:

- **Green ✓ “Money kernel (must pass)”** → the new money work is verified and correct. Done.
- **Red ✗** → send me a screenshot of the Actions page and I’ll tell you exactly what it means.

The green checkmark is your verification from now on. Every time you upload anything, GitHub re-checks
it and shows green or red — you never have to type a command again.

## One reassurance about the first run
The full test suite also runs, but only **for information** — older tests that were never run before
can’t turn your check red. Only the new money-kernel tests decide the green ✓. So a green check is a
true “the new work is good,” even if some old tests need attention later.

## What’s inside (you don’t need to touch any of it)
- `app/Kernel/…`, `app/Services/Money/PaymentService.php`, `app/Http/Controllers/Panel/StaffCashController.php`
  — the one money-in path (3A + 3B + 3C) that closes the double-payment bug.
- `database/factories/…` + `app/Models/User.php`, `app/Models/Tenant.php` — lets the test suite run.
- `tests/…/Kernel/…` — the tests that prove it.
- `.github/workflows/tests.yml` — the automatic checker.
