# Google Ads API Access Application — Tool Design Document

**Applicant organization:** Irish Titan  
**Tool name:** Irish Titan Analytics (Atlas / Titan platform)  
**Application type:** Basic or Standard access (read-only reporting)  
**Document version:** 1.0  
**Date:** 2026-06-09  
**Primary contact:** Tyler Page tyler.page@irishtitan.com

---

## 1. Executive summary

**Irish Titan Analytics** is a B2B analytics and reporting platform operated by **Irish Titan**. It gives marketing agencies and their clients a unified dashboard for commerce, SEO, analytics, and paid media performance.

The Google Ads integration is **read-only**. Authorized users connect a Google account via OAuth, select an Ads customer (including MCC client accounts), and the platform **syncs historical and incremental performance metrics** for display in client-facing dashboards. The tool does **not** create, edit, or manage campaigns, ads, budgets, bids, audiences, or conversions.

We request **Standard** (or **Basic**) developer token access so production client accounts can be queried. Our integration uses the **Google Ads API REST interface** (`googleAds:searchStream`) with GAQL reporting queries only.

---

## 2. Intended use case

### 2.1 Business purpose

Agencies using Irish Titan Analytics need to view Google Ads performance alongside Shopify/BigCommerce revenue, Google Search Console, and GA4 in one place. The Ads connector answers:

- How much was spent in a date range?
- How did impressions, clicks, and CTR trend daily?
- What is total conversion value?
- Which campaigns drove the most spend?

### 2.2 What we do with Google Ads data

| Use | Description |
|-----|-------------|
| **Ingest** | Pull daily account- and campaign-level metrics via scheduled background jobs |
| **Store** | Persist raw rows in our application database (encrypted credentials; deduplicated payloads) |
| **Transform** | Map to internal daily metrics (`ad_spend`, `ads_impressions`, etc.) |
| **Display** | Render summary cards, spend charts, campaign tables, and period comparisons on client dashboards |
| **AI context** | Optional: aggregated metrics may be exposed to an in-app AI assistant for natural-language Q&A over dashboard data |

### 2.3 What we do **not** do

- No campaign, ad group, keyword, or asset creation or modification
- No budget or bid changes
- No conversion action configuration
- No user list or remarketing list management
- No automated optimization or autobidding
- No reselling of raw Google Ads data to third parties

---

## 3. Users of the tool

| User type | Role | Google Ads interaction |
|-----------|------|------------------------|
| **Platform administrators** | Agency staff at Irish Titan | Connect Google OAuth, select Ads customer, test connection, trigger sync |
| **Client dashboard viewers** | End clients (read-only) | View pre-synced metrics only; **no direct Google Ads API access** |

**Estimated scale (initial): 10 clients

Each connection maps to **one Ads customer ID** (optional manager / `login-customer-id` for MCC clients). Access is limited to accounts the connecting Google user can already access in the Google Ads UI.

---

## 4. Google Ads API usage

### 4.1 API version and transport

- **API:** Google Ads API REST
- **Version:** `v21` (configurable via `TITAN_GOOGLE_ADS_API_VERSION`; we follow Google’s version sunset guidance)
- **Base URL:** `https://googleads.googleapis.com/v21`
- **Primary method:** `POST /customers/{customerId}/googleAds:searchStream`
- **Secondary (setup only):** `GET /customers:listAccessibleCustomers`

We do **not** use the legacy SOAP API or mutating services (CampaignService mutations, etc.).

### 4.2 OAuth 2.0

| Item | Value |
|------|--------|
| **Flow** | Authorization code with offline access |
| **Scope** | `https://www.googleapis.com/auth/adwords` |
| **Supplementary scopes** | `userinfo.email`, `userinfo.profile` (display connected account only) |
| **Token storage** | Refresh token encrypted at rest per connection |
| **Access token** | Short-lived; obtained on demand from refresh token; not stored long-term |

**Redirect URI:** `{APP_URL}/admin/google/oauth/callback`  
**OAuth client type:** Web application (Google Cloud Console)

### 4.3 Developer token

- Stored **server-side only** in environment configuration (`GOOGLE_ADS_DEVELOPER_TOKEN`)
- Sent as HTTP header `developer-token` on every Ads API request
- Never exposed to browsers or client devices

### 4.4 Manager (MCC) accounts

For client accounts under a manager:

- Header `login-customer-id` is set to the manager customer ID when required
- Account discovery uses `customer_client` GAQL under accessible manager accounts
- Picker UI labels match Google Ads workspace format (e.g. `Irish Titan > Client Name`)

---

## 5. API operations in detail

### 5.1 Connection setup (low frequency)

| Operation | Endpoint / query | Purpose |
|-----------|------------------|---------|
| List accessible customers | `customers:listAccessibleCustomers` | OAuth account picker |
| List MCC clients | `searchStream` + `customer_client` GAQL | Include client accounts under managers |
| Test connection | `searchStream` — `SELECT metrics.cost_micros FROM customer WHERE segments.date DURING LAST_7_DAYS LIMIT 1` | Validate credentials before save |

