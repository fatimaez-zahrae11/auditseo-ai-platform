# Backend Test Cases Summary

## Backend file/functionality tested

This folder documents the automated and manual test coverage for the Laravel backend. It is a documentation index; the executable tests remain in `tests/Feature/` and `tests/Unit/`.

PHPUnit, through Laravel's testing support, is the main automated testing framework. Run the Laravel/PHPUnit test suite with:

```bash
php artisan test
```

## Related backend files

- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/AuditController.php`
- `app/Http/Controllers/Api/AiRecommendationController.php`
- `app/Http/Controllers/Api/DashboardController.php`
- `app/Services/Seo/SeoCrawlerService.php`
- `app/Services/Seo/SeoScoringService.php`
- `app/Services/Ai/AiRecommendationService.php`
- `app/Models/ApiUsageLog.php`
- `app/Http/Requests/`
- `routes/api.php`

## Test types covered

- Unit Tests
- Feature Tests
- Integration Tests
- Security Tests
- Validation Tests
- Database Tests
- Mock Tests
- Manual Smoke Tests

## PHPUnit test files covering it

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AuditApiTest.php`
- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/DashboardApiTest.php`
- `tests/Unit/SeoScoringServiceTest.php`

## Test cases / scenarios

| Area | Coverage |
| --- | --- |
| Authentication | Registration, login, current user, logout, token handling, unauthorized access, and registration rate limiting |
| Audits | Creation, crawling, extraction, issue creation, scoring, persistence, listing, viewing, ownership, and safe failures |
| AI recommendations | Generation, external request mocking, persistence, retrieval, ownership, safe failures, and secret protection |
| Dashboard | User-specific totals, averages, latest audit, authorization, and empty state |
| SEO crawler | Robots, sitemap, on-page, links, multi-page crawling, structured data, technical metadata, performance, redirects, and unsafe URL rejection |
| SEO scoring | Technical, content, links, performance, global score behavior, score reduction, and score clamping |
| API usage logs | Successful and failed AI calls, safe stored error details, and API-key exclusion |
| Manual backend workflow | Register, `/api/me`, audit creation, AI generation, stored recommendation retrieval, and dashboard |

## Expected results

- `php artisan test` runs the executable Laravel/PHPUnit tests.
- Protected endpoints reject unauthenticated requests.
- Authenticated users can access only their own backend resources.
- Audit, recommendation, dashboard, and usage-log data are persisted and returned as asserted by the automated tests.
- External HTTP behavior is mocked in automated tests; secrets and sensitive upstream diagnostics are not returned or stored.
- Backend API workflow testing is **DONE**.
- Full frontend End-to-End testing is pending frontend integration.

## Status

**DONE**
