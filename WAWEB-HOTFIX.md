# WAWEB-HOTFIX.md — composer visible, ghost bubbles gone

Your screenshot, diagnosed — both bugs mine:

1. **Composer invisible / page scrolled past the pane.** CSS grid children default to
   min-height:auto, so the thread grew taller than the fixed pane and pushed the
   composer below the fold. Fix: `min-height:0` on both panes — the thread now scrolls
   INSIDE its pane and the type-a-message bar stays pinned at the bottom, always.
2. **Big empty gaps between bubbles.** Old messages with blank bodies (media received
   before media-logging existed, plus system rows) rendered as invisible bubbles.
   Fix: empty rows are skipped entirely; legacy "[image]/[voice]"-style placeholders
   show a small italic "📎 image (sent before media logging)"; system lines render as
   small centred notes, not bubbles.

Also confirmed while diagnosing: replies you send from your own phone ARE logged
correctly as outbound (the fromMe→'out human' path) — any old reply sitting on the
left predates that logic; new ones will sit right where they belong.

## File (1)
resources/views/shell.blade.php — deploy over the whatsapp-web-mode zip's copy →
Rebuild → hard-refresh /v3 (Cmd+Shift+R) → open the same chat: composer at the bottom,
gaps gone.
