# CHATS-ATTENTION-DEPLOY.md — CloudBSS pings YOU (stop checking WhatsApp)

## The two things your screenshot showed
1. Rows were raw phone numbers — you couldn't tell who's who without opening WhatsApp.
2. CloudBSS never notified — WhatsApp pings, this waited to be opened.

## 1. Names, resolved (server-side)
Contact resolution is now: CustomerProfile name → **the latest ORDER that phone ever
placed** → the number. Most of your 350 conversations belong to people who have
ordered — they now show as "Mary Akech", number underneath. Same fallback in the
thread header. Pinned by test. (Also run **Sync contacts** on the Chats screen once —
it pulls WhatsApp names into profiles for the rest.)

## 2. The attention engine (shell)
- **Live badges**: catalog polled every 45s — the sidebar unread count moves on its own.
- **Tab title**: "(3) CloudBSS" — visible from any other tab.
- **Desktop notification** when a NEW message arrives while the tab is hidden —
  "3 conversations need a reply", click = jumps to Chats. (The browser asks permission
  once, on your first click after deploy — say Allow.)
- **Toast + beep** when you're in the app but on another screen.
- **Live thread**: while ON Chats/a conversation, the view refreshes every 20s —
  but NEVER while a modal is open or while you're typing a reply (guarded).

Net effect: keep one CloudBSS tab open (or pinned) and WhatsApp Web becomes optional —
the unread number, the who, the thread, and the reply box are all here and they come
to you.

## Honest limits (so expectations are right)
- Notifications need the tab open somewhere (pin it). True push-when-browser-closed =
  a PWA service worker — a future step if the team wants phones buzzing.
- Media (photos/voice notes) still shows as text placeholders in the thread; viewing
  media is the one remaining reason to open WhatsApp. Say the word and that's the next
  build (media URLs exist in the message log).

## Files (4)
ChatListCapability + ChatCapability (name resolver) · shell.blade.php (attention
engine, one appended IIFE — node-validated) · ChatsWorkspaceTest (+1 name-fallback test)

## Deploy
After comms-round2 (this shell supersedes chat-bubbles'). Rebuild →
`php artisan test --filter=ChatsWorkspace` → open /v3, click anywhere once (permission
prompt), then send yourself a WhatsApp from another phone and watch the badge move.
