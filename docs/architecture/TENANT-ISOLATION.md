# Tenant isolation — the two execution modes

CloudBSS is one deployment serving many shops (tenants). Isolation is **structural**, not a
convention each query must remember. This note defines the two modes every piece of server code
runs in, and the rules that keep them safe. It is reference material (it rides along in the repo
but is not deployed).

## The mechanism

- **`App\Support\TenantContext`** — a per-process singleton holding the active `tenantId` (and a
  `superAdmin` bypass flag). Set from the request by middleware (`SetTenantFromUser`,
  `IdentifyTenantByDomain`); set explicitly by console/queue code.
- **`App\Models\Concerns\BelongsToTenant`** — a global scope added to tenant-owned models. When not
  super-admin it appends `WHERE <table>.tenant_id = TenantContext::id()` to every read, and stamps
  `tenant_id` on create when it is empty.
- **The sharp edge:** `TenantContext::id()` is `null` when nothing is set. Eloquent turns
  `->where('tenant_id', null)` into `WHERE tenant_id IS NULL`, which **no real row satisfies**. So a
  scoped read with no context does not return everything — it returns **nothing**, silently. That is
  the failure mode every rule below exists to prevent.

## Mode 1 — Tenant operation

Work scoped to exactly **one** tenant: an HTTP request, a bot message, a job that carries a tenant.

- Context is established once (request middleware, or the job setting it first) and every scoped
  read is automatically correct. Code does not repeat `where('tenant_id', …)`.
- A long-lived worker must not inherit the previous unit of work's context. The job/command boundary
  **resets** `TenantContext` so a unit of work that forgets to set a tenant reads *nothing* (safe)
  rather than the previous tenant's data (a cross-tenant leak). A **synchronous** (inline) job must
  not wipe the caller's context — the boundary saves and restores it. (See the TenantContext
  boundary workstream.)

## Mode 2 — Platform operation

Work that **deliberately** spans many tenants: scheduled sweeps, reconciliation, the tender scan,
the AI-campaign sender. This is the mode that bites, because there is no request to set context.

A platform operation is almost always a loop over tenants. It has two parts, and **both** must be
handled:

1. **The cross-tenant index read** — the list of tenants / campaigns / tenders to process. There is
   no single tenant yet, so this read is an *explicit, authorized* cross-tenant read via
   `Model::withoutGlobalScopes()`. Keep it narrow and obvious.
2. **Per-tenant processing** — once you are working on one tenant's data, every tenant-scoped read
   must be constrained to that tenant. Two sanctioned ways:

   - **(a) Establish context (preferred).** `TenantContext::set($tenant->id)` at the top of the loop
     body, cleared in a `finally`. One boundary then scopes *every* nested read, however deep the
     call chain. **Required** when the per-tenant work fans out through services (the tender scan is
     `scan → analyze → sync → assignSourcing` across several classes — filtering each nested read by
     hand is infeasible and fragile).
   - **(b) `withoutGlobalScopes()` + an explicit `where('tenant_id', …)` on every statement.**
     Acceptable only for a **flat** loop with a few auditable reads (e.g. the AI-campaign sender,
     whose every draft read carries the explicit filter, and whose exclusion list is read through a
     raw query builder that is not scoped at all).

   When in doubt, use (a). Mixing them on the same read (both `withoutGlobalScopes()` and a live
   context) is a smell — the opt-out wins and the context is dead weight.

### The rules

- Never run a platform loop with no context **and** no explicit filter — that is the `tenant_id IS
  NULL` → zero-rows trap. It fails silently: the sweep "succeeds" having done nothing.
- Pattern (a) **must** clear context in a `finally` (exceptions included), so one tenant's id never
  leaks into the next iteration or past the command on a worker.
- An already-loaded model's `save()`/`update()` is a keyed write (`WHERE id = ?`) and is scope-free
  regardless of context — but the *reads* that precede it are not.
- `superAdmin` is a bypass for the admin panel only. Console/queue platform code uses context or
  explicit filters, never the bypass, so a forgotten reset cannot silently widen a later read.

## What enforces this

- `tests/Feature/TenderScanContextTest.php` — DB-backed: `TenderItems::sync()` reports the real
  count only with context and `0` without (the regression), dedup requires context, rows are
  isolated per tenant, and `tenders:scan` leaves no context behind.
- `tests/Feature/TenderAiCampaignScopeTest.php`, `tests/Feature/UnscopedModelsTenantScopeTest.php` —
  query-level: scoped models filter `tenant_id`; the `withoutGlobalScopes()` sweep does not; the
  super-admin bypass is preserved.
- `tests/Feature/TenantContextResetTest.php` — the job/command boundary: a job starts on a clean
  slate, a synchronous/inline job preserves and restores the caller's context.
- `qa/*.php` pure harnesses (run in the sandbox) model the scope arithmetic and the loop control
  flow for the parts that cannot boot Laravel.

## Checklist for new platform code

- [ ] Is this a loop over tenants? Then it is a platform operation — handle both parts.
- [ ] Index read: `withoutGlobalScopes()`, narrow, commented.
- [ ] Per-tenant: `set()` in a `try`, `clear()` in a `finally` (preferred), **or** explicit
      `tenant_id` on every read for a flat, auditable loop.
- [ ] A regression test that fails if the context/filter is removed.
