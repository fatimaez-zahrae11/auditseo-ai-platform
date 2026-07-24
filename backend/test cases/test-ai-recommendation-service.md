# AI Recommendation Service

## Backend part

`AiRecommendationService`: build the audit prompt, call the configured AI service, and handle its response.

## Real executable test file

`tests/Feature/AiRecommendationApiTest.php`

There is no separate service unit test in the current test suite.

## What is tested

- A mocked AI response creates a stored recommendation.
- The configured endpoint and model are used.
- Audit scores, raw data, and issues are included in the request.
- Provider failures return a safe error.
- The API key is not returned or stored in usage logs.

## Test type

Feature, integration, security, HTTP mock, and database tests.

## How to run

```bash
php artisan test --filter=AiRecommendationApiTest
```

## Status

**DONE**
