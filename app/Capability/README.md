# Capability Layer — Milestone 1 (Walking Skeleton)

The integration seam between the CloudBSS shell and the frozen engine. The shell never touches a
model or a service — it calls capabilities through `/api/v1/workspace/*`. Business logic stays in the
existing (frozen) services; capabilities only orchestrate and shape.

## What's here (M1)

```
Contracts/   Capability, ObjectCapability, ProcessCapability, CapabilityContext
Dto/         WorkspacePayload (the envelope) + Financial/Brief/ActionSpec/TimelineEvent/Related
Registry/    CapabilityRegistry, CapabilityManifest, CapabilityProvider, CoreCapabilityProvider
Command/     Command, HumanCommand, AiCommand, AiPolicy, CommandBus (human live; AI = suggest queue)
Shared/      StatementCapability→StatementService, HealthCapability, TimelineCapability, AssistantCapability (pure kernel)
Object/      CustomerCapability  (read via shared caps; write delegates to PaymentService)
Process/     CollectionsCapability (overdue queue + summary())
Http/        WorkspaceController, CommandController  (no App\Models / App\Services here — enforced)
```

Wiring: `App\Providers\CapabilityServiceProvider` (registered in `bootstrap/providers.php`) binds the
registry, runs `CoreCapabilityProvider`, and registers the routes inside the existing
`['web','auth',SetTenantFromUser]` group.

## Endpoints

```
GET  /api/v1/workspace/customer/{id}          → Customer Workspace envelope
GET  /api/v1/workspace/collections            → overdue queue
POST /api/v1/workspace/customer/{id}/record_payment   → delegates to PaymentService::record
POST /api/v1/workspace/collections/_/bulk_remind
```

## Verify

Runs anywhere (no Laravel needed):
```
php scripts/capability-arch-check.php     # proves Http layer references no models/services
php scripts/capability-smoke.php          # proves the brief kernel + envelope work (8 assertions)
find app/Capability -name '*.php' -exec php -l {} \;   # syntax
```

Runs after `composer install` + DB:
```
php artisan test tests/Unit/Capability tests/Feature/Capability
curl -s /api/v1/workspace/customer/1   # (authenticated) real Customer Workspace
```

## Honest status

- ✅ Verified in CI-without-app: 27 files lint-clean; architecture guard passes; brief-kernel + envelope smoke 8/8.
- ⏳ Not run here (needs `composer install` + Postgres): the PHPUnit feature test and the live HTTP call.
  The code mirrors `PaymentController`'s exact `PaymentService::record` mapping (Fx rate/usd, holder, kind),
  so the write path calls the engine the same way the current UI does.

## Dependency rules (enforced)

- `Http/*` may not import `App\Models` or `App\Services` (see `scripts/capability-arch-check.php` and `tests/Unit/Capability/ArchitectureTest`).
- Capabilities never write a model directly — writes go through a frozen service.
- Decorators (plugins) may only add to a payload, never remove engine values.
