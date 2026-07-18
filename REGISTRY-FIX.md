# REGISTRY-FIX.md — the actual bug, fixed (2 files)

## What your terminal proved
Every file uploaded fine. The exception:
`register(): Argument #1 must be of type Contracts\Capability, FollowupsCapability given`
My FollowupsCapability didn't declare `implements ProcessCapability` and used
`build()` where the contract requires `project()` (+ key() + permission()).
Claude's bug, full stop — and the class_exists hardening guarded the wrong
failure mode (file-missing, not type-mismatch).

## Fixed
1. FollowupsCapability now properly `implements ProcessCapability`
   (key/permission/project/summary/command — the full contract).
2. The registry guard upgraded to try/catch: ANY future registration failure in
   this capability = one warning log + one missing menu item, NEVER a dead panel.

## Deploy (2 files, 60 seconds)
Upload → app/Capability/Process/FollowupsCapability.php and
app/Capability/Registry/CoreCapabilityProvider.php → Rebuild → in the terminal:
```
php artisan tinker --execute="echo 'REGISTRY OK: '.count(app(\App\Capability\Registry\CapabilityRegistry::class)->all()).' capabilities';"
```
→ hard-refresh /v3 → full menu + chats return.

## Also spotted in your output
`TESTS MISSING` — the tests/ folder never landed. Not blocking, but upload
tests/Feature/*.php from v4-fix-complete.zip when convenient so the production
test gate works again.
