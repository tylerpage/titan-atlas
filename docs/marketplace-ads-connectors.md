# Marketplace ad connectors

Native paid media connectors for Amazon Ads, Walmart Connect, and eBay Advertising. These follow the same ingestion and dashboard patterns as Meta Ads and Google Ads.

## Status

Implemented with async report polling, expanded sync streams, and Tier 2 retail media dashboards (ROAS, revenue vs spend, campaign tables, dimension breakdowns). OAuth connect buttons are still manual-token only until vendor OAuth is added.

## Connectors

| Connector | Enum | Credential account field |
|-----------|------|--------------------------|
| Amazon Ads | `amazon_ads` | `profile_id` |
| Walmart Connect | `walmart_connect` | `advertiser_id` |
| eBay Advertising | `ebay_ads` | `account_id` |

## Sync streams

| Stream | Amazon | Walmart | eBay |
|--------|--------|---------|------|
| `spend_daily` | Account daily spend (SP+SB+SD) | Account daily | Account daily |
| `campaign_daily` | Sponsored Products campaigns | Campaigns | Campaigns |
| `ad_type_daily` | SP / SB / SD split | — | — |
| `keyword_daily` | Search terms | Keywords | Keywords |
| `ad_product_daily` | ASIN / SKU | — | — |
| `listing_daily` | — | — | Listings |
| `page_type_daily` | — | Page types | — |
| `tactic_daily` | — | Tactics | — |

## API workflow

All three connectors use `App\Support\AsyncReportPoller`:

1. Create async report / snapshot / report task
2. Poll until ready (`titan.reports.poll_max_attempts`, `titan.reports.poll_sleep_ms`)
3. Download JSON (gzip supported) and normalize rows

### Amazon Ads

- Profiles: `GET /v2/profiles`
- Reports: `POST /reporting/reports` (Reporting v3), poll `GET /reporting/reports/{id}`, download from `url`
- Default attribution columns: `purchases14d`, `sales14d`

### eBay Advertising

- Accounts: `GET /ad_account`
- Reports: `POST /ad_report`, poll `GET /ad_report/{id}`, download from `reportHref`
- Default data lag: 1 day (`titan.ebay_ads.data_lag_days`)

### Walmart Connect

- Advertisers: `GET /advertisers`
- Snapshots: `POST /advertisers/{id}/snapshot`, poll `GET /advertisers/{id}/snapshot/{id}`, download from `downloadUrl`

## Dashboard

Amazon, Walmart, and eBay use `RetailMediaDashboardPanel` (Tier 2):

- Spend, attributed revenue, ROAS, purchases/orders, CPA
- Spend / revenue / ROAS time series
- Campaign performance table with CSV export
- Platform breakdown charts (ad types, keywords, listings, ASINs, page types, tactics)

## Platform configuration

See `config/titan.php` and `.env.example`:

- **Amazon:** `AMAZON_ADS_CLIENT_ID`, `AMAZON_ADS_CLIENT_SECRET`, `TITAN_AMAZON_ADS_BASE_URL`
- **Walmart:** `TITAN_WALMART_CONNECT_BASE_URL` (partner-approved API access required)
- **eBay:** `TITAN_EBAY_ADS_BASE_URL`, `TITAN_EBAY_ADS_MARKETPLACE_ID`
- **Polling:** `TITAN_REPORT_POLL_MAX_ATTEMPTS`, `TITAN_REPORT_POLL_SLEEP_MS`

## Not yet implemented

- OAuth connect buttons (Login with Amazon, Walmart, eBay)
- Cross-platform paid media rollup dashboard
- Amazon SB/SD campaign and keyword reports beyond SP defaults

## Testing with access

1. Create a connection in Admin → Connections
2. Paste a valid OAuth access token with advertising scope
3. Select the profile / advertiser / ad account
4. Run a sync and open the client dashboard Data tab

Use vendor sandbox credentials first; production tokens require approved developer apps for each marketplace.
