# AI Recommendation Controller Test Cases

## Backend file/functionality tested

`AiRecommendationController` operations for generating and retrieving recommendations for a user-owned audit.

## Related backend files

- `app/Http/Controllers/Api/AiRecommendationController.php`
- `app/Services/Ai/AiRecommendationService.php`
- `app/Exceptions/AiRecommendationException.php`
- `app/Models/AiRecommendation.php`
- `app/Models/Audit.php`
- `routes/api.php`

## Test types covered

Feature Tests, Integration Tests, Security Tests, Database Tests, and Mock Tests.

## PHPUnit test files covering it

- `tests/Feature/AiRecommendationApiTest.php`

## Test cases / scenarios

| Scenario | Coverage |
| --- | --- |
| Generate or retrieve recommendations without authentication | PHPUnit: requests are rejected |
| Retrieve stored recommendations for an owned audit | PHPUnit: results are returned newest first |
| Retrieve another user's recommendations | PHPUnit: access is hidden with HTTP `404` |
| Retrieve stored recommendations without an external AI call | PHPUnit HTTP mock assertion |
| Generate a recommendation for an owned audit | PHPUnit: external response is mocked and the recommendation is stored |
| Generate for another user's audit | PHPUnit: HTTP `404`, no external request, and no recommendation stored |
| External AI failure | PHPUnit: safe HTTP `502` response and no recommendation stored |
| Successful response confidentiality | PHPUnit: configured API key is absent from JSON |

## Expected results

- Recommendation endpoints require Sanctum authentication and audit ownership.
- Generation returns HTTP `201` and persists the mocked recommendation for the owned audit.
- Retrieval returns stored recommendations without contacting the external provider.
- Provider failures return a generic public error without secrets or upstream diagnostics.

## Status

**DONE**
