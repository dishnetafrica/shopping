# SEND-MEDIA-DEPLOY.md — sending FROM the web: photo · document · location

The composer now has three attach buttons (visible whenever you hold the chat):

| Button | What it does |
|---|---|
| 📎 | **Photo** + caption (as before) |
| 📄 | **Document** — PDF, Word, Excel, CSV up to 25MB. Pick a file, name it as the customer will see it ("pricelist.pdf"), optional caption. The web form supplies a base64 data-URL; the server strips it and hands Evolution raw base64 (pinned by test). |
| 📍 | **Location** — one tap asks the browser for YOUR current position → confirm → sent (perfect for Asish: "I'm here, come out"). If location permission is denied — or you want to send the SHOP's pin — the manual form opens instead: lat/lng/place name, name prefilled with your shop. |

Everything follows the established rules: sending by hand takes the chat over from
the bot; every send lands in the transcript (locations with their maps-ready pin,
documents as "📄 name — caption"); gateway calls guarded with method_exists; the
frozen manager API untouched (sendLocation added to the gateway with the same
documented additive pattern sendDocument already uses).

## Files (6)
EvolutionGateway (+sendLocation, additive) · PanelApiController (send-document with
data-URL strip + send-location) · routes (2) · ChatCapability (send_file +
send_location actions, shop-name prefill) · shell.blade.php (📄 + 📍 buttons,
geolocation quick-send with form fallback) · ChatsWorkspaceTest (+2)

## Deploy
Over the web-push stack (shell supersedes) → Rebuild →
`php artisan test --filter=ChatsWorkspace` → open a chat you hold → 📍 → Allow →
the customer receives a real WhatsApp location pin.

Note: browser geolocation requires HTTPS — mycloudbss.com already is.
