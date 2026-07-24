# Auth Controller

## Backend part

`AuthController`: register, login, `/api/me`, and logout.

## Real executable test file

`tests/Feature/AuthenticationTest.php`

## What is tested

- Registration creates a user, hashes the password, and returns a Sanctum token.
- Valid login works and invalid credentials are rejected.
- `/api/me` and logout require authentication.
- Logout revokes the current token.
- Registration rate limiting is checked.

## Test type

Feature, security, and database tests.

## How to run

```bash
php artisan test --filter=AuthenticationTest
```

## Status

**DONE**
