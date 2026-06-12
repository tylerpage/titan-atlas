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

Created by `php artisan migrate --seed` locally. For production (e.g. Laravel Cloud), use `titan:create-admin` instead of the full demo seeder.

## Production admin user

Create or update a platform admin without seeding demo data:

```bash
php artisan titan:create-admin \
  --email=you@example.com \
  --name="Your Name" \
  --password="your-strong-password"
```

Or set env vars and run with no flags (handy on Laravel Cloud):

```env
TITAN_ADMIN_EMAIL=you@example.com
TITAN_ADMIN_NAME=Your Name
TITAN_ADMIN_PASSWORD=your-strong-password
```

```bash
php artisan titan:create-admin
```

On Laravel Cloud: **Commands** tab or `cloud command:run "php artisan titan:create-admin"`. Set the `TITAN_ADMIN_*` vars in the environment first so the password is not passed on the command line. Re-running updates name/role; omit `TITAN_ADMIN_PASSWORD` to leave the existing password unchanged.

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

- `ai` — TitanAI reporting chat and connector builder (LLM jobs, 120–180s runtime)
- `ingestion` — fetch data from external APIs
- `transform` — normalize raw payloads into metric snapshots
- `cache` — reserved for dashboard cache warming

### Local development

`composer dev` and `composer dev:share` run a single listener on all queues:

```bash
php artisan queue:listen --queue=ai,ingestion,transform,default --tries=1 --timeout=0 --memory=512
```

That is fine locally. **Do not mirror this in production** — long ingestion jobs can block AI chat.

### Laravel Cloud production workers

Run **separate worker processes** per queue family:

**AI worker** (reporting + connector builder):

```bash
php artisan queue:work --queue=ai --timeout=210 --memory=512 --tries=2
```

| Setting | Value | Why |
|---------|-------|-----|
| `--queue` | `ai` only | Prevents ingestion/transform from starving chat |
| `--timeout` | `210` | Must exceed job timeouts (reporting 120s, builder 180s) |
| `--tries` | `2` | Matches `GenerateReportResponseJob` |
| Processes | `2–4` | Parallel chat sessions; scale when queue depth stays > 0 |

**Ingestion / transform worker** (separate process):

```bash
php artisan queue:work --queue=ingestion,transform --timeout=60 --memory=512 --tries=3
```

### Required environment variables

```env
TITAN_QUEUE_AI=ai
DB_QUEUE_RETRY_AFTER=210
TITAN_AI_RESPONSE_TIMEOUT=120
TITAN_CONNECTOR_BUILDER_RESPONSE_TIMEOUT=180
```

`DB_QUEUE_RETRY_AFTER` must be **greater than** the longest AI job timeout. The default of 90 seconds is too low and can release jobs back to the queue while they are still running.

### Observability

```bash
php artisan titan:ai-queue-status   # pending AI jobs, oldest job age, retry_after check
php artisan titan:ai-stats          # session durations (includes queue wait) + traces
php artisan titan:ai-trace {id} --flow=reporting
```

**Smoke test after worker changes:** send one chat message on an idle system, then run `titan:ai-trace {sessionId}`. Queue wait should be under ~500ms when no other jobs are pending.

## Tests

```bash
php artisan test
```