**Frequency:** Per admin action (connect, reconnect, test). Not scheduled.

### 5.2 Scheduled data sync (ongoing)

Two read-only reporting streams per connection:

#### Stream A: Account daily metrics (`spend_daily`)

```sql
SELECT
  segments.date,
  metrics.cost_micros,
  metrics.impressions,
  metrics.clicks,
  metrics.ctr,
  metrics.conversions_value
FROM customer
WHERE segments.date BETWEEN '{start_date}' AND '{end_date}'
ORDER BY segments.date
```

#### Stream B: Campaign daily metrics (`campaign_daily`)

```sql
SELECT
  segments.date,
  campaign.id,
  campaign.name,
  metrics.cost_micros,
  metrics.impressions,
  metrics.clicks,
  metrics.ctr,
  metrics.conversions_value
FROM campaign
WHERE segments.date BETWEEN '{start_date}' AND '{end_date}'
ORDER BY segments.date
```

**Metrics pulled:**

| Google Ads metric | Internal use |
|-------------------|--------------|
| `cost_micros` | Spend (converted to currency units) |
| `impressions` | Impression totals |
| `clicks` | Click totals |
| `ctr` | CTR display |
| `conversions_value` | Conversion value totals |

No personally identifiable information (PII) is requested from the Ads API. We do not query `click_view`, user identifiers, or customer match lists.

---

## 6. API call volume and efficiency

### 6.1 Sync schedule

| Job type | Typical frequency | Notes |
|----------|-------------------|-------|
| **Backfill** | Once per new connection | Default ~16 months of history |
| **Incremental sync** | Daily (scheduled) + optional hourly for recent days | Default 5-day overlap for corrections |
| **Manual sync** | Admin-initiated | Same connector logic |

### 6.2 Efficiency measures

1. **Date chunking:** Queries use small date windows (default **1 day per request**) to limit payload size and job duration.
2. **Cursor-based pagination:** Multi-day ranges are processed across queued jobs with resumable cursors.
3. **Job time budgets:** Workers stop and re-queue before platform timeout limits (~45–55 seconds per job).
4. **Reporting lag:** End date excludes the most recent day(s) (default 1-day lag) to avoid incomplete data.
5. **Deduplication:** Rows stored with stable external IDs (`date` or `date:campaign_id`) to prevent duplicate inserts on re-sync.
6. **No polling loops:** We do not poll the API in tight loops; all access is batch-oriented sync.

### 6.3 Estimated call volume

**Per connection per full incremental day (approximate):**

- 1 API call per stream per day of data (2 streams × 1 day = **2 calls** per incremental run with default settings)
- Backfill over 16 months ≈ 480 days × 2 streams ≈ **960 calls** spread across many queued jobs over hours/days

**Platform-wide (example):** 50 connections × 2 daily incremental calls ≈ **100 calls/day** steady state, plus one-time backfills for new connections.

We believe this is well within reasonable use for a reporting tool and will monitor usage as we scale.

---

## 7. System architecture

```
┌─────────────────┐     OAuth 2.0      ┌──────────────────────┐
│  Agency Admin   │ ─────────────────► │  Google OAuth        │
│  (Browser)      │                    │  (accounts.google)   │
└────────┬────────┘                    └──────────────────────┘
         │ Save connection
         ▼
┌─────────────────────────────────────────────────────────────┐
│  Irish Titan Analytics (Laravel)                             │
│  ┌─────────────────┐  ┌──────────────────┐  ┌─────────────┐ │
│  │ Connection UI   │  │ Sync Queue Jobs  │  │ Dashboard   │ │
│  │ OAuth + picker  │  │ GoogleAdsConnector│  │ Service     │ │
│  └────────┬────────┘  └────────┬─────────┘  └──────▲──────┘ │
│           │                    │                     │        │
│           ▼                    ▼                     │        │
│  ┌─────────────────────────────────────────┐        │        │
│  │ Encrypted credentials + raw payloads DB  │────────┘        │
│  └─────────────────────────────────────────┘                 │
└────────────────────────────┬────────────────────────────────┘
                             │ searchStream (GAQL, read-only)
                             │ developer-token + OAuth bearer
                             ▼
                  ┌──────────────────────┐
                  │ Google Ads API (v21)   │
                  └──────────────────────┘
```

### 7.1 Data flow

1. Admin authorizes Google account → refresh token stored encrypted.
2. Admin selects `customer_id` (+ optional `login_customer_id` for MCC).
3. Background job runs `GoogleAdsConnector::fetch()` → GAQL via `searchStream`.
4. Rows normalized to JSON payloads → `raw_connector_payloads` table.
5. Transform job writes daily metrics.
6. Client dashboard reads from database only (no live API calls for viewers).

