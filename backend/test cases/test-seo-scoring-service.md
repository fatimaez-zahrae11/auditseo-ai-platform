# SEO Scoring Service Test Cases

## Backend file/functionality tested

`SeoScoringService::calculate`, including technical, content, links, performance, and global score calculation.

## Related backend files

- `app/Services/Seo/SeoScoringService.php`
- `app/Http/Controllers/Api/AuditController.php`
- `app/Services/Seo/SeoCrawlerService.php`
- `app/Models/Audit.php`

## Test types covered

Unit Tests, Feature Tests, and Integration Tests.

## PHPUnit test files covering it

- `tests/Unit/SeoScoringServiceTest.php`
- `tests/Feature/AuditApiTest.php` (API integration and persisted scores)

## Test cases / scenarios

| Scenario | Coverage |
| --- | --- |
| Slow or large pages | Unit: performance score reductions |
| Weak performance metadata | Unit: response time, size, compression, caching, non-HTML response, and clamping |
| Broken or poor-quality links | Unit: links score reduction and clamping |
| On-page content problems | Unit: content score reduction and clamping |
| Robots and sitemap problems | Unit: technical score reduction and clamping |
| Multi-page crawl problems | Unit: technical/content reductions and clamping |
| Structured-data problems | Unit: technical score reduction and clamping |
| Site-wide quality problems | Unit: content/technical reductions and clamping |
| API audit scoring | Feature: expected category scores, global rounded average, score bounds, and persistence |

## Expected results

- Healthy fixture data produces the asserted baseline scores.
- Defined SEO problems reduce the relevant category scores.
- Every returned score remains between `0` and `100`.
- The global score behavior and stored scores match the feature-test assertions.

## Status

**DONE**
