# SEO Scoring Service

## Backend part

`SeoScoringService`: calculate technical, content, links, performance, and global scores.

## Real executable test file

- `tests/Unit/SeoScoringServiceTest.php`
- `tests/Feature/AuditApiTest.php`

## What is tested

- Performance problems reduce the performance score.
- Link problems reduce the links score.
- Content problems reduce the content score.
- Robots, sitemap, structured data, and site-wide problems affect scores.
- Scores stay between `0` and `100`.
- Audit API tests check calculated and stored scores.

## Test type

Unit and integration tests.

## How to run

```bash
php artisan test --filter=SeoScoringServiceTest
```

## Status

**DONE**
