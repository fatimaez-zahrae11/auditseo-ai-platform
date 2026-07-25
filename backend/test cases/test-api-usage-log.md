# API Usage Logging

## Backend part

`app/Models/ApiUsageLog.php`

## Real executable test file

`tests/Feature/AiRecommendationApiTest.php`

## What is tested

- Log successful AI requests
- Log failed AI requests
- Store safe error messages
- Do not store the API key or sensitive provider details

## Test type

- Integration
- Security
- Database
- Mock

## How to run

```bash
php artisan test
```

## Status

DONE
