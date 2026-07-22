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

Protected routes require the Sanctum token returned by registration or login:

```http
Authorization: Bearer <token>
```

Never put the token in a URL or log it to the browser console.

### Authentication flow

1. Register with `POST /register` or sign in with `POST /login`.
2. Read the `token` value from the successful response and store it in the frontend's authentication state/storage.
3. Add `Authorization: Bearer <token>` to every protected request.
4. Optionally use `GET /me` to restore or verify the current session.
5. Call `POST /logout` to revoke the current token, then remove it from frontend storage.

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
| `POST` | `/register` | No | Create an account and token |
| `POST` | `/login` | No | Sign in and create a token |
| `GET` | `/me` | Yes | Get the authenticated user |
| `POST` | `/logout` | Yes | Revoke the current token |
| `POST` | `/audits` | Yes | Run and store a new SEO audit |
| `GET` | `/audits` | Yes | List the user's audits |
| `GET` | `/audits/{id}` | Yes | Get one owned audit |
| `POST` | `/audits/{audit}/recommendations` | Yes | Generate and store an AI recommendation |
| `GET` | `/audits/{audit}/recommendations` | Yes | Retrieve stored recommendations |
| `GET` | `/dashboard` | Yes | Get user-specific summary statistics |

## Authentication

### Register

Creates a user account and immediately returns a Sanctum token.

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
  "message": "Registration successful.",
  "user": {
    "id": 1,
    "name": "TechGirl",
    "email": "TechGirl@example.com",
    "email_verified_at": null,
    "created_at": "2026-07-19T10:00:00.000000Z",
    "updated_at": "2026-07-19T10:00:00.000000Z"
  },
  "token": "1|sanctum-token-value"
}
```

Common errors:

- `422 Validation Error` when fields are missing, the email is invalid/already registered, or the password is too weak.
- `429 Too Many Requests` when the rate limit is exceeded.

### Login

Authenticates an existing user and creates a new Sanctum token.

- **Method:** `POST`
- **URL:** `/login`
- **Authentication:** Not required
- **Rate limit:** 5 requests per minute

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
    "email": "TechGirl@example.com",
    "email_verified_at": null,
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

- `429 Too Many Requests` when the rate limit is exceeded.

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
    "email": "TechGirl@example.com",
    "email_verified_at": null,
    "created_at": "2026-07-19T10:00:00.000000Z",
    "updated_at": "2026-07-19T10:00:00.000000Z"
  }
}
```

Common error:

- `401 Unauthorized` when the token is missing, invalid, or revoked.

### Logout

Revokes the token used for the current request.

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

## Audits

All audit endpoints only expose audits owned by the authenticated user. Audit ownership is determined through the audit's domain.

### Create an audit

Crawls a public HTTP or HTTPS URL, calculates SEO scores, creates detected issues, and stores the audit.

- **Method:** `POST`
- **URL:** `/audits`
- **Authentication:** Required

Request body:

```json
{
  "url": "https://example.com/page"
}
```

The URL must be valid, begin with `http://` or `https://`, and must not target an unsafe/private address.

The response includes professional SEO analysis in both `audit.raw_data` and the top-level `raw_data` field. Detected audit issues are returned in both `audit.issues` and the top-level `issues` array.

Successful response — `201 Created`:

