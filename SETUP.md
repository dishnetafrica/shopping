# ShopBot / CloudBSS — full source

Multi-tenant WhatsApp ordering bot + business panel (Laravel 11 + Filament 3.2, PHP 8.2+).

## What's in this zip
The complete application source: `app/`, `routes/`, `config/`, `database/` (27 migrations + seeder),
`resources/` (Filament views + the Seller-Panel HTML incl. the mobile panel + icons), `tests/`,
`docker/`, `Dockerfile`, `docker-compose.yml`, `composer.json`, `.env.example`, plus the standard
Laravel scaffolding (`artisan`, `public/index.php`, `phpunit.xml`, `.gitignore`, `storage/` tree).
`qa/` holds standalone regression harnesses (pure PHP, run without the framework).

## NOT in this zip (regenerated, never committed)
- `vendor/` — run `composer install`
- `composer.lock` — created by `composer install` (pins versions)
- `.env` — copy from `.env.example` and fill in
- front-end build assets — Filament ships its own; there is no custom Vite build

> Source of truth remains your Git repo. This zip is the current working source assembled from the
> build workspace; treat it as a clean snapshot to diff against or to seed a fresh checkout.

## Brand-new setup
```bash
composer install
cp .env.example .env
php artisan key:generate
# edit .env: DB (Postgres), Redis, Evolution API, Flutterwave/Stripe, OPENAI_API_KEY (optional)
php artisan migrate --seed
php artisan storage:link
php artisan serve            # or use the Docker stack below
```
Queue + scheduler (orders, WhatsApp sends, campaigns):
```bash
php artisan queue:work       # or Horizon: php artisan horizon
php artisan schedule:work
```

## Docker
`docker-compose up -d --build` (see `docker/` for nginx, php.ini, supervisord, entrypoint).

## Key URLs
- `/app/login` — staff login (WhatsApp code or email+password)
- `/panel/m`   — mobile Seller Panel (Orders / Chats / POS) — installable to home screen
- `/panel/staff` — staff & logins (add / edit)
- `/admin`     — operator console (manage tenants)

## Tests
- Framework tests: `php artisan test` (after composer install)
- Standalone bot regression: `php qa/chatnoise.php`, `php qa/faq.php`, `php qa/override.php`, etc.
  (these run the pure Bot services directly — no DB/framework needed).
