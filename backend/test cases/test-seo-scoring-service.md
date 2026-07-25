# SEO Scoring

## Backend part

`app/Services/Seo/SeoScoringService.php`

## Real executable test file

- `tests/Unit/SeoScoringServiceTest.php`
- `tests/Feature/AuditApiTest.php`

## What is tested

- Technical score
- Content score
- Links score
- Performance score
- Global score
- Scores stay between `0` and `100`

## Test type

- Unit
- Integration

## How to run

```bash
php artisan test
```

## Status

DONE
