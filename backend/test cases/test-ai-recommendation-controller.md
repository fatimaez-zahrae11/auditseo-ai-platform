# AI Recommendation Controller

## Backend part

`AiRecommendationController`: generate and retrieve recommendations for an audit.

## Real executable test file

`tests/Feature/AiRecommendationApiTest.php`

## What is tested

- Recommendation routes require authentication.
- A user can generate and store a recommendation for their own audit.
- Stored recommendations are returned newest first.
- Retrieving stored recommendations does not call the external AI service.
- A user cannot access another user's audit recommendations.
- External AI failures return a safe error.

## Test type

Feature, integration, security, mock, and database tests.

## How to run

```bash
php artisan test --filter=AiRecommendationApiTest
```

## Status

**DONE**
