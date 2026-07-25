# Authentication

## Backend part

`app/Http/Controllers/Api/AuthController.php`

## Real executable test file

`tests/Feature/AuthenticationTest.php`

## What is tested

- Register and login
- Input validation and duplicate email
- Password hashing
- Sanctum token creation and logout
- `/api/me` authentication
- Login and registration rate limits

## Test type

- Feature
- Security
- Validation
- Database

## How to run

```bash
php artisan test
```

## Status

DONE
