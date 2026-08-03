# AuditSEO AI Platform API

This document describes the Laravel API contract used by the frontend application.

## Quick reference

### Base URL

Local development:

```text
http://127.0.0.1:8000/api
```

All endpoint paths below are relative to this base URL. For example, `POST /register` means:

```text
POST http://127.0.0.1:8000/api/register
```

### Required headers

Send this header with every API request:

```http
Accept: application/json
```

For requests with a JSON body, also send:

```http
Content-Type: application/json
```

Protected routes require the Sanctum token returned by a successful login after email verification:

```http
Authorization: Bearer <token>
```

Never put the token in a URL or log it to the browser console.

### Authentication flow

1. Register with `POST /register`. This creates an unverified user, sends an email verification notification, and does **not** return a token.
2. Show a "check your email" state and let the user open the signed verification URL from the email.
3. After verification succeeds, ask the user to log in with `POST /login`.
4. Read the `token` from the successful login response and only then store it in the frontend's authentication state/storage.
5. Add `Authorization: Bearer <token>` to every protected request.
6. Optionally use `GET /me` to restore or verify the current session.
7. Call `POST /logout` to revoke the current token, or `POST /logout-all` to revoke every token, then remove the token from frontend storage.

If login returns `403` with `Email verification is required before login.`, keep the user signed out and offer the verification-notification resend action. Registration alone never grants access to protected endpoints.

Example frontend helper:

```js
const API_BASE_URL = 'http://127.0.0.1:8000/api';

export async function apiRequest(path, { token, ...options } = {}) {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  });

  const data = await response.json();

  if (!response.ok) {
    throw { status: response.status, data };
  }

  return data;
}
```

## Endpoint summary

| Method | Endpoint | Authentication | Purpose |
| --- | --- | --- | --- |
| `GET` | `/health` | No | Check application and database availability |
| `POST` | `/register` | No | Create an unverified account and send a verification email |
| `POST` | `/login` | No | Sign in a verified user and create a token |
| `GET` | `/email/verify/{id}/{hash}` | Signed URL | Verify an email address |
| `POST` | `/email/verification-notification` | No | Resend a verification email with a generic response |
| `GET` | `/me` | Yes | Get the authenticated user |
| `POST` | `/logout` | Yes | Revoke the current token |
| `POST` | `/logout-all` | Yes | Revoke all tokens for the authenticated user |
| `POST` | `/audits` | Yes | Run and store a new SEO audit |
| `GET` | `/audits` | Yes | List the user's audits with pagination |
| `GET` | `/audits/{id}` | Yes | Get one owned audit |
| `POST` | `/audits/{audit}/recommendations` | Yes | Generate and store an AI recommendation |
| `GET` | `/audits/{audit}/recommendations` | Yes | Retrieve stored recommendations |
| `GET` | `/dashboard` | Yes | Get user-specific summary statistics |

## Health check

`GET /api/health` is a public JSON endpoint for deployment health checks and uptime monitoring. It does not require authentication.

When the application and database are reachable, it returns `200 OK`:

```json
{
  "status": "ok",
  "app": "AuditSEO API",
  "database": "ok"
}
```

If the database check fails, it returns `503 Service Unavailable` without exposing exception messages or other internal details:

```json
{
  "status": "degraded",
  "app": "AuditSEO API",
  "database": "error"
}
```

## Authentication

### Register

Creates an unverified user account and sends an email verification notification. It does **not** create or return a Sanctum token.

- **Method:** `POST`
- **URL:** `/register`
- **Authentication:** Not required
- **Rate limit:** 5 requests per minute

Request body:

```json
{
  "name": "TechGirl",
  "email": "TechGirl@example.com",
  "password": "Password1"
}
```

Password requirements:

- At least 8 characters
- At least one uppercase letter
- At least one number

Successful response — `201 Created`:

```json
{
  "message": "Registration successful. Please verify your email before logging in."
}
```

After this response, show a "check your email" screen. Do not look for or store a token, and do not call protected routes. Email addresses are trimmed and stored in lowercase.

Common errors:

- `422 Validation Error` when fields are missing, the email is invalid/already registered, or the password is too weak.
- `429 Too Many Requests` when the rate limit is exceeded.

### Login

