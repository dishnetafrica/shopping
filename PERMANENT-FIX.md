# PERMANENT-FIX.md — this class of incident, ended

## The root cause of today
The first time PHP ever LOADED the new code was a live customer request. Syntax
checkers can't see wrong imports, missing `implements`, or signature mismatches —
only PHP's class loader can, and it ran in production.

## Layer 1 — the build gate (THE fix): `php artisan deploy:check`
Force-loads every class under app/ (PHP verifies imports, contracts, signatures),
boots the capability registry (today's exact failure surface), compiles routes.
Broken → loud failure naming file+line. Clean → "safe to serve".

**Wire it into the build so broken code CANNOT go live:**
EasyPanel → your app → **Build settings** → append to the build command,
right after `composer install ...`:
```
php artisan deploy:check
```
From then on: a bad upload FAILS THE BUILD → the old container keeps serving →
customers never see a 500 → the build log shows exactly what to fix. Today's
entire 40-minute chase becomes a 10-second red build log.

(Until you wire it in, run it manually right after every Rebuild — same protection,
one manual step.)

## Layer 2 — the try/catch registry guard (already live-fixed)
Even if something slips past, one broken capability = one missing menu item +
a log line, never a dead panel.

## Layer 3 — tests on the container (already in your repo now)
`php artisan test` after deploy:check catches logic bugs the loader can't.

## Layer 4 — Claude's side (process, already adopted)
- Canonical tree = YOUR live uploads, never a snapshot
- Imports copied from compiling siblings, contracts read before implementing
- New: every PHP file Claude ships gets its `use` statements cross-checked
  against actual file paths and its `implements` checked against the interface's
  method list — automated in the validation pipeline before any zip is built

## Deploy this zip (1 file)
app/Console/Commands/DeployCheckCommand.php → Rebuild → then prove it:
```
php artisan deploy:check
```
Expected: "N classes loaded clean · registry OK · routes · DEPLOY CHECK PASSED".
Then add it to the build command and forget about this failure mode forever.
