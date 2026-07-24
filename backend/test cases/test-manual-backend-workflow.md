# Manual Backend Workflow Test Cases

## Backend file/functionality tested

Manual Thunder Client smoke workflow across authentication, audit creation, AI recommendation generation and retrieval, and dashboard reporting.

## Related backend files

- `routes/api.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/AuditController.php`
- `app/Http/Controllers/Api/AiRecommendationController.php`
- `app/Http/Controllers/Api/DashboardController.php`
- `app/Services/Seo/SeoCrawlerService.php`
- `app/Services/Ai/AiRecommendationService.php`

## Test types covered

Manual Smoke Tests and manual backend API workflow testing.

## PHPUnit test files covering it

This end-to-end backend sequence is documented as a **Manual Smoke Test**. Its individual backend behaviors also have automated coverage in:

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AuditApiTest.php`
- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/DashboardApiTest.php`

## Test cases / scenarios

Use Thunder Client against the configured local backend. Use placeholder test credentials and keep the returned bearer token only in Thunder Client's local request environment; do not place it in this document or commit it.

| Step | Manual Smoke Test action | Expected result |
| --- | --- | --- |
| 1. Register | Send `POST /api/register` with non-secret test name, email, and a valid temporary test password | HTTP `201`; a user and bearer token are returned |
| 2. Current user | Send `GET /api/me` with the bearer token | HTTP `200`; the registered user is returned without a password |
| 3. Create audit | Send `POST /api/audits` with `{"url":"https://www.python.org"}` and the bearer token | HTTP `201`; an audit, scores, issues, and crawler data are returned; retain only the audit ID for the next requests |
| 4. Generate recommendation | Send `POST /api/audits/{audit_id}/recommendations` with the bearer token | HTTP `201`; a recommendation for the audit is generated and stored |
| 5. Retrieve recommendation | Send `GET /api/audits/{audit_id}/recommendations` with the bearer token | HTTP `200`; the stored recommendation appears in the returned list |
| 6. Dashboard | Send `GET /api/dashboard` with the bearer token | HTTP `200`; dashboard statistics reflect the created audit and recommendation |

## Expected results

- The authenticated backend workflow completes from registration through dashboard retrieval.
- The audit target is exactly `https://www.python.org`.
- The generated recommendation can be retrieved from storage.
- No real tokens, passwords, API keys, or other secrets are recorded in this documentation.
- Backend API workflow testing: **DONE**.

## Status

**DONE**