Authenticates an existing **verified** user and creates a new Sanctum token. The token expires after 1,440 minutes (24 hours). A successful login revokes all previously issued tokens for that user before creating the new token, so the API uses a single-session login policy. Email input is trimmed and lowercased, so login is not case-sensitive.

- **Method:** `POST`
- **URL:** `/login`
- **Authentication:** Not required
- **Rate limit:** 5 requests per minute for the same email and IP, plus 20 requests per minute per source IP

Request body:

```json
{
  "email": "TechGirl@example.com",
  "password": "Password1"
}
```

Successful response — `200 OK`:

```json
{
  "message": "Login successful.",
  "user": {
    "id": 1,
    "name": "TechGirl",
    "email": "techgirl@example.com",
    "email_verified_at": "2026-07-19T10:05:00.000000Z",
    "created_at": "2026-07-19T10:00:00.000000Z",
    "updated_at": "2026-07-19T10:00:00.000000Z"
  },
  "token": "2|sanctum-token-value"
}
```

Common errors:

- `422 Validation Error` for an invalid request or incorrect credentials. Incorrect credentials return:

```json
{
  "message": "Invalid credentials."
}
```

- `403 Forbidden` when the credentials are valid but the email has not been verified:

```json
{
  "message": "Email verification is required before login."
}
```

  Keep the user logged out, do not store a token, and offer the resend-verification action.
- `429 Too Many Requests` when the rate limit is exceeded.

### Verify email

Verifies the user's email through the signed URL sent by the backend.

- **Method:** `GET`
- **URL:** `/email/verify/{id}/{hash}`
- **Authentication:** Not required
- **Query string:** The emailed URL includes Laravel signature and expiration parameters. The frontend must preserve the complete URL without changing its path or query string.
- **Request body:** None

The link is valid only when its signature is valid and its `{hash}` matches the user's email. Calling a valid link again is safe.

Successful response — `200 OK`:

```json
{
  "message": "Email verified successfully. You may now log in."
}
```

Verification does not return a token. After success, direct the user to log in again.

Common errors:

- `403 Forbidden` when the signed link is invalid, modified, or expired:

```json
{
  "message": "The verification link is invalid or has expired."
}
```

- `404 Not Found` when the user referenced by the signed URL no longer exists.

### Resend email verification notification

Requests another verification email. The response is intentionally identical for unknown, verified, and unverified email addresses, so the frontend must not infer whether an account exists.

- **Method:** `POST`
- **URL:** `/email/verification-notification`
- **Authentication:** Not required
- **Rate limit:** 5 requests per minute for the same email and IP, plus 20 requests per minute per source IP

Request body:

```json
{
  "email": "techgirl@example.com"
}
```

Successful generic response — `200 OK`:

```json
{
  "message": "If the email is registered and unverified, a verification link has been sent."
}
```

Common errors:

- `422 Validation Error` when the email field is missing or invalid.
- `429 Too Many Requests` when either resend rate limit is exceeded.

### Get current user

Returns the user associated with the supplied token.

- **Method:** `GET`
- **URL:** `/me`
- **Authentication:** Required
- **Request body:** None

Successful response — `200 OK`:

```json
{
  "user": {
    "id": 1,
    "name": "TechGirl",
    "email": "techgirl@example.com",
    "email_verified_at": "2026-07-19T10:05:00.000000Z",
    "created_at": "2026-07-19T10:00:00.000000Z",
    "updated_at": "2026-07-19T10:00:00.000000Z"
  }
}
```

Common error:

- `401 Unauthorized` when the token is missing, invalid, expired, or revoked.

### Logout

Revokes only the token used for the current request. Use logout-all when every token for the user must be revoked.

- **Method:** `POST`
- **URL:** `/logout`
- **Authentication:** Required
- **Request body:** None

Successful response — `200 OK`:

```json
{
  "message": "Logout successful."
}
```

After success, remove the token from frontend state/storage.

Common error:

- `401 Unauthorized` when the token is missing, invalid, or already revoked.

### Logout all sessions

Revokes every Sanctum token belonging to the authenticated user, including tokens issued to other sessions.

- **Method:** `POST`
- **URL:** `/logout-all`
- **Authentication:** Required
- **Request body:** None

Successful response — `200 OK`:

```json
{
  "message": "All sessions logged out successfully."
}
```

