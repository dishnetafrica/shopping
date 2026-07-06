# The CloudBSS Kernel Constitution

*One page. Every developer reads it before touching the platform. Breaking any rule requires an
Architecture Review (ADR) — not a pull request.*

## The 14 rules

1. **The kernel never imports Laravel** (or any framework). The domain is pure PHP.
2. **The kernel never knows tenants.** Tenant/entity are event *metadata*; projections use them to
   scope read models. No core class branches on a tenant.
3. **The kernel never knows HTTP.** No requests, controllers, or routes in the domain.
4. **The kernel never knows databases.** Persistence lives behind adapters the kernel calls through
   interfaces it defines.
5. **The kernel never knows queues.** Delivery is an infrastructure concern outside the domain.
6. **One write model.** The event store is the single source of truth. Every change is an event.
7. **The ledger is projection-only.** It folds `JournalPosted` events into balances; it stores no
   write model and is always rebuildable from the log.
8. **Money never uses float.** Integer minor units only; arithmetic is overflow-checked and throws
   rather than ever becoming a float.
9. **Decisions record *why*.** Every consequential choice is a `Decision` capturing policy name +
   version + parameters + structured inputs + evidence — explainable forever.
10. **Events are immutable and ordered.** Never edited or deleted; each carries schema version,
    per-aggregate sequence, global position, and correlation/causation ids.
11. **Policies are versioned.** Accounting and Decision policies expose a version, captured on every
    posting/decision, so history stays explainable after rules change.
12. **Modules subscribe; they never modify the kernel.** New domains plug in through the event bus
    and the policy resolvers. If a new module needs a kernel edit, the design is wrong.
13. **The kernel public API is backward compatible.** See the compatibility policy below.
14. **Anything that breaks these rules needs an Architecture Review.** No exceptions merge silently.

## Compatibility & versioning policy

The kernel is a product with consumers (modules, tenants). It evolves without breaking them:

- **Additive by default.** New optional fields, new event types, new policies — never removals or
  meaning changes to existing ones.
- **Events are versioned, never mutated.** A breaking payload change is a *new* `schemaVersion`
  (or a new event name). Old subscribers keep working; the kernel can upcast old → new on read.
- **Value objects evolve by extension.** `Money v2` adds capability; it does not change how `v1`
  amounts behave. Same for Decision payloads.
- **Interfaces are promises.** `DecisionPolicy`, `AccountingPolicy`, `AccountingPolicyResolver`,
  `Clock`, `IdGenerator`, and the `Task` API are stable contracts. A breaking change to any of them
  is a **major** kernel version and ships with a migration path and a deprecation window.
- **Semantic versioning of the kernel.** `MAJOR` = a breaking contract change (rare, ADR-gated);
  `MINOR` = additive; `PATCH` = fixes. Modules pin a compatible kernel range.

## The freeze gate

The kernel is frozen only when it passes the **Architecture Stress Test** (`tests/stress.php`):
ten new business domains / countries / tax regimes / approval rules plug in, and every kernel file
is proven byte-for-byte unchanged. Re-run it before every kernel release — if a change to the core
is needed to admit a new module, that change is itself an ADR, not a routine edit.
