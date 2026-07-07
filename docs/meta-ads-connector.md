# Meta Ads connector

Native connector for Meta (Facebook/Instagram) Ads using the [Marketing API Insights](https://developers.facebook.com/docs/marketing-api/insights) endpoint.

## Setup

1. Create a Meta app with Marketing API access.
2. Generate an access token with `ads_read` scope (System User or long-lived user token).
3. In Titan admin, add a **Meta Ads** connection:
   - Paste the access token
   - Click **Test connection** to list ad accounts
   - Select one ad account per connection

## Sync streams

| Stream | API | Notes |
|--------|-----|-------|
| Account spend | `/{ad-account-id}/insights` level=account, time_increment=1 | Daily spend, reach, purchases, purchase value |
| Campaign daily | level=campaign | Campaign name, objective, zero-spend rows excluded |
| Placement daily | breakdowns=publisher_platform, platform_position | Spend by placement |
| Device daily | breakdowns=device_platform | Spend by device |

Purchase metrics map from Meta `actions` / `action_values` with action types: `purchase`, `omni_purchase`, `offsite_conversion.fb_pixel_purchase`, `onsite_conversion.purchase`.

## Dashboard

The Meta Ads panel answers:

1. **How much did we spend?** — Spend KPI + spend over time
2. **What did we get for that spend?** — Purchase revenue, ROAS, purchases, CPA
3. **What's performing best?** — Top campaigns (sortable by spend, revenue, ROAS, purchases)
4. **Where should we optimize?** — Lowest ROAS campaigns, spend by objective/placement/device

Also includes:

- Period-over-period trend indicators on KPIs
- Revenue vs spend chart
- ROAS over time
- Sortable/searchable campaign table with CSV export

## Configuration

See `config/titan.php` → `meta_ads` and `.env.example` for backfill window, chunk size, and pagination limits.

## Not yet implemented

- Meta OAuth (token is pasted manually today)
- Campaign status / objective / device filters on the dashboard
- Ad set / ad drill-down views
- Weekly/monthly spend granularity toggle
- PDF/PNG export
