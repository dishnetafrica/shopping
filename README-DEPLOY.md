# CloudBSS — no Filament for staff; the mobile panel is the app

Push these files (repo-relative paths, no wrapper folder), then:
`php artisan optimize:clear` AND `php artisan route:clear && php artisan filament:cache-components`
(or just `optimize:clear`). No migration.

## What changed so staff never see Filament
- app/Filament/Auth/OtpLogin.php          — after sign-in (WhatsApp OR email) -> redirect to /panel/m
- app/Filament/Pages/Dashboard.php (NEW)  — visiting /app redirects to /panel/m (no dashboard)
- app/Providers/Filament/AppPanelProvider.php — uses the redirecting Dashboard

## The mobile app + staff tools (from before, included so one push does everything)
- app/Http/Controllers/Panel/SellerPanelController.php — serves /panel/m
- app/Http/Controllers/Panel/PwaController.php          — manifest ?app=m -> Add to Home Screen opens /panel/m
- app/Http/Controllers/Panel/PanelApiController.php     — staff Edit (staffUpdate) + POS save-order/cashbook
- routes/web.php                                        — /panel/m, POST /papi/staff/update
- resources/panel/mobile.html                           — the Orders/Chats/POS app
- resources/panel/staff.html                            — staff page with Edit

## Result
- Log in at /app/login (email+password or WhatsApp code) -> lands on /panel/m (the simple app).
- Anyone who opens /app is bounced to /panel/m. The Filament dashboard is gone for staff.
- The operator console at /admin is unchanged (that's your platform tool, different audience).

## Fix the home-screen icon that currently opens the dashboard
Delete the old icon. In Safari open /panel/m, then Share -> Add to Home Screen. The new icon
opens the app directly. (After this deploy, even the old icon's /app would just bounce to /panel/m.)

## Smoke test after deploy (I cannot run your stack here)
1. /app/login -> sign in -> you land on /panel/m (NOT the dashboard).
2. Type /app directly -> it redirects to /panel/m.
3. /panel/m loads orders; /panel/staff Edit still works.
If /app shows a Livewire error instead of redirecting, tell me — the dashboard-redirect can be
done a second way (a plain /app route redirect) if your Filament version dislikes redirect-in-mount.
