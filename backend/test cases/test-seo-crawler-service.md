# SEO Crawler

## Backend part

`app/Services/Seo/SeoCrawlerService.php`

## Real executable test file

`tests/Feature/AuditApiTest.php`

## What is tested

- Robots.txt and sitemap checks
- Titles, descriptions, headings, images, and links
- Multi-page crawl limits and same-host crawling
- Duplicate, thin, canonical, and orphan-page checks
- Structured data, redirects, and performance data
- Direct and redirect-based SSRF blocking

## Test type

- Feature
- Integration
- Security
- Mock

## How to run

```bash
php artisan test
```

## Status

DONE
