# CHAT-BUBBLES-DEPLOY.md — Chats becomes a real chat

## What changed
Opening a conversation in /v3 now renders a **WhatsApp-style thread** instead of the
generic timeline: customer bubbles left, yours right (bot replies right but dashed —
you always see who answered), timestamps per bubble, auto-scrolled to the newest, and
an **inline composer** — type, hit Enter, sent. No more Reply modal for the common
case (the Reply quick-action stays as fallback; Take over / Hand back unchanged).

## How it works (why it's safe)
- The shell gains ONE renderer branch, driven by a `meta.thread` flag on the payload —
  every other workspace's timeline renders exactly as before.
- The composer doesn't invent a write path: it reads the payload's own `reply` action
  and posts through the same proven `/papi/chats/send` (auto agent-takeover included).
  No reply action in the payload → no composer renders.
- Full script re-validated with node after the patch; PHP brace-checked; the thread
  flag pinned by test.

## Files (4)
resources/views/shell.blade.php (CSS + renderer branch + sendThread helper) ·
app/Capability/Object/ChatCapability.php (meta.thread flag) ·
tests/Feature/Capability/ChatsWorkspaceTest.php (flag pinned) · this doc

## Deploy
After the scheduled-v3 zip (this shell supersedes that one's). Rebuild →
`php artisan test --filter=ChatsWorkspace` → open any conversation in /v3.
