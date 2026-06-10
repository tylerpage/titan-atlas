# StackAdapt connector

Internal reference for the Titan StackAdapt programmatic connector.

## Overview

- **API:** StackAdapt GraphQL Public API (`https://api.stackadapt.com/graphql`)
- **Auth:** Bearer token using a dedicated GraphQL API key
- **Scope:** One StackAdapt advertiser per connection
- **Mode:** Read-only delivery and insight reporting (no mutations)

The legacy REST API is optional fallback only (`TITAN_STACKADAPT_USE_REST_FALLBACK=true`) during migration. New sync work uses GraphQL exclusively.

## Credentials

| Key | Required | Notes |
|-----|----------|-------|
| `graphql_api_key` | Yes | Primary auth for all sync streams |
| `advertiser_id` | Yes (on save) | Selected from advertiser list after test connection |
| `rest_api_key` | No | Legacy fallback when GraphQL auth fails |

## Ingestion streams

| Stream | GraphQL source | `resource_type` |
|--------|----------------|-----------------|
| Advertiser daily | `advertiserDelivery` | `spend_daily` |
| Campaign daily | `campaignDelivery` | `campaign_daily` |
| Channel daily | `campaignDelivery` aggregated by `channelType` | `channel_daily` |
| Geo insight | `campaignInsight` (`COUNTRY`, `REGION`, `DATE`) | `insight_geo_daily` |
| Domain insight | `campaignInsight` (`APP`, `DATE`) | `insight_domain_daily` |
| Device insight | `campaignInsight` (`DEVICE_TYPE`, `DATE`) | `insight_device_daily` |

Sync chunks by date (`chunk_days: 1` default). Campaign delivery paginates across fetch calls; channel daily aggregates all pages within a date chunk before writing rows.

## Core payload metrics

- `cost`, `impressions`, `clicks`, `ctr`
- `conversions`, `conversions_value`, `roas`
- `secondary_conversions`, `engagement_rate`
- `video_starts`, `video_completions`, `audio_starts`, `audio_completions` (campaign/channel rows)

Non-aggregatable metrics such as reach and frequency are excluded from v1.

## Dashboard

Client dashboards render `StackAdaptDashboardPanel` when the selected connection type is `stackadapt`:

- KPI row: spend, impressions, clicks, CTR, conversions, ROAS
- Spend over time with prior-year overlay
- Channel mix bar chart
- Conversion revenue + secondary conversions
- Top campaigns table
- Insight panels: top geos, top domains, device split

## Configuration

See `config/titan.php` → `stackadapt` and `.env.example` for:

- `TITAN_STACKADAPT_BACKFILL_MONTHS` (default 16)
- `TITAN_STACKADAPT_INCREMENTAL_DAYS` (default 5)
- `TITAN_STACKADAPT_DATA_LAG_DAYS` (default 1)
- `TITAN_STACKADAPT_TOP_CAMPAIGNS_LIMIT` (default 25)
- `TITAN_STACKADAPT_TOP_CHANNELS_LIMIT` (default 10)

## Admin setup

1. Add connection → choose **StackAdapt**
2. Paste GraphQL API key
3. Click **Test connection** to list advertisers
4. Select one advertiser
5. Save — backfill starts automatically

## Tests

```bash
php artisan test --filter=StackAdapt
```
