# PHASE-C-DEPLOY.md — one product, one screen: staff join /v3

## What this ships
Jayesh (purchaser) and Asish (field) now land on **/v3** exactly like everyone else —
desktop (/panel) and mobile (/panel/m). The two-screens era ends here.

Runtime-reversible without a deploy: tenant setting `v3_all_roles` (default ON).
`php artisan v3:staff 1 --off` instantly restores their old tap-tile home;
`v3:staff 1 --dry` prints, per role, exactly which v3 workspaces they can open.

## The one real blocker (found + fixed)
Cashbook's v3 gate was `menu:finance`; purchaser/field hold `cash` but NOT `finance`,
so their daily cash screen would have 403'd on day one. Gate corrected to `menu:cash` —
byte-for-byte classic parity (accountant/procurement unaffected; sales still excluded).

Their v3 menu after the flip: Morning Briefing · My Work · Sales Orders · Quotations ·
Purchasing · Suppliers · Procurement Hub · Demand Plans · Cashbook (+ open Settings
read view, platform-wide today — noted for a later tightening pass, not a Phase C item).

## Files
| File | Change |
|---|---|
| app/Http/Controllers/Panel/LandingController.php | staff carve-out now setting-gated (v3_all_roles, default ON) on /panel AND /panel/m |
| app/Capability/Process/CashbookCapability.php | permission → menu:cash |
| app/Capability/Registry/CoreCapabilityProvider.php | cashbook manifest → menu:cash |
| app/Console/Commands/V3StaffCommand.php | NEW — preview + flip + instant rollback |
| tests/Feature/LandingV3Test.php | NEW — 5 tests: default landing, flag-off restore, classic escape, purchaser-cashbook 200, sales-cashbook 403 |
| V3-DEFAULT-MIGRATION-PLAN.md | Phase C marked shipped |

## Deploy
1. Upload → EasyPanel **Rebuild**.
2. `php artisan test --filter='Comms|Architect|ChatsWorkspace|CommunicationWorkspace|LandingV3'`
3. `php artisan v3:staff 1 --dry` → eyeball both role lines → done (flag defaults ON).
4. Tell Jayesh & Asish: same login, new screen; "Open classic panel" in the account
   menu still works if they're ever stuck.

## What's next (Phase D — after ~1 week of staff soak)
/panel/* page routes → 302 to their v3 equivalents (papi stays — v3 uses it),
?classic=1 survives one quarter, then resources/panel is archived. Say the word and
I'll build the redirect map.

All architecture guards re-simulated green on this tree (R3, R4, D1–D6).