After success, remove the token from frontend state/storage on the current device. Other sessions will receive `401 Unauthorized` on their next protected request.

Common error:

- `401 Unauthorized` when the token is missing, invalid, or revoked.

### Protected endpoint requirements

Send `Authorization: Bearer <token>` for every endpoint in this table:

| Endpoint | Additional behavior |
| --- | --- |
| `GET /me` | Returns the authenticated user |
| `POST /logout` | Revokes the current token |
| `POST /logout-all` | Revokes every token for the user |
| `GET /dashboard` | Requires a verified email |
| `POST /audits` | Requires a verified email; limited to 10 requests per hour |
| `GET /audits` | Requires a verified email; returns paginated owned audits |
| `GET /audits/{id}` | Requires a verified email; only returns an owned audit |
| `POST /audits/{id}/recommendations` | Requires a verified email; limited to 5 requests per minute |
| `GET /audits/{id}/recommendations` | Requires a verified email; only returns recommendations for an owned audit |

Protected routes are generally limited to 30 requests per minute. More specific limits shown above replace that general limit for the applicable endpoint. A missing, expired, revoked, or invalid token returns `401 Unauthorized`.

### Global application rate limits

All public API endpoints, including the health check, registration, login, email verification, and verification resend endpoints, share a global limit of 120 requests per minute per source IP address. Stricter endpoint-specific login, registration, and verification limits still apply in addition to this shared budget.

Authenticated API activity is also limited to 300 requests per minute per authenticated user ID, with an IP-address fallback when no authenticated identity is available. The existing 30-requests-per-minute limit on ordinary authenticated routes remains in effect, so the stricter limit wins. Audit creation remains limited to 10 requests per hour and AI recommendation generation remains limited to 5 requests per minute; both also remain inside the broader authenticated activity budget.

Rate limits are shared across application instances through Laravel's configured rate-limiter cache store. This project uses the installed `predis/predis` package, so production should set `CACHE_STORE=redis`, `CACHE_LIMITER=redis`, and `REDIS_CLIENT=predis` with the configured `REDIS_*` connection values. Tests use the in-memory array cache and do not require a Redis server.

Application-level rate limiting reduces abusive traffic reaching controllers and external services, but it is not a replacement for network-level DDoS protection. Production must also use Cloudflare, Nginx request controls, firewall restrictions, and a correctly configured trusted-proxy chain.

## Audits

All audit endpoints only expose audits owned by the authenticated user. Audit ownership is determined through the audit's domain.

### Create an audit

Crawls a public HTTP or HTTPS URL, calculates SEO scores, creates detected issues, and stores the audit.

Audit creation is asynchronous. The endpoint validates and stores the request as a pending audit, dispatches the SEO audit job, and returns immediately. Crawling, scoring, issue generation, and final URL resolution run in the queue worker.

- **Method:** `POST`
- **URL:** `/audits`
- **Authentication:** Required

Request body:

```json
{
  "url": "https://example.com/page"
}
```

The URL must be valid, begin with `http://` or `https://`, and must not target an unsafe/private address. The crawler re-applies the public URL and DNS-pinning protections while processing redirects.

Successful response — `202 Accepted`:

```json
{
  "message": "Audit queued for processing.",
  "audit": {
    "id": 12,
    "status": "pending",
    "requested_url": "https://example.com/page"
  },
  "poll_url": "/api/audits/12"
}
```

The POST response does not include scores, issues, or crawl data because processing has not completed. Poll the owned audit endpoint until it reaches a terminal status:

```http
GET /api/audits/12
Authorization: Bearer <token>
```

Audit statuses:

- `pending`: stored and waiting for a worker.
- `running`: the worker has started crawling and scoring.
- `completed`: scores, `raw_data`, issues, and `final_url` are available.
- `failed`: processing ended unsuccessfully. Internal failure details are not exposed in API responses.

`requested_url` is the exact validated URL submitted for that audit. `final_url` is the final page URL reached after safe redirect handling and remains `null` until available. These audit-specific values are independent from the domain's host-level identity.

Common errors:

- `401 Unauthorized` when authentication is missing or invalid.
- `422 Validation Error` when `url` is missing, invalid, does not use HTTP(S), or targets an unsafe address.
- `429 Too Many Requests` when the audit creation rate limit is exceeded.

