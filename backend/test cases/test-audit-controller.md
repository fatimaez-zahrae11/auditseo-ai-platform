# Audit Controller

## Backend part

`AuditController`: create, list, and view SEO audits.

## Real executable test file

`tests/Feature/AuditApiTest.php`

## What is tested

- Audit routes require authentication.
- An authenticated user can create an audit.
- Crawl data, scores, issues, domains, and audits are stored.
- Crawler failures return a safe error.
- Existing domains are reused for the same user.
- Users can list and view only their own audits.

## Test type

Feature, integration, security, and database tests.

## How to run

```bash
php artisan test --filter=AuditApiTest
```

## Status

**DONE**
