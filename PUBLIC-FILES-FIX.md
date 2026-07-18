# PUBLIC-FILES-FIX.md — the two missing public/ files (+ "Save"→"Send" polish)

## What your console screenshot showed
`GET /v3-manifest.webmanifest → 404`. Both public/ files from the web-push zip missed
the upload (everything else that zip carried was under app/, easy to miss the two in
public/). Consequences until fixed: no Add-to-Home-Screen (blocks iPhone push) and —
if sw.js is also absent — push silently dead on every device (enrolment fails
quietly by design).

## In this zip
| File | Why |
|---|---|
| public/sw.js | the service worker — push display + tap-to-open-Chats |
| public/v3-manifest.webmanifest | makes /v3 installable (required for iOS push) |
| resources/views/shell.blade.php | tiny polish: form modals can carry a per-action button label |
| app/Capability/Object/ChatCapability.php | Send photo / Send document / Send location modals now say **Send**, not "Save" |

## Deploy
1. Upload all four (the two public/ files are the critical ones) → **Rebuild**
2. Verify in a tab: https://mycloudbss.com/sw.js and
   https://mycloudbss.com/v3-manifest.webmanifest — both must load (no 404)
3. Hard-refresh /v3 → console errors gone → click once → Allow
4. `POST /papi/push/test` → phone buzzes "Push notifications are working 🎉"
5. iPhones: Share → Add to Home Screen → open from the icon once → they buzz too

Then rerun verify-deploy.sh — section 10 should flip to ✅✅ and the train is 30/30.
