# Backend Test Cases Summary

## Purpose

The real executable tests are in:

- `tests/Feature/`
- `tests/Unit/`

Run them from the backend folder with:

```bash
php artisan test
```

The `test cases` folder only explains the existing test coverage in a simple way for review. These Markdown files are documentation and do not replace the PHPUnit tests.

## Executable test files used

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AuditApiTest.php`
- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/DashboardApiTest.php`
- `tests/Unit/SeoScoringServiceTest.php`

## Notes

- The backend API workflow was manually tested with Thunder Client.
- Full frontend E2E testing is pending frontend integration.

## Status

**DONE**
