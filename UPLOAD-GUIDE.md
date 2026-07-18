# UPLOAD-GUIDE.md — the fix, folder by folder (READ THIS ONE FIRST)

## Why the panel died
GitHub's web upload silently drops files whose target FOLDER doesn't exist. The V4
zip introduced two brand-new folders — `app/Services/Relationship/` and new files in
`app/Capability/Process/` — and the evidence (only plugin menus survived; every core
workspace call 500) says CoreCapabilityProvider crashed referencing a class whose
file never landed.

## The fix in this zip — TWO layers
1. **Hardened so this class of failure is now impossible:**
   - the registry SKIPS the followups registration if its files are absent
     (menu item missing ≠ panel dead)
   - the Briefing's AI-desk counts degrade to 0 if the table isn't migrated yet
   - the Follow-ups page shows "run php artisan migrate" instead of 500ing
2. **Every current file, complete** (v4 train + shop-identity, 33 files) — upload
   them ALL again; identical files overwrite harmlessly.

## HOW to upload on GitHub (the part that went wrong — follow exactly)
GitHub web upload PRESERVES folder structure when you DRAG THE FOLDERS, not loose
files. Safest method:
1. Extract this zip on your Mac → you get `app/`, `database/`, `routes/`, `tests/`
2. GitHub repo ROOT → **Add file → Upload files**
3. **Drag the four extracted FOLDERS** (`app`, `database`, `routes`, `tests`)
   from Finder into the drop area — GitHub shows the full paths list; CONFIRM you
   can see `app/Services/Relationship/RelationshipExtractor.php` and
   `app/Capability/Process/FollowupsCapability.php` in that list before committing
4. Commit → EasyPanel **Rebuild**

## After Rebuild (in EasyPanel → app → Terminal — NOT the browser console 🙂)
```
php artisan migrate
php artisan test --filter='RelationshipMemory|FollowupEngine|PlanningEscalation|Attribution|ShopAddressFaq'
bash verify-v4.sh        # optional but recommended — should be all ✅ now
```
Then hard-refresh /v3 → full side menu returns, chats load.
Then the flags + kick commands from V4-COMBINED-DEPLOY.md (also in this zip), and
the shop:configure one-liner from SHOP-IDENTITY section below.

## One-liner reminders
```
php artisan shop:configure 17 --address="A29 & A31, FREEDOM SQUARE CENTRE\nKonyo Konyo Market, Juba" --website="https://familyshoppers.net"
```
And still outstanding from earlier: the two push files into `public/`
(public/sw.js + public/v3-manifest.webmanifest — the 404 in every console
screenshot today).