### Queue worker

Production should use the Redis queue connection:

```dotenv
QUEUE_CONNECTION=redis
```

At least one queue worker must be running for pending audits to progress:

```bash
php artisan queue:work
```

This documents the required worker command only; it does not imply that Supervisor, systemd, or another process manager is already configured.

### List audits

Returns the authenticated user's audits, ordered newest first, in pages of 20. Each audit includes its domain.

- **Method:** `GET`
- **URL:** `/audits`
- **Authentication:** Required
- **Query parameter:** Use `page` to request a page, for example `/audits?page=2`.
- **Request body:** None

Successful response — `200 OK`:

```json
{
  "audits": [
    {
      "id": 12,
      "domain_id": 4,
      "global_score": 86,
      "technical_score": 100,
      "content_score": 75,
      "links_score": 70,
      "performance_score": 100,
      "raw_data": {
        "title": "Example Page",
        "meta_description": "Example description"
      },
      "created_at": "2026-07-19T10:15:00.000000Z",
      "updated_at": "2026-07-19T10:15:00.000000Z",
      "domain": {
        "id": 4,
        "user_id": 1,
        "domain_name": "example.com",
        "url": "https://example.com/page",
        "created_at": "2026-07-19T10:15:00.000000Z",
        "updated_at": "2026-07-19T10:15:00.000000Z"
      }
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 3,
    "per_page": 20,
    "total": 45,
    "from": 1,
    "to": 20,
    "first_page_url": "https://api.example.com/api/audits?page=1",
    "last_page_url": "https://api.example.com/api/audits?page=3",
    "previous_page_url": null,
    "next_page_url": "https://api.example.com/api/audits?page=2"
  }
}
```

The response always uses separate `audits` and `pagination` objects. `previous_page_url` is `null` on the first page, and `next_page_url` is `null` on the last page. When the user has no audits, `audits` is an empty array; `from` and `to` are `null`.

Common error:

- `401 Unauthorized` when authentication is missing or invalid.

### Get one audit

Returns one owned audit, including its domain and detected issues.

The full professional SEO analysis is available in `audit.raw_data`. Detected audit issues are available in `audit.issues`.

- **Method:** `GET`
- **URL:** `/audits/{id}`
- **Authentication:** Required
- **Path parameter:** Replace `{id}` with the audit ID.
- **Request body:** None

Example URL:

```text
/audits/12
```

Successful response — `200 OK`:

```json
{
  "audit": {
    "id": 12,
    "domain_id": 4,
    "global_score": 86,
    "technical_score": 100,
    "content_score": 75,
    "links_score": 70,
    "performance_score": 100,
    "raw_data": {
      "title": "Example Page",
      "meta_description": "Example description"
    },
    "created_at": "2026-07-19T10:15:00.000000Z",
    "updated_at": "2026-07-19T10:15:00.000000Z",
    "domain": {
      "id": 4,
      "user_id": 1,
      "domain_name": "example.com",
      "url": "https://example.com/page",
      "created_at": "2026-07-19T10:15:00.000000Z",
      "updated_at": "2026-07-19T10:15:00.000000Z"
    },
    "issues": []
  }
}
```

Common errors:

- `401 Unauthorized` when authentication is missing or invalid.
- `404 Not Found` when the audit does not exist or belongs to another user. Treat both cases the same in the frontend.

## Professional SEO raw_data fields

`raw_data` contains detailed evidence collected by the Laravel audit engine. Fields may be `null` or empty when a value is absent, a resource could not be fetched, or a check does not apply. Arrays are deliberately sampled and bounded to keep responses compact.

### Technical SEO

- `http_status_code`
- `final_url`
- `redirect_count`
- `response_time_ms`
- `page_size_bytes`
- `canonical_url`
- `canonical_matches_final_url`
- `meta_robots`
- `is_indexable`
- `html_lang`
- `viewport_found`
- `h1_count`
- `h2_count`
- `h3_count`
- `h4_count`
- `h5_count`
- `h6_count`

These fields describe the final HTTP response, redirects, canonical/indexing signals, page language, responsive viewport, response timing/size, and heading counts.

### Link SEO

