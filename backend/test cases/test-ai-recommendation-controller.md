# AI Recommendation API

## Backend part

`app/Http/Controllers/Api/AiRecommendationController.php`

## Real executable test file

`tests/Feature/AiRecommendationApiTest.php`

## What is tested

- Generate and store a recommendation
- Retrieve stored recommendations newest first
- Require authentication and audit ownership
- Avoid an AI call when reading stored data
- Return safe errors when the AI service fails
- Limit recommendation generation requests

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