### 7.2 Key code references

| Area | Path |
|------|------|
| API client | `app/Ingestion/Connectors/GoogleAds/GoogleAdsApiClient.php` |
| Connector / GAQL | `app/Ingestion/Connectors/GoogleAdsConnector.php` |
| OAuth | `app/Services/Google/GoogleOAuthService.php`, `app/Http/Controllers/Admin/GoogleOAuthController.php` |
| Dashboard | `app/Services/Analytics/GoogleAdsDashboardService.php` |
| Config | `config/titan.php` → `google_ads` |

---

## 8. Security, privacy, and data handling

| Control | Implementation |
|---------|----------------|
| **Credential encryption** | Connection credentials (refresh token, customer IDs) encrypted at rest |
| **Developer token** | Server environment variable only; not in source control or client bundles |
| **Least privilege** | Read-only reporting queries; OAuth scope limited to Ads API access required for reporting |
| **Access control** | Admin-only connection management; client users see dashboards for their organization only |
| **Token refresh** | Access tokens generated on demand; not logged |
| **Data retention** | Synced metrics retained for dashboard history; clear-data action available per connection |
| **PII** | We do not intentionally collect Ads user PII via API |

---

## 9. Error handling and compliance

- **API errors:** Logged server-side; connection `sync_status` updated; admins can retry sync.
- **Permission errors:** Surfaced on “Test connection” before save where possible (e.g. invalid customer, developer token restrictions).
- **Token revocation:** Admin must reconnect Google OAuth if refresh token is revoked.
- **Policy compliance:** Tool used only for reporting for accounts the user already manages; no circumvention of Google Ads access controls.
- **Terms:** We comply with [Google Ads API Terms and Conditions](https://developers.google.com/google-ads/api/docs/terms) and applicable Google API Services User Data Policy requirements.

---

## 10. Screenshots / UI description (for reviewers)

If the form requests screenshots, provide:

1. **Connection setup** — “Connect with Google” and Ads account picker (`Manager > Client` labels).
2. **Test connection** — Success state after selecting a customer.
3. **Client dashboard** — Google Ads tab with spend summary, daily chart, campaign table.
4. **No editing UI** — Confirm there are no campaign edit forms.

---

## 11. Requested access level

| Level | Requested? | Rationale |
|-------|------------|-----------|
| **Test** | Current | Development only |
| **Basic** | Acceptable minimum | Read-only reporting for production accounts |
| **Standard** | **Preferred** | Production reporting at modest scale across agency client base |

We need **production account access** for real client dashboards. Read-only GAQL reporting is our sole use case.

---

## 12. Checklist for application form

Use these short answers where the form has character limits:

**Tool description (short):**

> Irish Titan Analytics is a read-only marketing analytics dashboard for agencies. The Google Ads connector syncs daily spend, impressions, clicks, CTR, and conversion value at account and campaign level via `googleAds:searchStream` GAQL queries, for display in client dashboards. No campaign management.

**Read or write?**

> Read only.

**Which API features?**

> `customers:listAccessibleCustomers`, `googleAds:searchStream` (GAQL reporting on `customer` and `campaign` resources).

**Who accesses the API?**

> Our server, on behalf of authenticated agency administrators who have linked their Google account. End clients view stored data only.

**How often?**

> Daily incremental sync per connection (~2 API calls per day per connection with default settings), plus one-time backfill on connect.

---

## 13. Supporting materials

Attach or link:

- This design document (PDF or hosted doc)
- Architecture diagram (Section 7)
- Company website: https://irishtitan.com

---

## 14. Declaration

We confirm that:

- All information in this document accurately describes our planned and implemented use of the Google Ads API.
- Irish Titan Analytics will use the API only for the purposes described above.
- We will not share developer tokens or OAuth credentials with unauthorized parties.
- We will respond promptly to any Google requests regarding API usage.

**Authorized signatory:** Tyler Page Senior Software Engineer  
**Date:** 2026-06-09

---

## Appendix: Environment configuration

| Variable | Purpose |
|----------|---------|
| `GOOGLE_CLIENT_ID` | OAuth web client ID |
| `GOOGLE_CLIENT_SECRET` | OAuth web client secret |
| `GOOGLE_ADS_DEVELOPER_TOKEN` | Platform developer token |
| `TITAN_GOOGLE_ADS_API_VERSION` | API version (default `v21`) |
| `TITAN_GOOGLE_ADS_BACKFILL_MONTHS` | Historical sync window (default 16) |
| `TITAN_GOOGLE_ADS_INCREMENTAL_DAYS` | Incremental overlap (default 5) |
| `TITAN_GOOGLE_ADS_DATA_LAG_DAYS` | Reporting lag (default 1) |
| `TITAN_GOOGLE_ADS_CHUNK_DAYS` | Days per API request (default 1) |