```json
{
  "message": "Audit created successfully.",
  "audit": {
    "id": 12,
    "domain_id": 4,
    "global_score": 86,
    "technical_score": 100,
    "content_score": 75,
    "links_score": 70,
    "performance_score": 100,
    "raw_data": {
      "title": null,
      "meta_description": "Example description",
      "h1_count": 1,
      "h2_count": 2,
      "images_count": 3,
      "images_missing_alt_count": 1,
      "links_count": 5,
      "uses_https": true,
      "robots_txt_found": true,
      "sitemap_xml_found": true
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
    "issues": [
      {
        "id": 30,
        "audit_id": 12,
        "category": "content",
        "title": "Missing page title",
        "severity": "important",
        "description": null,
        "recommendation": "Add a descriptive title element.",
        "created_at": "2026-07-19T10:15:00.000000Z",
        "updated_at": "2026-07-19T10:15:00.000000Z"
      }
    ]
  },
  "domain": {
    "id": 4,
    "user_id": 1,
    "domain_name": "example.com",
    "url": "https://example.com/page",
    "created_at": "2026-07-19T10:15:00.000000Z",
    "updated_at": "2026-07-19T10:15:00.000000Z"
  },
  "issues": [
    {
      "id": 30,
      "audit_id": 12,
      "category": "content",
      "title": "Missing page title",
      "severity": "important",
      "description": null,
      "recommendation": "Add a descriptive title element.",
      "created_at": "2026-07-19T10:15:00.000000Z",
      "updated_at": "2026-07-19T10:15:00.000000Z"
    }
  ],
  "raw_data": {
    "title": null,
    "meta_description": "Example description",
    "h1_count": 1,
    "h2_count": 2,
    "images_count": 3,
    "images_missing_alt_count": 1,
    "links_count": 5,
    "uses_https": true,
    "robots_txt_found": true,
    "sitemap_xml_found": true
  }
}
```

Common errors:

- `401 Unauthorized` when authentication is missing or invalid.
- `422 Validation Error` when `url` is missing, invalid, does not use HTTP(S), or targets an unsafe address.
- `502 Bad Gateway` when Laravel cannot fetch the requested URL:

```json
{
  "message": "Unable to fetch the requested URL."
}
```

### List audits

Returns the authenticated user's audits, ordered newest first. Each audit includes its domain.

- **Method:** `GET`
- **URL:** `/audits`
- **Authentication:** Required
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
  ]
}
```

When the user has no audits, `audits` is an empty array.

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

The frontend must never call the configured AI provider directly. It calls Laravel, and Laravel safely handles provider credentials and stores successful recommendations.

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

Do not attempt to obtain, send, or display an AI provider API key in the frontend.

### Get stored AI recommendations

Returns recommendations already stored for an owned audit. This endpoint does **not** call the AI provider and does not generate a new recommendation. Results are ordered newest first.

- **Method:** `GET`
- **URL:** `/audits/{audit}/recommendations`
- **Authentication:** Required
- **Path parameter:** Replace `{audit}` with the audit ID.
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
  ]
}
```

When no recommendation has been generated, `recommendations` is an empty array. Render each item's `generated_text`; do not regenerate merely to display prior results.

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

## Common error formats and status codes

Always branch on the HTTP status (`response.status`), not only the response message.

### `200 OK`

The request completed successfully. Used by login, current-user, logout, list/detail, stored recommendations, and dashboard endpoints.

### `201 Created`

A resource was created successfully. Used by registration, audit creation, and AI recommendation generation.

### `401 Unauthorized`

The protected request has no valid Sanctum token.

```json
{
  "message": "Unauthenticated."
}
```

The frontend should clear an invalid token and redirect the user to sign in.

### `403 Forbidden`

The user is authenticated but is not allowed to perform an operation. The frontend should show an access-denied state. Ownership-protected audit endpoints intentionally return `404` instead of revealing whether another user's resource exists.

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

The client exceeded a route rate limit. Registration, login, and AI recommendation generation are limited to 5 requests per minute. Disable repeated submission and ask the user to retry later.

### `502 AI service unavailable`

AI recommendation generation could not obtain a valid provider response:

```json
{
  "message": "AI recommendation service is unavailable."
}
```

Keep the current audit page usable, show a retry message, and avoid tight automatic retry loops. Audit creation can also return `502` with `Unable to fetch the requested URL.` when the target site cannot be reached.

## Important frontend notes

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
- Recommendations returned by the GET endpoint are ordered newest to oldest.
- Dashboard statistics contain only data owned by the authenticated user.
- A resource belonging to another user is returned as `404 Not Found`, not as accessible data.
- Treat IDs as opaque identifiers and never assume that changing an ID grants access.
