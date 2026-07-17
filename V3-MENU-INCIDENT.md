# V3-MENU-INCIDENT.md — why the sidebar emptied, and the safe path back

## 1. ROLLBACK FIRST (do this before anything else)
GitHub → your repo → **Commits** → the commit where the comms zip files were uploaded
→ **Revert** → EasyPanel **Rebuild**. The menu returns immediately.
Do NOT roll back by uploading another zip — rollback lives in git.

## 2. What actually happened (root cause — my bug)
The comms-v3 zip's sidebar-badge code counts failed emails from the `comm_messages`
table on EVERY shell load. That table is created by the **Email Center backend zip
(cloudbss-email-center-m1-m4.zip) + `php artisan migrate`** — which this server does
not have yet. So:

1. the badge query threw (missing table/class),
2. the counts code re-threw instead of degrading,
3. the catalog endpoint (`/api/v1/workspace`) returned 500,
4. the shell builds the WHOLE sidebar from that catalog → **menu items vanished**.

The v3 engine and your data were never touched — only the catalog request was failing.

## 3. The fix in this zip (3 files, deploy-order-proof)
| File | Hardening |
|---|---|
| app/Capability/Shared/NavCountsCapability.php | **Per-key isolation**: every badge count wrapped individually — a missing table now omits that ONE badge; every other menu item renders. The re-throw path removed. |
| app/Capability/Http/WorkspaceController.php | Catalog never fails because badges did: counts wrapped, worst case = no badges, full menu. |
| app/Capability/Registry/CoreCapabilityProvider.php | Email Center workspace registers ONLY when the Comms backend classes exist (`class_exists` guard) — the menu never offers a screen that would 500. |

With these three, this class of failure cannot empty the menu again — on any server,
in any deploy order.

## 4. Correct redeploy order (when you're ready to bring G1/G2 back)
1. **Backend first**: cloudbss-email-center-m1-m4.zip → Rebuild → `php artisan migrate`
   → `php artisan comms:seed-templates 1`.
2. **This hotfix zip** (the 3 hardened files).
3. chats-v3-capability.zip (G1).
4. comms-v3-capability.zip (G2) — its NavCounts/CoreCapabilityProvider are superseded
   by this hotfix's versions; upload the hotfix files LAST if uploading together.
5. Gate: `php artisan test --filter='Comms|Architect|ChatsWorkspace|CommunicationWorkspace'`.

## 5. One more caution (snapshot drift)
My zips replace whole files from your July-17 upload (notably `routes/web.php` and
`resources/views/shell.blade.php`). If you committed changes to those files after
July 17, review the diff in GitHub before merging any of my zips so nothing newer gets
clobbered. If the repo hasn't changed since, this doesn't affect you.
