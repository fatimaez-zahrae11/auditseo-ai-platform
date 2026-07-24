# Backend Security Rules

## Backend part

Authentication, resource ownership, request safety, rate limiting, and secret protection.

## Real executable test file

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AuditApiTest.php`
- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/DashboardApiTest.php`

## What is tested

- Protected routes reject unauthenticated requests.
- Users cannot read another user's audits or recommendations.
- Dashboard data is limited to the authenticated user.
- Unsafe audit URLs are rejected before an HTTP request is sent.
- Registration rate limiting is enforced.
- Passwords, API keys, and sensitive error details are not exposed in the tested responses or logs.

## Test type

Feature and security tests.

## How to run

```bash
php artisan test
```

## Status

**DONE**
