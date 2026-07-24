# AI Recommendation Service Test Cases

## Backend file/functionality tested

`AiRecommendationService::generate`, including prompt construction, configured external request behavior, response handling, and usage logging.

## Related backend files

- `app/Services/Ai/AiRecommendationService.php`
- `app/Exceptions/AiRecommendationException.php`
- `app/Models/Audit.php`
- `app/Models/AuditIssue.php`
- `app/Models/ApiUsageLog.php`
- `app/Http/Controllers/Api/AiRecommendationController.php`
- `config/services.php`

## Test types covered

Feature Tests, Integration Tests, Security Tests, Database Tests, and Mock Tests.

## PHPUnit test files covering it

- `tests/Feature/AiRecommendationApiTest.php` (service exercised through the API with Laravel HTTP fakes)

There is no separate unit test file for this service in the referenced suite.

## Test cases / scenarios

| Scenario | Coverage |
| --- | --- |
| Successful generation | PHPUnit: mocked provider response is returned and persisted |
| Configured request | PHPUnit: endpoint, model, authorization header, scores, raw data, and issues are included as asserted |
| Failed provider response | PHPUnit: safe exception path, failed usage log, and no recommendation |
| Successful-response confidentiality | PHPUnit: API key is not returned |
| Usage-log confidentiality | PHPUnit: API key is not stored |

## Expected results

- A successful mocked provider call produces provider, prompt summary, and trimmed generated text for persistence.
- The external request uses the configured endpoint and model and includes the audited SEO data.
- A failed provider response becomes a generic service-unavailable API response.
- Secrets and sensitive upstream diagnostics are not exposed.

## Status

**DONE**
