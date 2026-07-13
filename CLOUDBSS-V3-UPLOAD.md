# CloudBSS v3 — Upload / Deploy Guide

This bundle contains **all** the v3 work: the Capability Layer, the `/v3` shell (sidebar +
list workspaces), the native-currency formatting, and the reskinned sign-in. Paths mirror the
repo, so you can extract it over your working copy and commit.

## What's in here (paths are relative to the repo root)

**New — the Capability Layer** (entire namespace is new, safe to add):
```
app/Capability/**                         52 PHP files — contracts, DTOs, registry,
                                          command bus, shared/object/process capabilities,
                                          the 7 list workspaces, HTTP transport, README
app/Providers/CapabilityServiceProvider.php   binds the registry + registers /api/v1 + /v3 routes
scripts/capability-arch-check.php         standalone architecture guard (no Laravel needed)
scripts/capability-smoke.php              standalone envelope/brief smoke test
tests/Unit/Capability/**  tests/Feature/Capability/**   PHPUnit
```

**New — the shell UI:**
```
resources/views/shell.blade.php           the /v3 single-page shell (nav + all workspaces)
```

**Modified — existing engine files (small, backward-compatible edits):**
```
bootstrap/providers.php                   appends App\Providers\CapabilityServiceProvider::class
app/Filament/Auth/OtpLogin.php            login default method 'whatsapp' -> 'email' (one line)
resources/views/filament/auth/otp-login.blade.php   split-screen sign-in reskin
MIGRATION-SCOREBOARD.md                   progress notes
```

> The engine itself is untouched. The HTTP layer imports no models/services (enforced by
> `scripts/capability-arch-check.php`); every write goes through the existing frozen services.

## Upload options

**A. From a local clone (recommended)**
```
# unzip this bundle over your working copy so files land at the right paths, then:
git checkout -b v3-workspaces
git add app/Capability resources/views/shell.blade.php \
        resources/views/filament/auth/otp-login.blade.php \
        app/Filament/Auth/OtpLogin.php app/Providers/CapabilityServiceProvider.php \
        bootstrap/providers.php scripts/capability-arch-check.php scripts/capability-smoke.php \
        tests/Unit/Capability tests/Feature/Capability MIGRATION-SCOREBOARD.md
git commit -m "CloudBSS v3: capability layer, /v3 shell, list workspaces, native currency, sign-in reskin"
git push -u origin v3-workspaces
```

**B. GitHub web upload** — drag the folders into the repo's "Add file → Upload files",
keeping the same paths, and commit.

## After deploy — clear caches (important)
```
php artisan view:clear      # Blade recompiles shell.blade.php + login
php artisan config:clear
php artisan route:clear
```

## Verify it's live
```
php scripts/capability-arch-check.php     # "17+ capabilities registered; graph closed"
php scripts/capability-smoke.php          # "SMOKE PASS"
# in a browser (authenticated):
#   /v3                    -> new shell, sidebar with counts
#   /v3#/customers         -> Customers list (KPIs + table)
#   /api/v1/workspace/     -> JSON catalog with counts
```

## Notes
- Money renders in the tenant's **native currency** (UGX/USD/SSP), not a hard-coded `$`.
- List workspaces show the first 500 rows; their KPIs are aggregated over **all** records.
- Two columns are intentionally omitted because they're not in the frozen schema:
  customer **Segment** and quotation **Prepared by**. Each is a one-field additive migration
  if you want them later.
- The legacy `/panel` and `/admin` UIs are **not** modified by this bundle.
