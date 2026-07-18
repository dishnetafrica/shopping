# MEDIA-FULL-DEPLOY.md — location, PDF, video, voice: all handled

## Your question, answered per type
| Customer sends | Before | Now |
|---|---|---|
| **Voice note** | key captured (bot transcribes) but thread showed nothing | ▶️ plays inline in the thread |
| **Photo** | ✅ (last build) | ✅ + correct kind even when mime missing (bug fixed) |
| **Location pin** 📍 | parsed for the bot, invisible in the thread | a location card: place name + address + **Open in Google Maps** — exactly what Asish needs for deliveries |
| **Video** | completely dropped | 🎬 plays inline (streamed on demand) |
| **PDF / document** | completely dropped | 📎 shown WITH its filename ("pricelist.pdf") — click opens/downloads |

## How
- The Evolution normalizer now also extracts videoMessage + documentMessage (mime,
  filename, caption) — purely ADDITIVE output keys; every existing consumer and all
  bot behaviour untouched.
- The transcript row records the right kind (from the normalizer's explicit flags —
  this also fixes my earlier mime-less misclassification), plus filename/caption, or
  lat/lng/name for locations.
- All streamable media flows through the same on-demand `/papi/chats/media` relay
  (message-key now json-packed so image/audio/video/document all fetch identically);
  locations need no fetch — the coordinates are the payload.

## From-now note
Same as photos: recorded from deploy time onward. Yesterday's videos/PDFs predate the
meta and stay as text placeholders.

## Files (6)
EvolutionGateway (normalizer, additive) · ProcessIncomingMessage (meta v2 + location)
· ChatCapability (kind passthrough + maps links + json-packed keys) ·
PanelApiController (media endpoint decodes key arrays) · shell.blade.php (video
player + location card + named-file renderer) · ChatsWorkspaceTest (+1: all three
types pinned)

## Deploy
Over the waweb-hotfix stack → Rebuild → `php artisan test --filter=ChatsWorkspace` →
ask someone to send you a location pin — the Maps link in the thread is the proof.
