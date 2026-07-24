# API Usage Log

## Backend part

`ApiUsageLog`: records external AI request results.

## Real executable test file

`tests/Feature/AiRecommendationApiTest.php`

## What is tested

- A successful AI request creates a success log.
- A failed AI request creates a failed log.
- Failed logs contain a safe error message.
- Sensitive provider details and the API key are not stored.

## Test type

Integration, security, mock, and database tests.

## How to run

```bash
php artisan test --filter=AiRecommendationApiTest
```

## Status

**DONE**
