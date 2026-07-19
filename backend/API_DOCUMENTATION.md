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

- The frontend never calls OpenRouter, Anthropic, or another AI provider directly.
- The frontend never needs or receives the AI API key.
- Use Laravel's `POST /audits/{audit}/recommendations` endpoint to generate a recommendation.
- Laravel stores each successfully generated recommendation.
- Use `GET /audits/{audit}/recommendations` to retrieve stored results without spending another AI request.
- Display the `generated_text` field from the returned recommendation object.
- Recommendations returned by the GET endpoint are ordered newest to oldest.
- Dashboard statistics contain only data owned by the authenticated user.
- A resource belonging to another user is returned as `404 Not Found`, not as accessible data.
- Treat IDs as opaque identifiers and never assume that changing an ID grants access.
