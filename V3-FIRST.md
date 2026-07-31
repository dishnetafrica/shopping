# V3-FIRST — the development rule (effective 31 Jul 2026)

**Decision (Bhavin):** the classic panel is being retired. V3 (`/v3`, `resources/views/shell.blade.php`)
is the ONE front door. **Every new screen, button, or action ships inside V3.** No new
classic-panel screens, no new links into the classic panel chrome.

## The rule

1. **New feature → V3.** Either as a native workspace (capability payload rendered by the shell)
   or as a full-screen page embedded in the shell. Never as a new classic-panel menu item.
2. **Classic panel (`/panel/*` pages + `config/navigation.php`) is FROZEN for new work.**
   Bug fixes to existing classic screens are allowed. New entries in `config/navigation.php`
   are not (the file's header says so too).
3. **Full-screen operational pages are fine — but V3 is their front door.** A till, a stock-count
   page, a training page may live as a dedicated page (fast, self-contained), as long as:
   - the V3 shell has a menu entry / button that opens it (iframe embed, house pattern below);
   - the page supports `?embed=1` → hides its own back-button chrome (V3 provides context);
   - the page never links back into classic-panel navigation.
4. **Every drop that changes a screen updates the Training Center card**
   (`resources/panel/help-center.html`) for that screen in the same drop.

## Where the shell lives (since the v3.7 split)

`resources/views/shell.blade.php` is now ~75 lines of HTML only. The code moved to static
assets — **no build step, deploy the files together**:

- `public/assets/v3/shell.css` — all V3 styles
- `public/assets/v3/shell.js`  — all V3 JavaScript (router, nav, screens)
- `resources/v3/shell.css` + `shell.js` — IDENTICAL copies (self-healing, since v3.9.1): if the
  public/ copy is missing on a deploy, the `/assets/v3/{file}` fallback route in routes/web.php
  serves the resources/ copy, so a half-upload can no longer blank V3 (31 Jul incident).

Cache-busting is automatic (`?v=filemtime` of whichever copy exists). ⚠️ When a drop touches
the shell, update BOTH copies of each asset (public/ + resources/) — they must stay identical.
Longer-term direction: when a module (customers, tasks, finance…) gets its next real feature,
its functions move out of shell.js into their own static file loaded after it — the shell
shrinks opportunistically, never in a big-bang rewrite.

## How to add a screen to V3 (the house pattern)

All JS edit points below are now in `public/assets/v3/shell.js` (markup, if any, in the blade):

1. `LABELS`  → add `mykey:'My Screen'`
2. `GROUPS`  → add `'mykey'` to the right group array (order = menu order)
3. `READY`   → add `'mykey'`
4. `P` icons → add an svg path for `mykey`
5. Gating    → if the key has NO server capability behind it, add `|| k==='mykey'` to the
   `has` closure in `renderNav()` AND to `hasScreen()` (mobile rows), like `settings`/`pos`.
   If it IS permission-sensitive, gate it on a real capability key instead (see how `pnl`
   rides on `finance`).
6. `route()` → add a branch. For an embedded full-screen page:
   ```js
   if(key==='mykey'){ document.getElementById('view').innerHTML=
     '<iframe title="My Screen" src="/panel/my-page?embed=1" style="width:100%;height:calc(100vh - 96px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
   ```
   For a native workspace: let it fall through to the capability fetch and build the
   capability server-side (see `app/Capability/README.md`).

Existing examples of the embed pattern: `settings`, `payments`, `pnl`, `books`, `pos`, `guide`.

## Current V3 surface (after v3.6)

- **POS Till**: top-bar green **POS** button (UltimatePOS-style) + Sales → POS Till (`#/pos`).
  Embeds `/panel/pos?embed=1`; ⛶ opens the naked till full-screen for dedicated till stations.
- **Help & Training** (`#/guide`) now opens the Training Center (`/panel/training`).
- Settings / Payments / P&L / Books were already embedded.

## Migration direction (opportunistic, not big-bang)

When a classic screen gets its next real feature, that is the moment it moves behind a V3
entry (embed first, native workspace when it earns it). The classic left menu keeps working
until its screens have all moved; then `/panel` routes stay as deep-link redirects only.
