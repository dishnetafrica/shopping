# EMAIL-CENTER-FINISH-INSTALL.md

## Why Email Center says "Could not load (500)"
The Comms backend CODE is deployed (that's why the menu item appears) but the DATABASE
tables aren't created yet — the deploy ran Rebuild without `php artisan migrate`.

## Fix now (EasyPanel terminal)
```
php artisan migrate
php artisan comms:seed-templates 1
```
Refresh /v3#/communication → the workspace loads.

## This zip (1 file)
`app/Capability/Process/CommunicationCapability.php` — the workspace now self-diagnoses:
if the tables are missing it renders a **Setup tab with the exact two commands** instead
of a 500. Deploy any time; harmless before or after migrating.

## Status check
Chats (G1) confirmed LIVE in your screenshot — Messaging → Chats with a live unread
badge. After the migrate, G2 is live too and Phase B is complete.