- `links_count`
- `internal_links_count`
- `external_links_count`
- `nofollow_links_count`
- `empty_anchor_links_count`
- `generic_anchor_links_count`
- `checked_links_count`
- `broken_links_count`
- `broken_links_sample`

`broken_links_sample` is a small URL sample. Link checking is deliberately limited, so `checked_links_count` can be lower than `links_count`.

### On-page Content SEO

- `title`
- `title_length`
- `meta_description`
- `meta_description_length`
- `word_count`
- `visible_text_sample`
- `h1_texts`
- `h2_texts`
- `heading_structure`
- `title_matches_h1`
- `images_count`
- `images_missing_alt_count`
- `images_alt_missing_ratio`

`heading_structure` is an ordered array such as `[{"tag":"h1","text":"Main title"}]`. `visible_text_sample` is truncated and is not the full page text.

### Robots and Sitemap SEO

- `robots_txt_found`
- `robots_txt_status_code`
- `robots_txt_allows_audited_url`
- `robots_txt_sitemap_urls`
- `robots_txt_disallow_rules_count`
- `sitemap_xml_found`
- `sitemap_xml_status_code`
- `sitemap_xml_is_valid`
- `sitemap_urls_count`
- `sitemap_contains_audited_url`
- `sitemap_https_urls_count`
- `sitemap_non_https_urls_count`
- `sitemap_checked_urls_count`
- `sitemap_broken_urls_count`
- `sitemap_broken_urls_sample`

The response can also include `sitemap_urls_sample`, a bounded list used for site-wide quality details. Sitemap parsing and URL checking use safety limits.

### Multi-page Crawl

- `crawl_enabled`
- `crawl_max_pages`
- `crawl_max_depth`
- `crawled_pages_count`
- `discovered_internal_urls_count`
- `crawled_pages`
- `pages_with_http_errors_count`
- `pages_with_missing_title_count`
- `pages_with_missing_meta_description_count`
- `pages_with_missing_h1_count`
- `pages_with_noindex_count`
- `pages_with_low_word_count_count`

Each compact `crawled_pages` item can contain:

```json
{
  "url": "https://example.com/about",
  "status_code": 200,
  "depth": 1,
  "title": "About Example",
  "meta_description": "About the Example organization.",
  "h1": "About Example",
  "word_count": 420,
  "is_indexable": true,
  "response_time_ms": 180,
  "page_size_bytes": 24500,
  "structured_data_found": true,
  "schema_types": ["Organization"],
  "canonical_url": "https://example.com/about",
  "content_fingerprint": "4d8a3c135f01a872"
}
```

The crawl is same-host and limit-based. These summaries do not contain full HTML or full page text.

### Performance SEO

- `content_type`
- `content_encoding`
- `compression_enabled`
- `cache_control`
- `cache_headers_present`
- `server_header`
- `html_size_kb`
- `is_html_response`
- `performance_warnings_count`

`response_time_ms`, `page_size_bytes`, `viewport_found`, and `redirect_count` from Technical SEO are also used by performance checks.

### Structured Data SEO

- `structured_data_found`
- `structured_data_formats`
- `json_ld_count`
- `microdata_found`
- `rdfa_found`
- `schema_types`
- `structured_data_errors_count`
- `structured_data_errors_sample`
- `important_schema_types_found`
- `recommended_schema_types_missing`

Supported detection includes JSON-LD, Microdata, and RDFa. The API stores extracted type names and short validation errors, not complete JSON-LD documents.

### Site-wide Quality

- `duplicate_title_groups`
- `duplicate_meta_description_groups`
- `duplicate_h1_groups`
- `duplicate_content_groups`
- `duplicate_content_count`
- `thin_content_pages_count`
- `thin_content_pages_sample`
- `canonical_conflicts_count`
- `canonical_conflicts_sample`
- `sitemap_orphan_urls_count`
- `sitemap_orphan_urls_sample`
- `site_quality_warnings_count`

Title, meta-description, and H1 duplicate groups use this compact shape:

```json
{
  "value": "Repeated page title",
  "urls": ["https://example.com/a", "https://example.com/b"],
  "count": 2
}
```

Content duplicate groups replace `value` with a short `fingerprint`. Group URL samples, thin-page samples, canonical-conflict samples, and sitemap-orphan samples are limited. A thin-page sample contains `url` and `word_count`; a canonical-conflict sample contains `url` and `canonical_url`.

