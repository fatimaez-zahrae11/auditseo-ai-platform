# SEO Crawler Service

## Backend part

`SeoCrawlerService`: fetch and analyze a website for an audit.

## Real executable test file

`tests/Feature/AuditApiTest.php`

There is no separate crawler unit test in the current test suite.

## What is tested

- Robots.txt and sitemap checks.
- Page title, meta description, headings, images, and links.
- Internal multi-page crawling and crawl limits.
- Duplicate, thin, canonical, and orphan-page findings.
- Structured data, technical fields, redirects, and performance data.
- Unsafe URLs are rejected without an HTTP request.

## Test type

Feature, integration, security, and HTTP mock tests.

## How to run

```bash
php artisan test --filter=AuditApiTest
```

## Status

**DONE**
