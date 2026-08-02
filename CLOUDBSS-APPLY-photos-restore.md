# CloudBSS — bike photos + catalogue fonts restore (one-drag fix)

**What:** the 26 storefront motorcycle/part photos (`public/assets/sfm/`) and 3 catalogue Inter fonts (`public/fonts/inter/`) that were missed when a 120-file cumulative zip hit GitHub's 100-files-per-drag upload limit. No code changes.

**Upload:** extract this zip → in GitHub: Add file → Upload files → drag the extracted `public` FOLDER (the folder itself, so paths are kept) → Commit → Rebuild in EasyPanel.

**Test:** open `eaautopart.com/assets/sfm/f6ddaed4a709.jpg` → the red Boxer photo should load. Then hard-refresh the homepage (Ctrl/Cmd-Shift-R) — hero + Your Motorcycle cards are back.

**Going forward:** cumulative zips are now >100 files — upload them in two drags (code folders first, `public` second) or use the delta zips included with each drop.
