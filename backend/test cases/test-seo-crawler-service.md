# SEO Crawler Service Test Cases

## Backend file/functionality tested

`SeoCrawlerService::crawl` and its URL safety, fetching, extraction, link checking, robots, sitemap, multi-page, structured-data, technical, and performance analysis.

## Related backend files

- `app/Services/Seo/SeoCrawlerService.php`
- `app/Http/Controllers/Api/AuditController.php`
- `app/Http/Requests/StoreAuditRequest.php`
- `app/Models/Audit.php`
- `app/Models/AuditIssue.php`

## Test types covered

Feature Tests, Integration Tests, Security Tests, Validation Tests, and Mock Tests.

## PHPUnit test files covering it

- `tests/Feature/AuditApiTest.php` (service exercised through the audit API with Laravel HTTP fakes)

There is no separate `SeoCrawlerServiceTest.php` in the referenced suite.

## Test cases / scenarios

| Scenario group | PHPUnit-covered behavior |
| --- | --- |
| Fetching and safe failures | Successful fake HTTP crawl, safe controller response on crawler failure, non-HTML response, final HTTP status, redirects, and final URL |
| URL security | Unsafe URLs are rejected without sending HTTP requests |
| Robots and sitemap | Availability, directives, allow/disallow precedence, sitemap parsing and validity, audited URL presence, HTTPS URLs, broken URLs, and safety limits |
| On-page content | Title, meta description, headings, word count, title/H1 match, and image alt metrics |
| Links | Internal/external/nofollow classification, anchor quality, broken links, ignored unsafe or unsupported links, deduplication, and check limits |
| Multi-page crawling | Same-host behavior, compact summaries, URL deduplication, maximum pages, maximum depth, content problems, noindex, HTTP errors, and duplicates |
| Site-wide quality | Duplicate content, thin content, canonical conflicts, and sitemap orphan URLs |
| Structured data | JSON-LD objects, arrays and graph types, invalid JSON-LD, Microdata, RDFa, absent schema, and contextual schema recommendations |
| Technical and performance metadata | Canonical, viewport, language, meta robots, response time, page size, compression, and cache headers |

## Expected results

- Extracted values are stored in audit raw data as asserted by `AuditApiTest`.
- HTTP requests remain fake during automated coverage.
- Unsafe targets and unsupported links are not fetched.
- Crawl and check limits are respected.
- Detected problems produce the issue records and score changes asserted by the feature tests.

## Status

**DONE**
