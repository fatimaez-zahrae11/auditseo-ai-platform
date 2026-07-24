# Manual Backend Workflow

## Backend part

Main backend API flow checked manually with Thunder Client.

## Real executable test file

This workflow is a manual smoke test, not an executable Markdown test. Its individual API parts are also covered by:

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AuditApiTest.php`
- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/DashboardApiTest.php`

## What is tested

1. Register.
2. Call `/api/me`.
3. Create an audit with `https://www.python.org`.
4. Generate an AI recommendation.
5. Retrieve the stored recommendation.
6. Open the dashboard.

No token, password, API key, or secret is stored in this document.

## Test type

Manual Smoke Test using Thunder Client.

## How to run

Run the steps above in Thunder Client against the local backend API.

## Status

**DONE**
