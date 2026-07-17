# PHASE-D-DEPLOY.md — the V2 retirement kit (safe to deploy today, OFF by default)

## What this is
The last tool in the migration: 302 redirects from classic /panel pages to their v3
workspaces. Deploying this zip changes NOTHING — the flag `v3_redirects` defaults OFF.
When the staff soak looks good, retiring V2 becomes one command.

## The safety properties (incident-informed)
- **OFF by default** — deploy is a no-op, verified by test.
- **Per-tenant runtime flag** — `php artisan v3:retire 17` flips it; `--off` is the
  instant rollback; no rebuild either way.
- **?classic=1 escapes every redirect** (kept one quarter for deep links + support).
- **papi untouched** — v3 itself consumes it; pinned by test.
- **Honest tail** — audit, partners, billing, diagnostics, guide, onboard have no v3
  home yet, so they keep rendering classic instead of redirecting into an error.
- Every one of the 35 redirect targets was verified against the live capability
  registry — no redirect can land on a workspace that doesn't exist.

## Files
| File | Change |
|---|---|
| app/Http/Middleware/ClassicPanelRedirects.php | NEW — the 35-entry map + gated 302 logic |
| routes/web.php | EDIT — middleware appended to the panel route group (runs after auth) |
| app/Console/Commands/V3RetireCommand.php | NEW — prints map + tail, flips the flag |
| tests/Feature/ClassicRedirectsTest.php | NEW — 4 tests: default-off no-op, on→302 targets, classic-escape + unmapped render, papi never redirected |
| V3-DEFAULT-MIGRATION-PLAN.md | Phase D marked kit-shipped |

## Rollout sequence (the whole endgame)
1. Deploy this zip any time (no-op) → gate:
   `php artisan test --filter='Comms|Architect|ChatsWorkspace|CommunicationWorkspace|LandingV3|ClassicRedirects'`
2. Deploy Phase C zip → `v3:staff 17 --dry` → Jayesh & Asish live on /v3.
3. **Soak ~1 week.** Signal to watch: nobody reaches for "Open classic panel".
4. `php artisan v3:retire 17 --dry` → read the map → `v3:retire 17`. V2 is retired.
5. One quarter later: remove ?classic=1, archive resources/panel, decide Filament /app.

That's the full V3-default directive, executed: enforced for new development (D6 test),
migrated (Chats + Email Center live, coverage matrix green), staff unified (Phase C),
and V2 disabled by one reversible command (this kit).
