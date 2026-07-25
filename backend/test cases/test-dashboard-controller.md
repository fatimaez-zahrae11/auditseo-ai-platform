# Dashboard API

## Backend part

`app/Http/Controllers/Api/DashboardController.php`

## Real executable test file

`tests/Feature/DashboardApiTest.php`

## What is tested

- Authentication is required
- Audit, issue, and recommendation totals
- Average score and latest audit
- Data is limited to the current user
- Empty dashboard response

## Test type

- Feature
- Integration
- Security
- Database

## How to run

```bash
php artisan test
```

## Status

DONE
