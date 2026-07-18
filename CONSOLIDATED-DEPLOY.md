# CONSOLIDATED-DEPLOY.md — everything pending, one upload, zero file-version confusion

This zip = the LATEST state of every file from the four pending zips (Phase C, Phase D,
parity fixes, Help & Training) plus the idempotent brand migration. It supersedes those
four zips — upload THIS one, ignore the others.

## What it contains (one deploy delivers all of this)
- **Phase C**: Jayesh & Asish land on /v3 (desktop + /panel/m) · `v3:staff` command ·
  Cashbook gate fixed to menu:cash
- **Phase D kit**: classic→v3 redirects (33 pages, flag OFF — deploying changes nothing) ·
  `v3:retire` command
- **Parity fixes**: expense recording + table in Finance · Approve/Reject pending costs
  in Purchasing · consignment + scheduled honestly kept classic
- **Help & Training**: the role-aware manual (8 chapters) + nav item + content config
- **Hardening**: idempotent brand migration (the drift that blocked your migrate)

## Deploy (one pass)
1. Upload the zip contents to GitHub (repo root paths preserved) → EasyPanel **Rebuild**
2. Gate:
   `php artisan test --filter='Comms|Architect|ChatsWorkspace|CommunicationWorkspace|LandingV3|ClassicRedirects|ParityFixes|GuideWorkspace'`
3. `php artisan v3:staff 17 --dry` → read both role lines → done (flag defaults ON —
   Jayesh & Asish are on /v3 from their next login; rollback `v3:staff 17 --off`)
4. Check /v3: Overview → **Help & Training** · Purchasing shows **Awaiting approval**
   when staff record costs · Finance has **Record expense**
5. Do NOT run `v3:retire 17` yet — that's after the ~1 week soak.

## Snapshot-drift check (one minute, worth it)
This zip replaces `routes/web.php` and `resources/views/shell.blade.php` in full.
If you committed changes to either file since your last upload to me (July 17 +
tonight's zips), review the GitHub diff before merging. If not — merge freely.

File list is in the zip; every file brace/JS-validated; all architecture guards
simulated green on this exact tree.
