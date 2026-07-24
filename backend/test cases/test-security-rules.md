# Backend Security Rules Test Cases

## Backend file/functionality tested

Backend authentication, authorization, ownership isolation, request throttling, unsafe URL rejection, and secret/error-data protection.

## Related backend files

- `routes/api.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/AuditController.php`
- `app/Http/Controllers/Api/AiRecommendationController.php`
- `app/Http/Controllers/Api/DashboardController.php`
- `app/Services/Seo/SeoCrawlerService.php`
- `app/Services/Ai/AiRecommendationService.php`
- `app/Models/ApiUsageLog.php`

## Test types covered

Security Tests, Feature Tests, Integration Tests, Validation Tests, Database Tests, and Mock Tests.

## PHPUnit test files covering it

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AuditApiTest.php`
- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/DashboardApiTest.php`

## Test cases / scenarios

| Security rule | PHPUnit-covered scenario |
| --- | --- |
| Sanctum authentication | `/api/me`, logout, audits, recommendations, and dashboard reject unauthenticated requests |
| Password protection | Registration stores a hash; user JSON omits the password |
| Token lifecycle | Registration/login create a token; logout revokes the current token |
| Rate limiting | Registration is throttled after the tested request limit |
| Audit ownership / IDOR protection | Users list and view only their own audits; cross-user detail access returns HTTP `404` |
| Recommendation ownership | Cross-user generation/retrieval returns HTTP `404`; generation sends no external request |
| Dashboard isolation | Counts and latest audit exclude other users' data |
| Server-side request safety | Unsafe audit URLs are rejected before an HTTP request is made |
| Safe error handling | Crawler and AI failures return generic messages without sensitive diagnostics |
| Secret protection | AI API key is absent from responses and API usage logs |

## Expected results

- Protected resources require authentication and enforce ownership.
- Cross-user resources are not disclosed.
- Unsafe URLs do not trigger outbound requests.
- Passwords, API keys, tokens, and sensitive provider or transport diagnostics are not exposed by tested responses or logs.
- Throttled requests return HTTP `429` where asserted.

## Status

**DONE**
