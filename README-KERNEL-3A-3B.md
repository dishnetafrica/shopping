# ShopBot Kernel — Tickets 3A + 3B (money.receive)

This bundle adds the first slice of the frozen Blueprint's **Week 3** to the repo: the
`money.receive` capability (3A) and `PaymentService` dual-writing through it (3B). It is
**additive and behaviour-preserving** — nothing existing is deleted, and the live money path
is unchanged except that its event now flows through the one capability.

Unzip at the repo root; the files land in their correct places.

## What's in here

### Code (the commit)
```
app/Kernel/Contracts/KernelCapability.php              (3A) fine-grained capability contract
app/Kernel/Events/MoneyReceived.php                    (3A) money.received event contract  (3B: honours explicit subject)
app/Kernel/Capabilities/Money/ReceiveMoney.php         (3A) the money.receive capability (validate → R3 → emit)
app/Services/Money/PaymentService.php                  (3B) dual-writes the event through money.receive  [MODIFIED]
tests/Unit/Kernel/MoneyReceivedEventTest.php           (3A) schema tests
tests/Feature/Kernel/ReceiveMoneyCapabilityTest.php    (3A) happy-path / R3 / malformed
tests/Feature/Kernel/MoneyReceiveIdempotencyTest.php   (3A) retry → one event
tests/Feature/Kernel/PaymentServiceDualWriteTest.php   (3B) parity + behaviour-preservation + idempotency
```
> `PaymentService.php` is a full-file replacement carrying the 3B dual-write block; everything
> else in it (the authoritative OrderCost + CustomerPayment writes, syncs, discount) is unchanged.

### Docs (optional — the architecture record; safe to delete if you only want code)
```
docs/architecture/  Due Diligence, 2036 Architecture, Kernel Constitution, Implementation Blueprint,
                    Engineering Principles, Execution & Evidence System
3A-APPLY.txt / 3B-APPLY.txt   per-ticket apply + verify + rollback notes
```

## Commit it
```bash
git checkout -b kernel/money-receive-3a-3b
git add app/Kernel tests/Unit/Kernel tests/Feature/Kernel \
        app/Services/Money/PaymentService.php \
        docs/architecture README-KERNEL-3A-3B.md 3A-APPLY.txt 3B-APPLY.txt
git commit -m "Kernel 3A+3B: money.receive capability + PaymentService dual-write (behaviour-preserving)"
git push -u origin kernel/money-receive-3a-3b
```

## Verify (in CI / your env — a DB is required, so it does not run in the doc environment)
```bash
php -l app/Kernel/Events/MoneyReceived.php app/Kernel/Capabilities/Money/ReceiveMoney.php \
       app/Services/Money/PaymentService.php
php artisan test --filter=Kernel     # the 3A + 3B kernel tests
php artisan test                     # full suite — must stay green (no regression)
```

## Safety
- Additive + reversible. No migration (the `bos_events` spine already exists).
- The money-in path is guarded: the capability emit can never throw into a payment (ADR-012).
- R3 is **observe-only** in 3B (logged, never blocks); it becomes the fatal front-door rule in **3C**.
- Rollback: `git revert` the commit — the capability + tests remain, harmless.

## Next
**3C** — point `StaffCashController` at `money.receive` as the front door (fatal R3, "book excess as
advance"), prove parity against the 3B dual-write stream, then delete the duplicate money-in path.
That is the ticket that closes the due-diligence double-record for good.
