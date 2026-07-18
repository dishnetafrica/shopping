# WAWEB-DEPLOY.md — WhatsApp Web, inside CloudBSS

## What this is
Open **Chats** in /v3 and you get the WhatsApp Web layout: conversations on the left
(avatar, name, last message, time, green unread pill, Bot/Agent chip, live search),
the **thread on the right** — bubbles, photos inline, voice notes with a play button,
an always-there composer with 📎 photo attach, and Take over / Hand back in the header.
Click a conversation → thread opens instantly in-pane, URL updates (#/chats/{id}) so
links share. Mobile: list ⇄ thread with a back arrow, exactly like WhatsApp.

Combined with the attention engine (badges, title, notifications, beep) this is the
full answer to "stop checking WhatsApp": **who wrote, what they sent — including
pictures and voice — and your reply, all in one screen that pings you.**

## How media works (nothing stored on your server)
- **Inbound**: the webhook already receives each message's media key + mime; it now
  records them on the transcript row (purely additive — bot flow byte-identical).
  The thread fetches media ON DEMAND through `/papi/chats/media` (auth'd), which asks
  the gateway to decrypt/relay. WhatsApp's servers keep the bytes; you keep a key.
- **Outbound**: 📎 opens the photo picker (the shell's existing uploader) → caption →
  `/papi/chats/send-media` → gateway `sendImage` → logged with the photo in the
  transcript. Sending a photo takes the chat over from the bot, same rule as text.
- History note: media recorded from NOW on. Older messages predate the meta and show
  as text — that's expected, not a bug.

## Files (7)
| File | Change |
|---|---|
| app/Jobs/ProcessIncomingMessage.php | inbound log now carries media meta (additive; flow untouched) |
| app/Http/Controllers/Panel/PanelApiController.php | `chatMedia` (on-demand stream by key) + `chatSendMedia` (photo out + takeover + log) |
| routes/web.php | 2 routes: chats/media (GET), chats/send-media (POST) |
| app/Capability/Object/ChatCapability.php | rich `meta.msgs` transcript (media→src) + **Send photo** action |
| resources/views/shell.blade.php | the split-pane mode: route hook + waweb renderer + CSS (node-validated) |
| tests/…/ChatsWorkspaceTest.php | +2: media resolves to the stream endpoint & photo action pinned; send-media sends→logs→takes over (gateway container-faked, frozen API untouched) |

## Deploy
After chats-attention (this shell supersedes it — contains bubbles + attention + this).
Rebuild → `php artisan test --filter=ChatsWorkspace` → /v3 → Chats.
First real test: have someone WhatsApp you a photo — it appears in the thread; 📎 one back.

## Deliberately NOT claimed (honesty)
Blue ticks / typing indicators (not in our message log), video playback (files link
out), and push with the browser fully closed (PWA service worker — say the word if
staff phones should buzz). Everything else WhatsApp Web does daily is here.
