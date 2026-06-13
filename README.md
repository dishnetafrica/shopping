# ShopBot — multi-tenant WhatsApp ordering + business admin (SaaS)

One Laravel app that runs **many businesses (tenants)**. Each tenant gets:
- their own WhatsApp AI ordering bot (their own number / Evolution instance),
- their own admin panel (orders, products, riders, dispatch, settings),
- isolated data (row-level multi-tenancy on `tenant_id`).

## Architecture (how it fits together)

- **Multi-tenancy:** one shared Postgres DB. Every tenant-owned table has `tenant_id`.
  The `BelongsToTenant` trait auto-filters every query to the active tenant and stamps
  `tenant_id` on insert — so resource/controller code never has to remember to scope.
- **Tenant resolution:**
  - Admin panel → the logged-in staff user's `tenant_id` (`SetTenantFromUser`).
  - Bot → the Evolution instance / number that **received** the message → maps to a tenant.
- **WhatsApp gateway:** `App\Contracts\WhatsAppGateway` with two drivers —
  `EvolutionGateway` (now) and `CloudApiGateway` (official API, later). Swap per tenant
  via `tenant->whatsapp_driver`; nothing else changes.
- **Admin UI:** Filament. Tenant panel at `/app`, operator (super-admin) panel at `/admin`.
- **Bot:** Evolution/Cloud webhook → `WebhookController` → `ProcessIncomingMessage` (queued)
  → `BotBrain` (catalogue lookup now; OpenAI NLU in Phase 2) → reply on the tenant's number.

## What's in this package (Phase 0 — the backbone)
- Migrations: tenants, users(+tenant fields), products, orders, order_items, riders, branches, conversations
- Models + `BelongsToTenant` + `TenantContext`
- WhatsApp gateway (Evolution + Cloud stub) + manager + provider
- Bot webhook + queued job + `BotBrain` + `ProductSearch`
- Filament panels (`/app`, `/admin`) + example `ProductResource`
- Docker image (nginx + php-fpm + queue + scheduler) + docker-compose + CI

## First-time setup (turns these files into a runnable app)
```bash
# 1. fresh Laravel 11 skeleton
composer create-project laravel/laravel shopbot && cd shopbot

# 2. copy THIS package's files over the skeleton (app/, config/, database/, routes/,
#    bootstrap/, docker/, Dockerfile, docker-compose.yml, composer.json, .env.example)

# 3. install deps + Filament
composer install
composer require filament/filament:"^3.2" laravel/horizon predis/predis openai-php/laravel
php artisan filament:install --panels

# 4. env + key
cp .env.example .env && php artisan key:generate
#   set DB (pgsql), REDIS, EVOLUTION_*, OPENAI_* in .env

# 5. migrate + seed (creates operator + demo "Family Shoppers" tenant)
php artisan migrate --seed --seeder=Database\\Seeders\\InitialSeeder

# 6. generate the remaining admin screens from the models (Filament does the CRUD):
# Product, Order, Rider resources + the operator Tenant resource + a tenant Settings
# page are ALREADY included in this package. Optional extra:
php artisan make:filament-resource Branch --generate
```
Log in: operator → `/admin`, tenant staff → `/app` (credentials from the seeder / your `.env`).

## Deploy on EasyPanel (recommended — Docker under a GUI)
1. Push this project to a Git repo (GitHub).
2. EasyPanel → **Create service → App → from GitHub repo** → it builds the `Dockerfile`.
3. Add EasyPanel **Postgres** and **Redis** services; set `DB_HOST` / `REDIS_HOST` to their
   service names in the app's Environment tab (plus the rest of `.env`).
4. Add your domain (e.g. `app.yourdomain.com` and `*.yourdomain.com` for tenant subdomains) →
   EasyPanel auto-issues SSL.
5. Deploy. The image's entrypoint auto-runs migrations + seed + caches on boot. The queue
   worker and scheduler run **inside the same container** (supervisor), so nothing extra to set up.

### Each tenant's WhatsApp webhook
Point the tenant's Evolution instance webhook to:
```
https://app.yourdomain.com/api/webhook/whatsapp/evolution
```
(For a tenant on the official API later: `.../whatsapp/cloud`.)

## Self-host with Docker (any server)
```bash
cp .env.example .env   # set APP_KEY via: docker compose run --rm app php artisan key:generate
docker compose up -d --build
```
Brings up app + Postgres + Redis. App on `:8080`.

## Roadmap
- **Phase 1:** finish Filament resources (orders/dispatch/riders/settings), tenant onboarding wizard, WhatsApp QR-connect.
- **Phase 2:** OpenAI NLU in `BotBrain` (intent + cart + checkout → Order), per-tenant catalogue search via Postgres FTS / Meilisearch.
- **Phase 3:** operator billing/plans, Cloud API driver for high-volume tenants.

> Working title "ShopBot" — rename freely (`.env` `APP_NAME`, Filament `brandName`).
