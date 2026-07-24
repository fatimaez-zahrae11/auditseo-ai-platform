# API Usage Log Test Cases

## Backend file/functionality tested

`ApiUsageLog` persistence performed by `AiRecommendationService` for successful and failed external AI requests.

## Related backend files

- `app/Models/ApiUsageLog.php`
- `app/Services/Ai/AiRecommendationService.php`
- `database/migrations/2026_07_08_202452_create_api_usage_logs_table.php`
- `app/Models/User.php`

## Test types covered

Integration Tests, Security Tests, Database Tests, and Mock Tests.

## PHPUnit test files covering it

- `tests/Feature/AiRecommendationApiTest.php`

## Test cases / scenarios

| Scenario | Coverage |
| --- | --- |
| Log a successful AI request | PHPUnit: user, provider, success status, HTTP status, and null error are persisted |
| Log a failed AI request | PHPUnit: failed status, upstream HTTP status, and a generic stored error are persisted |
| Protect upstream diagnostics | PHPUnit: sensitive upstream error text is not stored |
| Protect the API key | PHPUnit: the key is absent from all usage-log attributes |

## Expected results

- Successful and failed provider calls create the usage-log records asserted by PHPUnit.
- Stored failure details are generic and safe.
- API keys and sensitive upstream diagnostics are never stored in the usage log.

## Status

**DONE**
