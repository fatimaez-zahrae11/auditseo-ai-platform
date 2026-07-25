# Audit API

## Backend part

`app/Http/Controllers/Api/AuditController.php`

## Real executable test file

`tests/Feature/AuditApiTest.php`

## What is tested

- Create an audit
- Store crawl data, scores, and issues
- Reject invalid URLs
- Return a safe crawler error
- Reuse an existing user domain
- List and view only the user's audits

## Test type

- Feature
- Integration
- Security
- Validation
- Database
- Mock

## How to run

```bash
php artisan test
```

## Status

DONE
