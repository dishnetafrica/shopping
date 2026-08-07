# v4.55 — Growth: who to ring today

**No migration.** Unzip, `php artisan optimize:clear`, done. Every number comes out of orders you
already have.

> Milestone 8 of the Sales Intelligence plan, built first because it depends on nothing else in
> ADR-016 — no schema, no provider, no map, no external source.

---

## What it does

A new **Growth** screen under Sales. It works out each customer's own buying rhythm from their order
history and lists the ones who have drifted past it, worst first.

The point is not "last ordered 43 days ago" — anyone can query that. It is that 43 days means a
crisis for a restaurant buying weekly and nothing at all for a hotel buying quarterly. The screen
tells those two apart, and refuses to guess for the customers where it cannot.

Three states worth acting on: **Gone quiet** (well past their gap, may be lost), **Late** (past it,
call now), **Due now** (call today, before the gap opens). Healthy customers get no row.

---

## Three decisions that make it trustworthy

**Median, never mean.** One Christmas shutdown or one bulk pre-order would drag an average far
enough to make every other customer look wrong. A weekly customer with gaps of 7, 7, 7 and 60 days
reads as weekly, which is what they are.

**No prediction from fewer than three orders.** Two orders give one interval, and one interval is an
anecdote. Those customers appear as *"Too new to judge"* and are left alone rather than wrongly
labelled late. Without this the screen fills with brand-new customers marked overdue and staff stop
reading it in a fortnight.

**Confidence is on the screen, not buried.** A customer who buys like clockwork gets **High**. One
whose orders arrive at random gets **Low**, and the row says *"their timing varies a lot — treat as
a hint"*. Saying "19 days late" about a customer with no rhythm would be inventing precision — the
same failure as a 94% purchase probability or a USD 54,000 estimated opportunity.

The ranking follows from that: value at stake × confidence × how late, with lateness capped at about
three cadences. A customer worth USD 4,000 slipping by 30% outranks one worth USD 40 who vanished a
year ago, and a guess never outranks a certainty.

---

## Two things it deliberately will not do

**It will not add two currencies together.** An order whose base-currency value was never stamped is
still counted for *cadence* — the date happened — but not for *value*, and the screen states how many
orders that was rather than quietly understating the total. Run `sales:backfill-base-currency` if
that count is large.

**It will not chase a walk-in.** An order with no phone number belongs to nobody who can be rung, so
it is skipped rather than grouped under a blank key.

---

## Files

| File | |
|---|---|
| `app/Services/Growth/ReorderSignals.php` | **new** — the arithmetic. Pure: no models, no database, no clock of its own |
| `app/Services/Growth/GrowthSignalsService.php` | **new** — assembles it from the order book, one query |
| `app/Capability/Process/GrowthCapability.php` | **new** — the screen |
| `app/Capability/Registry/CoreCapabilityProvider.php` | registers it |
| `resources/v3/shell.js` + `public/assets/v3/shell.js` | menu label, group, ready-set, icon — both copies |
| `resources/panel/help-center.html` | a card explaining how to read it |

---

## Checks that ran

```
t455/cadence.php     37 passed, 0 failed
t455/mutants.sh      20 killed, 0 survived
t455/contracts.sh    36 passed, 0 failed
t451–t454            all still green
```

The mutants are the useful ones here, because every failure in this feature is quiet. Swapping the
median for a mean does not crash — it just makes weekly customers look monthly so nobody rings them.
Dropping the three-order minimum does not crash — it just fills the screen with noise. Both are
mutants, both are caught.

Two contracts exist because of tonight's outage: every `Visibility`-style call site now gets its
enclosing closure checked, and both copies of `shell.js` are asserted identical.

---

## What is NOT proven

This has not run against your database. The arithmetic is tested hard and the wiring is asserted by
reading, but the numbers on the screen will be the first time this touches real orders.

**So the first thing to do is argue with it.** Open Growth, find a customer you know well, and read
their row. If it says a customer is late who you know buys sporadically, or misses one you know has
gone quiet, tell me which and what you expected — that is worth more than any test I can write here.
