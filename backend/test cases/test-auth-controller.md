# Auth Controller Test Cases

## Backend file/functionality tested

`AuthController` API operations: `register`, `login`, `me`, and `logout`.

## Related backend files

- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Requests/RegisterRequest.php`
- `app/Http/Requests/LoginRequest.php`
- `app/Models/User.php`
- `routes/api.php`

## Test types covered

Feature Tests, Integration Tests, Security Tests, Validation Tests, and Database Tests.

## PHPUnit test files covering it

- `tests/Feature/AuthenticationTest.php`

## Test cases / scenarios

| Scenario | Coverage |
| --- | --- |
| Register a user with accepted data | PHPUnit: response structure, user creation, password hashing, and Sanctum token persistence |
| Log in with valid credentials | PHPUnit: successful response and Sanctum token persistence |
| Log in with invalid credentials | PHPUnit: invalid credentials are rejected |
| Access `/api/me` | PHPUnit: unauthenticated rejection and authenticated user response without a password field |
| Log out | PHPUnit: unauthenticated rejection, successful logout, and current token revocation |
| Exceed the registration throttle | PHPUnit: the sixth request in the tested window is rate limited |

## Expected results

- Registration returns HTTP `201`, safe user data, and a token; the stored password is hashed.
- Valid login returns HTTP `200`, safe user data, and a token.
- Invalid credentials return HTTP `422` with the safe error message asserted by PHPUnit.
- `/api/me` and logout require Sanctum authentication.
- Logout removes the current personal access token.
- Rate-limited registration returns HTTP `429`.

## Status

**DONE**
