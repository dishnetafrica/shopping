# Win World MES — One-click Workspace (navigation)

The single entry point that wires every screen together. **Open `/panel/winworld` and click — no URLs to type.**

## What it is
A launcher page with a side-nav. Each tool opens **in place** (loaded in the workspace), so you never
leave the page. One new page + one route — nothing else changes, no edits to the existing screens.

## The nav
- **Home** — overview with the 1→5 order flow (Sell → Specify → Plan → Make → Improve), click any step to jump.
- **Sell side:** Sales orders · Exceptions
- **Make side:** Order indents · Planning · Production (floor)
- **See:** OEE Dashboard
- **Setup & people:** Masters (opens the `/app` panel) · Staff training

Works on mobile (☰ menu). Remembers the last tool in the URL hash.

## Install
1. Deploy `resources/panel/winworld-hub.html`, `resources/panel/training.html`, and `WinworldHubController.php`.
2. Append routes from `winworld-routes-append-hub.txt`.
3. `php artisan config:cache`.
4. Log in at `/app`, then go to **`/panel/winworld`** — bookmark it. That's the home base.

## Notes
- Masters opens the Filament `/app` panel in a new tab (it's the rich CRUD UI, not a simple page).
- The OIF print form is reached from an indent (it needs a specific indent id), so it's not a top-nav item.
- Requires all prior batches deployed (the screens it links to).
