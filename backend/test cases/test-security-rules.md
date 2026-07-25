# Security Rules

## Backend part

Authentication, ownership checks, SSRF protection, rate limits, and secret handling.

## Real executable test file

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AuditApiTest.php`
- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/DashboardApiTest.php`

## What is tested

- Protected routes require authentication
- Users cannot access another user's data
- Unsafe URLs and private redirect targets are blocked
- Login, registration, and AI generation are rate limited
- Passwords, API keys, and sensitive errors are not exposed

## Test type

- Feature
- Security
- Validation

## How to run

```bash
php artisan test
```

## Status

DONE
