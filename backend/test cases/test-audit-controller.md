# Audit Controller Test Cases

## Backend file/functionality tested

`AuditController` operations for creating, listing, and viewing audits, including issue creation and integration with crawling and scoring.

## Related backend files

- `app/Http/Controllers/Api/AuditController.php`
- `app/Http/Requests/StoreAuditRequest.php`
- `app/Models/Audit.php`
- `app/Models/AuditIssue.php`
- `app/Models/Domain.php`
- `app/Services/Seo/SeoCrawlerService.php`
- `app/Services/Seo/SeoScoringService.php`
- `routes/api.php`

## Test types covered

Feature Tests, Integration Tests, Security Tests, Validation Tests, Database Tests, and Mock Tests.

## PHPUnit test files covering it

- `tests/Feature/AuditApiTest.php`
- `tests/Unit/SeoScoringServiceTest.php` (scoring dependency)

## Test cases / scenarios

| Scenario | Coverage |
| --- | --- |
| Access audit routes without authentication | PHPUnit |
| Create an audit as an authenticated user | PHPUnit: crawler data, scores, domain, audit, issues, and database persistence |
| Handle a crawler exception | PHPUnit mock: safe HTTP `502`, no sensitive transport detail, and no audit persisted |
| Convert detected SEO problems into issues | PHPUnit: robots, sitemap, content, links, multi-page, structured-data, technical, and performance findings |
| Reuse an existing user-owned domain | PHPUnit |
| List audits | PHPUnit: only the authenticated user's audits are returned |
| View one audit | PHPUnit: owner can view it; another user receives HTTP `404` |

## Expected results

- Unauthenticated audit requests return HTTP `401`.
- A successful creation returns HTTP `201` with the persisted audit, its domain, issues, scores, and raw crawler data.
- Crawler failures return only the safe public error asserted by PHPUnit.
- Detected findings create issues with the asserted category and severity and affect scores where asserted.
- Audit list and detail queries enforce user ownership.

## Status

**DONE**
