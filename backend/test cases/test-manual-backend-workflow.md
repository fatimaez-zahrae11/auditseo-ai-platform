# Manual Backend Workflow

## Backend part

Main API workflow checked with Thunder Client.

## Real executable test file

The manual workflow is not an executable test. Related PHPUnit tests are in:

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AuditApiTest.php`
- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/DashboardApiTest.php`

## What is tested

- Register
- Call `/api/me`
- Create an audit for `https://www.python.org`
- Generate an AI recommendation
- Retrieve the stored recommendation
- Open the dashboard

No token, password, API key, or secret is written here.

## Test type

- Manual Smoke

## How to run

Run the steps above with Thunder Client. Run PHPUnit separately with:

```bash
php artisan test
```

## Status

DONE
