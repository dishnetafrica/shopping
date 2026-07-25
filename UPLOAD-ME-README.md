# Cash Handover — upload this to GitHub (that's the only step)

This is the new **cash handover** capability we planned: recording cash passing from one person to
another as a single, provable action. Everything needed is in this folder. No commands to run.

## What to do
Upload these files to your repository, **keeping the folders** (`app/…`, `routes/…`,
`resources/…`, `tests/…`). They replace/add exactly these 8 files and nothing else.

## How you'll know it worked — just a colour
After you upload, GitHub runs the checks. On the **Actions** tab, the latest run:

- **Green ✓ "Money kernel (must pass)"** → the new handover work is verified (the new tests run
  inside that same check). Done.
- **Red ✗** → send me a screenshot of the Actions page and I'll tell you exactly what it means.

## Then try it in the app (the real proof)
Open **Staff Cash**, pick a person, and you'll see a new **🤝 Hand over** button next to Give / He
spent / He received / Settle. Record a handover from one person to another and watch:

- the giver's cash in hand go **down** and the receiver's go **up** by the same amount,
- the **company total stay exactly the same** (money only moved between pockets),
- one clean entry on each person's history.

If the same handover ever gets sent twice (flaky signal, double-tap), it still records **once**.

## What's inside (you don't need to touch any of it)
- `app/Kernel/Events/CashHandedOver.php` — the handover fact (the first *custody* event).
- `app/Kernel/Capabilities/Cash/HandOverCash.php` — the one capability that records it. It only
  emits the fact; it never writes a row itself.
- `app/Http/Controllers/Panel/StaffCashController.php` — adds the `handover` action (the give /
  spend / receive / settle actions are untouched).
- `routes/web.php` — adds the one new route.
- `resources/panel/staff_cash.html` — adds the **🤝 Hand over** button + form (nothing existing changed).
- `tests/…/Cash*` and `tests/…/StaffCashHandover*` — the tests that prove all of the above.

## Safety notes
- **Additive only.** No existing money path (give / spend / receive / settle / invoice pay) was changed.
- **P&L untouched.** A handover is an internal move, so it never counts as income or expense.
- **Watching, not blocking.** If someone hands over more than the system says they hold, it's logged
  as evidence — it does **not** stop them yet. We enforce only once the evidence proves it's needed.
- **Rollback:** remove these files and you're exactly where you are today.
