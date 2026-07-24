# Dashboard Controller Test Cases

## Backend file/functionality tested

`DashboardController::index` and the authenticated dashboard statistics response.

## Related backend files

- `app/Http/Controllers/Api/DashboardController.php`
- `app/Models/Audit.php`
- `app/Models/AuditIssue.php`
- `app/Models/AiRecommendation.php`
- `app/Models/Domain.php`
- `routes/api.php`

## Test types covered

Feature Tests, Integration Tests, Security Tests, and Database Tests.

## PHPUnit test files covering it

- `tests/Feature/DashboardApiTest.php`

## Test cases / scenarios

| Scenario | Coverage |
| --- | --- |
| Access the dashboard without authentication | PHPUnit |
| Retrieve statistics with multiple audits | PHPUnit: totals, rounded average, issue count, recommendation count, and latest audit |
| Separate one user's data from another user's data | PHPUnit |
| Retrieve dashboard with no audits | PHPUnit: zero totals and `null` latest audit |

## Expected results

- Unauthenticated access returns HTTP `401`.
- Authenticated access returns the counts and latest audit asserted by PHPUnit.
- Statistics include only resources owned by the authenticated user.
- A user with no audits receives zeros and `latest_audit: null`.

## Status

**DONE**
