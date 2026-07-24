# Dashboard Controller

## Backend part

`DashboardController`: dashboard totals and latest audit.

## Real executable test file

`tests/Feature/DashboardApiTest.php`

## What is tested

- The dashboard requires authentication.
- Audit, issue, and recommendation totals are returned.
- The average score and latest audit are returned.
- Only the authenticated user's data is included.
- Empty dashboard values are handled.

## Test type

Feature, security, and database tests.

## How to run

```bash
php artisan test --filter=DashboardApiTest
```

## Status

**DONE**