### Audit response example

This is a shortened audit creation response. The real response can contain additional model timestamps, domain data, and `raw_data` fields. Audit issue objects are serialized under `issues`.

```json
{
  "message": "Audit created successfully.",
  "audit": {
    "id": 12,
    "global_score": 82,
    "technical_score": 88,
    "content_score": 72,
    "links_score": 78,
    "performance_score": 90
  },
  "raw_data": {
    "http_status_code": 200,
    "final_url": "https://example.com/page",
    "title": "Example Page",
    "word_count": 240,
    "internal_links_count": 8,
    "broken_links_count": 1,
    "compression_enabled": true,
    "structured_data_found": true,
    "schema_types": ["Organization", "WebSite"],
    "crawled_pages_count": 4,
    "thin_content_pages_count": 1,
    "duplicate_content_count": 0,
    "canonical_conflicts_count": 0
  },
  "issues": [
    {
      "category": "content",
      "title": "Thin content pages found",
      "severity": "important",
      "description": "1 crawled page(s) contain fewer than 300 visible words.",
      "recommendation": "Expand thin pages with useful, original information or consolidate pages that do not warrant separate URLs."
    }
  ]
}
```

For `GET /audits/{id}`, the same scores, professional `raw_data`, and audit issue objects are nested inside `audit` as `audit.raw_data` and `audit.issues`.

### Audit issue categories and severities

Audit issue `category` values are:

- `technical`
- `content`
- `links`
- `indexability`
- `accessibility`
- `performance`
- `structured_data`

Audit issue `severity` values are:

- `minor` — optimization or quality improvement
- `important` — meaningful SEO problem that should be prioritized
- `critical` — severe accessibility, indexability, technical, or performance problem

## AI recommendations

The frontend must never call the configured AI provider directly. It calls Laravel, and Laravel safely handles provider credentials and stores successful recommendations. The backend limits provider output tokens, downloaded response bytes, and stored `generated_text` length. A response that is invalid or exceeds those security limits is rejected without storing a partial recommendation.

### Generate an AI recommendation

Requests a new recommendation for an existing owned audit and stores the successful result.

- **Method:** `POST`
- **URL:** `/audits/{audit}/recommendations`
- **Authentication:** Required
- **Path parameter:** Replace `{audit}` with the audit ID.
- **Request body:** None
- **Rate limit:** 5 requests per minute

Example URL:

```text
/audits/12/recommendations
```

Successful response — `201 Created`:

```json
{
  "message": "AI recommendation generated successfully.",
  "recommendation": {
    "id": 8,
    "audit_id": 12,
    "provider": "openrouter",
    "prompt_summary": "SEO recommendations for audit #12 with 3 detected issue(s).",
    "generated_text": "Prioritize adding a descriptive page title, then improve image alternative text and internal linking.",
    "created_at": "2026-07-19T10:20:00.000000Z",
    "updated_at": "2026-07-19T10:20:00.000000Z"
  }
}
```

Display `recommendation.generated_text` as the generated recommendation content.

Common errors:

- `401 Unauthorized` when authentication is missing or invalid.
- `404 Not Found` when the audit does not exist or belongs to another user.
- `429 Too Many Requests` when the generation rate limit is exceeded.
- `502 AI service unavailable` when the external AI request fails:

```json
{
  "message": "AI recommendation service is unavailable."
}
```

Provider transport errors, invalid responses, and security-limit failures all use this generic `502` response. The frontend must not expect or display raw provider errors or internal provider details. Do not attempt to obtain, send, or display an AI provider API key in the frontend.

### Get stored AI recommendations

Returns a paginated history of recommendations already stored for an owned audit. This endpoint does **not** call the AI provider and does not generate a new recommendation. Results are ordered newest first.

- **Method:** `GET`
- **URL:** `/audits/{audit}/recommendations`
- **Authentication:** Required
- **Path parameter:** Replace `{audit}` with the audit ID.
- **Query parameters:** `page` selects the page. `per_page` selects the page size; the default is `20` and the maximum is `50`.
- **Request body:** None

Successful response — `200 OK`:

