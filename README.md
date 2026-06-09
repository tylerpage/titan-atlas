# Atlas

Multi-tenant analytics dashboard platform for Irish Titan and their clients.

## Architecture

Three layers:

1. **Management** — companies, users, dashboard templates, client dashboards, invitations, impersonation
2. **Ingestion** — connector registry, sync runs, raw payloads (Horizon queues)
3. **Analytics** — transform jobs, dimensional metric snapshots, widget data

## Platform decisions

| Area | Decision |
|------|----------|
| Connectors | Shopify, BigCommerce, Google Ads, Search Console, **GA4**, SEMrush (basic) |
| Commerce attribution | Store-native fields (`source_name`, channel, referring/landing site) |
| ROAS / currency | USD only; configurable attribution window per dashboard |
| Sync schedule | Daily incremental + hourly refresh for today |
| SEMrush v1 | Domain overview + top organic keywords only |
| Users | Clients can belong to multiple companies; dashboard access via assignment |
| Branding | Admin-only logo/colors; "Powered by Irish Titan" always shown |
| Domains | Optional `custom_domain` per dashboard (provision via Laravel Forge) |
| Metrics | Full dimensional rows (`metric_key` + `dimension_hash`) |
| Date ranges | Presets + custom start/end |

## Frontend

Vue 3 + [Inertia.js](https://inertiajs.com/) + [Ziggy](https://github.com/tighten/ziggy) — all UI lives in `resources/js/Pages/`. Laravel serves a single Blade shell (`resources/views/app.blade.php`) and returns Inertia responses from controllers. Use `route()` in Vue components via Ziggy (`@routes` in the shell).

**Development** — run both processes:

```bash
npm run dev          # Vite (hot reload)
php artisan serve    # Laravel
```

Or use the combined dev script: `composer dev`

**Production build:**

```bash
npm run build
```

PHPUnit uses `$this->withoutVite()` so tests do not require a built manifest.

## Getting started

```bash
cd titan
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Optional: run Horizon for background sync/transform jobs.

```bash
php artisan horizon
```

Scheduled syncs (requires cron):

```bash
php artisan schedule:work
# or in production: * * * * * php artisan schedule:run
```

Manual sync:

```bash
php artisan titan:sync-connections --type=incremental
php artisan titan:sync-connections --type=today_hourly
```

## Demo accounts

| Role   | Email              | Password  |
|--------|--------------------|-----------|
| Admin  | admin@titan.test   | password  |
| Client | client@acme.test | password  |

## Routes

- `/login` — authentication
- `/admin/dashboards` — admin overview (admin only)
- `/admin/impersonate/{user}` — view as client (admin only)
- `/dashboards/{dashboard}` — client-facing dashboard

## Connectors

Stub connectors validate credentials and return fetch results. Implement API calls in `app/Ingestion/Connectors/`.

Shopify syncs orders (REST Admin API) and session attribution (ShopifyQL via GraphQL `shopifyqlQuery`, API `2025-10+`). The custom app token needs `read_orders`, `read_all_orders` (for full backfill), and `read_reports` (for sessions by UTM source/medium).

GA4 replaces the session/traffic metrics previously mixed into Search Console. GSC remains for impressions, clicks, queries, and rankings.

## Queues

Configure queue names in `config/titan.php`:

- `ingestion` — fetch data from external APIs
- `transform` — normalize raw payloads into metric snapshots
- `cache` — reserved for dashboard cache warming

## Tests

```bash
php artisan test
```
