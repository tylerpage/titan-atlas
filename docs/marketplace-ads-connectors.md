# Marketplace ad connectors (scaffold)

Native paid media connectors for Amazon Ads, Walmart Connect, and eBay Advertising. These follow the same ingestion and dashboard patterns as Google Ads and Reddit Ads.

## Status

Scaffolded and wired into admin connections, sync, dashboards, metrics, and TitanAI paid media tools. API clients use direct REST endpoints with configurable base URLs. **Production OAuth flows and vendor-specific report polling are not implemented yet** — paste access tokens manually until OAuth is added.

## Connectors

| Connector | Enum | Credential account field |
|-----------|------|--------------------------|
| Amazon Ads | `amazon_ads` | `profile_id` |
| Walmart Connect | `walmart_connect` | `advertiser_id` |
| eBay Advertising | `ebay_ads` | `account_id` |

## Sync streams

| Stream | Notes |
|--------|-------|
| `spend_daily` | Account-level daily spend, impressions, clicks, conversions, conversion value |
| `campaign_daily` | Campaign breakdown for ranking and optimization |

## Dashboard

All three reuse the Google Ads dashboard panel (spend, impressions, clicks, CTR, conversion value, campaign table).

## Platform configuration

See `config/titan.php` and `.env.example`:

- **Amazon:** `AMAZON_ADS_CLIENT_ID`, `AMAZON_ADS_CLIENT_SECRET`, `TITAN_AMAZON_ADS_BASE_URL`
- **Walmart:** `TITAN_WALMART_CONNECT_BASE_URL` (partner-approved API access required)
- **eBay:** `TITAN_EBAY_ADS_BASE_URL`, `TITAN_EBAY_ADS_MARKETPLACE_ID`

## Not yet implemented

- OAuth connect buttons (Login with Amazon, Walmart, eBay)
- Amazon async report download workflow (real SP/reporting API)
- Placement/ad-type breakdown streams
- Cross-platform rollup dashboard view