```json
{
  "recommendations": [
    {
      "id": 8,
      "audit_id": 12,
      "provider": "openrouter",
      "prompt_summary": "SEO recommendations for audit #12 with 3 detected issue(s).",
      "generated_text": "Prioritize adding a descriptive page title, then improve image alternative text and internal linking.",
      "created_at": "2026-07-19T10:20:00.000000Z",
      "updated_at": "2026-07-19T10:20:00.000000Z"
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1,
    "from": 1,
    "to": 1,
    "next_page_url": null,
    "previous_page_url": null
  }
}
```

The frontend must consume both the `recommendations` array and the `pagination` object rather than expecting the endpoint to return the complete history in one array. Use the pagination URLs or the `page` query parameter to request more results. When no recommendation has been generated, `recommendations` is an empty array, `total` is `0`, and `from` and `to` are `null`. Render each item's `generated_text`; do not regenerate merely to display prior results.

Common errors:

- `401 Unauthorized` when authentication is missing or invalid.
- `404 Not Found` when the audit does not exist or belongs to another user.

## Dashboard

### Get dashboard statistics

Returns summary information calculated only from the authenticated user's domains, audits, issues, and recommendations.

- **Method:** `GET`
- **URL:** `/dashboard`
- **Authentication:** Required
- **Request body:** None

Successful response — `200 OK`:

```json
{
  "total_audits": 5,
  "average_global_score": 78,
  "total_issues": 14,
  "total_ai_recommendations": 3,
  "latest_audit": {
    "id": 12,
    "domain_id": 4,
    "global_score": 86,
    "technical_score": 100,
    "content_score": 75,
    "links_score": 70,
    "performance_score": 100,
    "raw_data": {
      "title": "Example Page",
      "meta_description": "Example description"
    },
    "created_at": "2026-07-19T10:15:00.000000Z",
    "updated_at": "2026-07-19T10:15:00.000000Z",
    "domain": {
      "id": 4,
      "user_id": 1,
      "domain_name": "example.com",
      "url": "https://example.com/page",
      "created_at": "2026-07-19T10:15:00.000000Z",
      "updated_at": "2026-07-19T10:15:00.000000Z"
    }
  }
}
```

`average_global_score` is rounded to the nearest whole number. If the user has no audits, the response is:

```json
{
  "total_audits": 0,
  "average_global_score": 0,
  "total_issues": 0,
  "total_ai_recommendations": 0,
  "latest_audit": null
}
```

Common error:

- `401 Unauthorized` when authentication is missing or invalid.

## Production and frontend environment

