# AI Recommendation Service

## Backend part

`app/Services/Ai/AiRecommendationService.php`

## Real executable test file

`tests/Feature/AiRecommendationApiTest.php`

## What is tested

- Send audit data to the configured AI endpoint
- Use the configured model
- Handle success, invalid response, HTTP error, and connection error
- Store the generated recommendation
- Keep the API key out of responses and logs

All AI requests are faked during PHPUnit tests.

## Test type

- Feature
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
