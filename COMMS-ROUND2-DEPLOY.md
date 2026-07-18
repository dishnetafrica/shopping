# COMMS-ROUND2-DEPLOY.md — Email Center round 2: rich templates + audiences

Closes the two v1 scope notes. Zero shell changes — everything rides existing modal
machinery (textarea fields + the chooser, both already in the shell).

## 1. Rich template editing (the "prefilled body" gap)
**"Edit a template"** opens a picker of every installed template (key · channel ·
locale, subject preview). Tap one → the form opens **fully prefilled**: name, subject,
text body and HTML body in real textareas, active toggle. Saves through the same
templates/save upsert. "New template" also upgraded: channel picker + textarea bodies.

## 2. Campaign audiences (the "segments" gap)
**"New campaign"** now asks for the audience: All customers · Tag · City ·
Ordered within N days · **Winback** (not ordered within N days) — translated by a thin
`save-simple` adapter into the exact segment map CampaignService already understands.
**"Estimate an audience"** answers "how many would this reach?" as a toast
("Audience ≈ 214 customers") before you commit — via `estimate-simple`, wrapping the
existing estimate endpoint. The Campaigns tab gains an **Audience column** so every
campaign shows who it targets at a glance.

## Files (5)
EmailCenterController (3 thin adapters: segmentFromSimple, campaignSaveSimple,
campaignEstimateSimple — all DELEGATE, no logic duplicated) · routes/web.php (2 routes)
· CommunicationCapability (chooser + forms + audience column) ·
CommunicationWorkspaceTest (+2 tests: prefilled chooser pinned, segment translation +
estimate round-trip) · this doc

## Deploy
After the chat-bubbles zip. Rebuild →
`php artisan test --filter=CommunicationWorkspace` → Email Center → try
"Estimate an audience" with Winback 30 days — that number is your first campaign.