- Configure the frontend API base URL to the deployed backend API URL, such as `https://api.example.com/api`. Do not ship the local `http://127.0.0.1:8000/api` value in a production frontend.
- Set backend `CORS_ALLOWED_ORIGINS` to include the exact production frontend origin, including its scheme and any non-default port. Do not use a wildcard origin for authenticated frontend traffic.
- Set backend `APP_URL` to the public HTTPS backend URL used to generate signed email-verification links.
- Set backend `FRONTEND_URL` to the public HTTPS frontend URL.
- Set `MAIL_MAILER=resend` in the production backend environment. Put the Resend API key in `RESEND_API_KEY` only in the real production `.env`; never commit the key or add it to `.env.example`.
- Verify the production sending domain in Resend before sending production email. Configure the DNS records supplied by Resend in Cloudflare (or the domain's DNS provider), and wait for Resend to confirm verification.
- Set `MAIL_FROM_ADDRESS` to an address on that verified domain and set `MAIL_FROM_NAME` to the desired sender name.
- Production verification emails must contain HTTPS production URLs. A link generated for `localhost` or `127.0.0.1` will not provide a usable production verification flow.
- Keep the complete signed verification URL unchanged when routing the user through the frontend or backend; modifying its signed parameters invalidates it.
- Use Redis through the installed Predis client for queues, shared application cache, and rate-limit counters in production (`QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `CACHE_LIMITER=redis`, and `REDIS_CLIENT=predis`). Ensure every application instance and queue worker connects to the same protected Redis service.
- Keep `SANCTUM_EXPIRATION=1440` (1,440 minutes / 24 hours) or configure a shorter production lifetime. Sanctum rejects expired tokens automatically with `401 Unauthorized`.
- Schedule `php artisan sanctum:prune-expired --hours=24` in production to remove token records that have been expired for at least 24 hours. This repository does not currently configure the production scheduler or cron entry; deployment must configure and monitor it. Pruning is database cleanup and is separate from Sanctum's automatic rejection of expired tokens.

## Common error formats and status codes

Always branch on the HTTP status (`response.status`), not only the response message.

### `200 OK`

The request completed successfully. Used by login, current-user, logout, list/detail, stored recommendations, and dashboard endpoints.

### `201 Created`

A resource was created successfully. Used by registration and AI recommendation generation.

### `202 Accepted`

The audit request was validated, stored as `pending`, and dispatched for background processing. Poll the response's `poll_url` until the audit becomes `completed` or `failed`.

### `401 Unauthorized`

The protected request has no valid Sanctum token.

```json
{
  "message": "Unauthenticated."
}
```

The frontend should clear an invalid token and redirect the user to sign in.

### `403 Forbidden`

Login returns `403` when valid credentials belong to an unverified user. In that case, keep the user signed out and offer verification resend. Other `403` responses indicate that an operation is forbidden. Ownership-protected audit endpoints intentionally return `404` instead of revealing whether another user's resource exists.

### `404 Not Found`

The requested resource does not exist or is not owned by the authenticated user. Do not use the response to infer whether another user's resource exists.

### `422 Validation Error`

Laravel validation errors normally use this shape:

```json
{
  "message": "The email field is required. (and 1 more error)",
  "errors": {
    "email": [
      "The email field is required."
    ],
    "password": [
      "The password field is required."
    ]
  }
}
```

Render messages from `errors[field]` beside the matching form control. Login can also return `422` with only `{ "message": "Invalid credentials." }`, so handle both shapes.

### `429 Too Many Requests`

The client exceeded a global or endpoint-specific route rate limit. Public API traffic has a shared 120-requests-per-minute per-IP budget, and authenticated activity has a shared 300-requests-per-minute per-user budget. Registration and AI recommendation generation are limited to 5 requests per minute, audit creation is limited to 10 requests per hour, and ordinary authenticated routes retain their 30-requests-per-minute limit. Login and verification resend also have email-and-IP and IP-only limits, so rotating email addresses does not bypass throttling. Disable repeated submission and ask the user to retry later.

### `502 AI service unavailable`

AI recommendation generation could not obtain a valid provider response:

```json
{
  "message": "AI recommendation service is unavailable."
}
```

Keep the current audit page usable, show a retry message, and avoid tight automatic retry loops.

## Important frontend notes

- After registration, show "check your email"; registration does not authenticate the user and does not return a token.
- Do not expect or store a token from `POST /register`.
- Handle the login `403` email-verification response separately from invalid credentials and provide a resend-verification form or action.
- After successful email verification, send the user through login again.
- Store a Sanctum token only after successful login, and remove it after logout, logout-all, or an authentication failure.
- Send `Authorization: Bearer <token>` on every protected API request.
- After `POST /audits` returns `202`, poll its `poll_url` and show pending/running progress until the audit reaches `completed` or `failed`.
- Display `global_score` prominently and show `technical_score`, `content_score`, `links_score`, and `performance_score` as category scores.
- Group audit issues by `category` and optionally by `severity` for summary and detail views.
- Use `raw_data` to build detailed tabs such as Technical SEO, Content, Links, Performance, Structured Data, Sitemap/Robots, and Crawl.
- The frontend should call only the Laravel API. It should not call external SEO, crawling, validation, or AI services directly.
- The frontend never calls the AI provider directly.
- The frontend never needs or receives the AI API key.
- Use Laravel's `POST /audits/{audit}/recommendations` endpoint to generate a recommendation.
- Laravel stores each successfully generated recommendation.
- Use `GET /audits/{audit}/recommendations` to retrieve stored results without making another AI request.
- Display the `generated_text` field from the returned recommendation object.
- Treat AI provider failures as the generic backend `502`; never render internal provider errors even if an unexpected upstream message is encountered.
- Recommendations returned by the GET endpoint are ordered newest to oldest.
- Dashboard statistics contain only data owned by the authenticated user.
- A resource belonging to another user is returned as `404 Not Found`, not as accessible data.
- Treat IDs as opaque identifiers and never assume that changing an ID grants access.
